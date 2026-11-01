#!/usr/bin/env python3
"""Rebuild tests/fixtures/test.psd using PhotoCraft itself.

Usage:
    python3 make-test-psd.py [workdir] [photocraft-cli]

Creates five small artwork PNGs with ImageMagick, then drives a
headless `photocraft-cli serve` session (stdio, JSON lines) to place
them as named layers in a 400x300 document: one group with two
children, one hidden top-level layer. Saves <workdir>/test.psd.

Verified against photocraft-cli 0.18.0. Protocol facts this relies on:
doc.place/doc.save take {"path": ...}; doc.exportLayers takes
{"dir": ...}; layer ids come from doc.inspect / place results.
"""
import json
import os
import subprocess
import sys

WORK = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else "fixture-work")
PC = sys.argv[2] if len(sys.argv) > 2 else "photocraft-cli"

ART = {
    "art-bg.png":    ["-fill", "#cc3333", "-draw", "rectangle 40,40 360,260"],
    "art-logo.png":  ["-fill", "#3366cc", "-draw", "circle 200,150 200,95"],
    "art-star.png":  ["-fill", "#e6b800", "-draw",
                      "polygon 300,60 318,110 372,110 328,142 344,194 300,160 "
                      "256,194 272,142 228,110 282,110"],
    "art-badge.png": ["-fill", "#cc3399", "-draw", "circle 60,60 60,42"],
    "art-draft.png": ["-stroke", "#339966", "-strokewidth", "10", "-draw",
                      "line 30,270 370,30"],
}
# (layer name, art file, group or None, hidden)
PLAN = [
    ("Background Art", "art-bg.png", None, False),
    ("Front Logo", "art-logo.png", None, False),
    ("Star Element", "art-star.png", "Back Design", False),
    ("Mini Badge", "art-badge.png", "Back Design", False),
    ("Draft Notes", "art-draft.png", None, True),
]


def make_art():
    os.makedirs(WORK, exist_ok=True)
    for filename, args in ART.items():
        subprocess.run(
            ["convert", "-size", "400x300", "xc:none", *args,
             os.path.join(WORK, filename)],
            check=True)


class Serve:
    def __init__(self):
        self.proc = subprocess.Popen(
            [PC, "serve",
             "--automation-read-root", WORK,
             "--automation-write-root", WORK],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True,
            bufsize=1)
        self.seq = 0

    def rpc(self, method, params=None):
        self.seq += 1
        self.proc.stdin.write(json.dumps(
            {"id": self.seq, "method": method, "params": params or {}}) + "\n")
        self.proc.stdin.flush()
        reply = json.loads(self.proc.stdout.readline())
        if "error" in reply:
            raise RuntimeError(f"{method} failed: {reply['error']}")
        return reply.get("result")

    def engine(self, command, params=None):
        return self.rpc("engine.execute",
                        {"command": command, "params": params or {}})

    def close(self):
        self.proc.stdin.close()
        self.proc.terminate()


def main():
    make_art()
    serve = Serve()
    try:
        serve.rpc("doc.new", {"width": 400, "height": 300})
        ids = {}
        for name, filename, _group, _hidden in PLAN:
            placed = serve.rpc("doc.place",
                               {"path": os.path.join(WORK, filename)})
            ids[name] = placed["layer"]
            serve.engine("layer.setProps",
                         {"layer": ids[name], "name": name})
            if name == "Background Art":
                # The document's default Background layer is deletable
                # once another layer exists; the fixture does not want it.
                tree = serve.rpc("doc.inspect", {})

                def drop(o):
                    if isinstance(o, dict):
                        if o.get("name") == "Background" and "id" in o:
                            serve.engine("layer.delete", {"layer": o["id"]})
                        for v in o.values():
                            drop(v)
                    elif isinstance(o, list):
                        for v in o:
                            drop(v)
                drop(tree.get("layers", []))
        members = [n for n, _f, g, _h in PLAN if g]
        serve.engine("layer.select",
                     {"layer": ids[members[0]], "mode": "replace"})
        for member in members[1:]:
            serve.engine("layer.select",
                         {"layer": ids[member], "mode": "add"})
        serve.engine("layer.groupLayers", {"name": "Back Design"})
        for name, _f, _g, hidden in PLAN:
            if hidden:
                serve.engine("layer.setProps",
                             {"layer": ids[name], "visible": False})
        saved = serve.rpc("doc.save",
                          {"path": os.path.join(WORK, "test.psd")})
        print("saved:", saved)
    finally:
        serve.close()


if __name__ == "__main__":
    main()
