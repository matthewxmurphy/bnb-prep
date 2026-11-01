# BNB Prep

Print-prep for layered designs, in WordPress. Upload a PSD, get back a ZIP
with **one trimmed PNG per layer** — pure layer names, groups flattened,
hidden layers included — plus an optional upscale (the classic
"enlarge 20% for print" step).

Built for print and wrap shops. It does the layer-splitting job that
standalone prep utilities charge monthly for, using the shop's own
PhotoCraft engine.

## What it does

1. **Upload** a `.psd` on the BNB Prep admin screen and pick an upscale
   percent (100 = original size).
2. The prep runs through a headless PhotoCraft session:
   - reads the layer tree (names, groups, visibility),
   - applies the upscale to the document,
   - exports one full-canvas PNG per **top-level** layer — a group
     exports as one flattened PNG named for the group,
   - hidden top-level layers get a second, visibility-flipped export
     pass so they are included too (hidden layers *inside* a group stay
     out of that group's render, exactly as they would print),
   - trims every PNG with ImageMagick. Layers that would trim away to
     nothing (solid or fully transparent) keep their full canvas and
     are flagged instead of destroyed.
3. **Download** the ZIP: `<Layer Name>.png` files plus `manifest.json`
   (machine-readable layer list with canvas/trimmed sizes, hidden and
   group flags) and a human `README.txt` summary.

## Requirements

- WordPress with the plugin installed (a FluentCart shop works fine;
  the plugin has no store dependency).
- On the machine that processes jobs:
  - **PhotoCraft** (`photocraft-cli` 0.18.0, headless `serve` mode),
  - **ImageMagick** (`magick` or IM6 `convert`/`identify`),
  - PHP with `proc_open`, `shell_exec` and ZipArchive available.
- Jobs too large for a web request (configurable, default 60 MB) are
  queued instead of run inline.

## The worker contract

The WordPress side and the processing side are deliberately separate:

- If the web server itself can run PhotoCraft, set the CLI path (and an
  optional run-as prefix such as `sudo -u mmurphy`, for installs owned
  by another user) in **BNB Prep → Settings** and jobs run in place.
  When the engine runs as a *different* user than the web server, the
  uploads path must be traversable by the engine user (it reads the job
  directory directly); on a normal single-user host this is automatic.
- Otherwise, jobs queue on disk under `wp-content/uploads/bnb-prep/<job>/`
  with `input.psd`, `job.json` and a `settings.json` snapshot. Run them
  from cron or SSH on any machine that has the engine:

  ```sh
  php tools/bnb-prep-worker-cli.php --job-dir=/path/to/wp-content/uploads/bnb-prep/<job>
  ```

  The worker writes `result.zip`, `manifest.json` and `status.json`
  back into the job directory, and WordPress offers the download.

## Licensing

Soft licensing, per fleet law: an installed copy **never** stops
working over a license. The plugin registers the site with
`my.net30hosting.com` on activation (failure is ignored — offline
installs work in full), and license state gates **upgrades only**:
whether the update feed may deliver newer versions. See
`includes/class-bnb-prep-license.php`.

## Testing

`tests/fixtures/test.psd` is a 400×300 PSD with named layers, a group
("Back Design": Mini Badge + Star Element), and a hidden top-level
layer ("Draft Notes"). `tests/make-test-psd.py` rebuilds it with
PhotoCraft itself; `tests/EXPECTED.md` lists the exact ZIP contents a
correct run produces.

## Version

2026.10.31 (CalVer). First testable slice:

- **Not yet in v1:** TIFF/CMYK output, per-layer (inside-group) export,
  WP-Cron background running, batch multi-PSD upload, a FluentCart
  product/download integration. The admin screen is the whole UI.
