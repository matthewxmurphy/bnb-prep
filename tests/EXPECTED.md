# Expected result for tests/fixtures/test.psd

Fixture: 400×300 RGB PSD built by `tests/make-test-psd.py` with
PhotoCraft itself. Layer stack (top to bottom):

| Layer | Kind | Visible | Artwork bounds |
|---|---|---|---|
| Draft Notes | Smart Object | **no** (hidden, top level) | 347×249 |
| Back Design | Group | yes | — |
| └ Mini Badge | Smart Object | yes | 37×37 |
| └ Star Element | Smart Object | yes | 145×135 |
| Front Logo | Smart Object | yes | 111×111 |
| Background Art | Smart Object | yes | 321×221 |

A correct run at 100% upscale produces `result.zip` with exactly:

| ZIP entry | Trimmed size | Notes |
|---|---|---|
| `Draft Notes.png` | 347×249 | Second (visibility-flipped) pass; `was_hidden: true` in the manifest |
| `Back Design.png` | 331×153 | Group flattened: union of badge (42..79) and star (228..373 × 60..195) artwork |
| `Front Logo.png` | 111×111 | |
| `Background Art.png` | 321×221 | |
| `manifest.json` | — | Lists all four layers with canvas 400×300 and the sizes above |
| `README.txt` | — | Human-readable copy of the manifest |

At 120% upscale the canvas becomes 480×360 and every trimmed size
scales by ≈1.2 (verified run: Back Design 399×185, Front Logo 135×135,
Background Art 389×269, Draft Notes 418×300 — rasterization grows
anti-aliased edges slightly beyond the exact 1.2× of the 100% bounds).
