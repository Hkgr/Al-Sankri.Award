# Brand assets manifest

The brand artwork in `frontend/src/assets/brand/` consists of **faithful vector extractions** from the supplied identity guide, `docs/جائزة السنكري visual identity.pdf`. The web fonts in `frontend/src/assets/brand/fonts/` are **lossless conversions** of the owner-supplied OTF files in `docs/fonts/` ([Web fonts](#web-fonts)).

- Page numbers are physical PDF pages.
- Nothing was traced, redrawn, generated or substituted.
- No standalone logo or symbol files were supplied with the guide (see [source-audit.md](source-audit.md) §11).

The assets and fonts are **not imported** by the running app in this phase.

## Inventory

Dimensions are in PDF points; the SVG `viewBox` uses the same units.

| File | Source page | Size (pt) | Aspect ratio | Paths | Colours | Intended use |
| --- | --- | --- | --- | --- | --- | --- |
| `logo-horizontal-light-bg.svg` | 2 | 287.50 × 93.15 | 3.086 | 73 | `#14413D` `#CC9933` `#FFFFFF` | Header and footer on light surfaces |
| `logo-vertical-light-bg.svg` | 3 | 138.13 × 186.85 | 0.739 | 73 | `#14413D` `#CC9933` `#FFFFFF` | Centred compositions on light surfaces |
| `logo-horizontal-dark-bg.svg` | 4 | 287.50 × 93.15 | 3.086 | 73 | `#F4F7FC` `#CC9933` | Deep-green (`#14413D`) bands and footer |
| `logo-vertical-dark-bg.svg` | 5 | 138.13 × 186.85 | 0.739 | 73 | `#F4F7FC` `#CC9933` | Centred compositions on deep green |
| `domain-culture-and-arts.svg` | 10 | 81.14 × 91.86 | 0.883 | 5 | `#14413D` `#CC9933` | الثقافة والفنون |
| `domain-tangible-and-intangible-heritage.svg` | 11 | 81.36 × 92.11 | 0.883 | 8 | `#14413D` `#CC9933` | التراث المادي واللامادي |
| `domain-volunteer-work.svg` | 12 | 81.61 × 92.40 | 0.883 | 4 | `#14413D` `#CC9933` | العمل التطوعي |
| `domain-social-responsibility.svg` | 13 | 81.25 × 91.98 | 0.883 | 6 | `#14413D` `#CC9933` | المسؤولية المجتمعية |
| `domain-entrepreneurship-and-innovation.svg` | 14 | 81.15 × 96.53 | 0.841 | 5 | `#14413D` `#CC9933` | ريادة الأعمال والابتكار |
| `domain-quality.svg` | 15 | 81.59 × 92.37 | 0.883 | 4 | `#14413D` `#CC9933` | الجودة |
| `background-line-pattern.svg` | 2 (identical on 3, 6–16) | 841.89 × 595.28 (full page) | 1.414 | 1 | `#CCCCCC` at 0.149994 opacity | Quiet decoration on light surfaces |

The entrepreneurship symbol's ratio differs because its gold dot rises above the cup's wings. The cup geometry is the same as in the other five symbols.

Each SVG carries:

- a `<title>`: the official Arabic name for logos, the Arabic domain label for symbols;
- a comment naming its source page.

The background pattern carries `aria-hidden="true"` instead of a title.

## Extraction method

1. **Inspection.** The PDF (Illustrator 30.2 export) contains outlined vector artwork on pages 2–16, no embedded fonts, and a single raster image on page 1. Every fill colour and every graphics-state opacity was read from the PDF objects.
2. **Selection.** PyMuPDF 2.0 (MuPDF 1.28.5) `Page.get_drawings()` returned every filled path with its exact colour, fill rule and paint order.
   - **Excluded** as page furniture:
     - the full-page background rectangle;
     - the bottom page band;
     - the `#CCCCCC` pattern form (except for the pattern asset itself).
   - **Logos:** every path inside the logo's region on pages 2–5.
   - **Domain symbols:** the topmost cluster of paths in the right-hand column of pages 10–15, which ends at the cup's base bar. A vertical gap of more than 6pt separates the symbol from the label lettering below it. Paths were re-sorted into their original paint order.
3. **Conversion.** Each PDF path operator was written as the equivalent SVG command:
   - `m`/`l` → `M`/`L`
   - `c` → `C` (cubic Bézier, unchanged control points)
   - `re` → rectangle

   Geometry was only translated so that the artwork's bounding box starts at (0, 0), and rounded to 0.001pt. There is no simplification, smoothing or re-fitting. Fill colours and fill rules are copied from the source.
4. **Pattern opacity.** The pattern's 15% opacity is set by the page's ExtGState `/GS1` (`/ca 0.149994`) before the pattern form is drawn. It is written as `fill-opacity="0.1500"` (0.149994 rounded to four decimals).

## Fidelity verification

Each logo and symbol SVG was checked as follows:

1. Rendered in Chromium 151 (Playwright 1.62) at 4× scale on its intended background.
2. Rasterised from the PDF region at the same scale by MuPDF.
3. Diffed pixel by pixel.

| Check | Result |
| --- | --- |
| Pixels differing by ≥ 64/255 whose 3 × 3 neighbourhood also differs (shape mismatch inside solid areas) | **0 for every asset** |
| Pixels differing by ≥ 64/255 at all (antialiased edges only) | 0.14% – 1.01% per asset |
| Visual inspection of the difference images | Only outlines (antialiasing) and the source's own faint background pattern under the PDF crop |

## Limitations

- These are **derivatives of a PDF export**, not the designer's master files. The geometry is faithful, but layer names, artboards and any construction guides are lost. Replace them with original masters if the designer supplies them, and keep the same filenames.
- Coordinates are rounded to 0.001pt (≈ 0.00035 mm). The difference is not visible at any practical size.
- SVGs include fixed `width`/`height` in points. Size them with CSS: set one dimension and leave the other `auto`.
- **Label lettering** on pages 6 and 10–15 and **text paragraphs** were not extracted. Web labels use live text.
- **Not extracted:** the page 1 raster mockup. It is a product visualisation, not a logo source, and its use on the web is unresolved.
- The background pattern is a full-page composition, not a seamless tile.

## Web fonts

The brand owner supplied five OTF files as the award's fonts. The identity PDF does not name them. Their internal metadata is recorded in [source-audit.md](source-audit.md) §10.2.

| Original (unchanged) | Family / style (internal) | Weight | Coverage | Web asset | Size |
| --- | --- | --- | --- | --- | --- |
| `docs/fonts/itfrayat-Light.otf` (61,312 B) | itf rayat / Light | 300 | Arabic + Basic Latin | `fonts/itf-rayat-light.woff2` | 30,072 B |
| `docs/fonts/itfrayat-Regular.otf` (63,044 B) | itf rayat / Regular | 400 | Arabic + Basic Latin | `fonts/itf-rayat-regular.woff2` | 31,456 B |
| `docs/fonts/itfrayat-Medium.otf` (63,832 B) | itf rayat / Medium | 500 | Arabic + Basic Latin | `fonts/itf-rayat-medium.woff2` | 31,844 B |
| `docs/fonts/itfrayat-Bold.otf` (64,884 B) | itf rayat / Bold | 700 | Arabic + Basic Latin | `fonts/itf-rayat-bold.woff2` | 32,240 B |
| `docs/fonts/itfrayat-Black.otf` (63,392 B) | itf rayat / Black | 900 | Arabic + Basic Latin | `fonts/itf-rayat-black.woff2` | 31,524 B |

`fonts/fonts.css` declares the five faces:

- alias `"SA ITF Rayat"`;
- relative `url()` sources only, with no `local()` and no remote service;
- `font-weight` taken from each file's `usWeightClass`;
- `font-style: normal`;
- `font-display: swap`.

The files are static, so no weight ranges are declared.

### Conversion method

1. **Tool.** fontTools 4.67.0 `woff2.compress` with Brotli 1.2.1, run from a temporary environment outside the repository. No project dependency was added.
2. **No subsetting.** Every glyph, the full character map and all OpenType layout tables were kept.
3. **Verification.** Each WOFF2 was decompressed again and compared with its OTF table by table.
   - **Byte-identical:** `CFF `, `GSUB`, `GPOS`, `GDEF`, `cmap`, `name`, `OS/2`, `hhea`, `hmtx`, `maxp` and `post`.
   - **`head`:** differs only by `flags` bit 11 ("font data losslessly converted"), which the WOFF2 specification requires, plus the recomputed checksum adjustment.
   - **`DSIG`:** the digital signature table is omitted. A signature cannot remain valid after conversion, and browsers do not use it.
4. **Result.** About 50% of the OTF size (30–32 KB per weight, about 157 KB for all five). A page downloads only the weights it actually uses.

### Font limitations

- **Not subset.** Subsetting for production (for example, dropping unused presentation-form code points) is a later optimisation, to be checked against shaping.
- **Missing characters fall back.** These include `…` `•` `©` `®` `™` `·` `→` `§`, accented Latin, `~` `^` `` ` ``, ٬ and Persian-only letters and digits. They render in the next font of the stack; see [identity-guidelines.md](identity-guidelines.md) §5.2.
- **No licence information is embedded** (no copyright, licence or licence-URL name records). Whether the licence permits self-hosting on a public website is **unresolved**.

## Missing originals (not fabricated)

The following were not supplied and have **not** been created:

- master `.ai`/`.svg` logo files
- symbol-only mark
- favicon and app icon
- social-sharing image
- monochrome logos
- dark-background domain symbols

The font licence confirmation is also outstanding. See [identity-guidelines.md](identity-guidelines.md) §9.
