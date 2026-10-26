# Brand assets manifest

All brand assets in `frontend/src/assets/brand/` are **faithful vector extractions** from the supplied identity guide, `docs/جائزة السنكري visual identity.pdf`.

- Page numbers are physical PDF pages.
- Nothing was traced, redrawn, generated or substituted.
- No standalone logo or symbol files were supplied with the guide (see [source-audit.md](source-audit.md) §11).

The assets are **not imported** by the running app in this phase.

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

## Missing originals (not fabricated)

The following were not supplied and have **not** been created:

- master `.ai`/`.svg` logo files
- symbol-only mark
- favicon and app icon
- social-sharing image
- monochrome logos
- dark-background domain symbols
- brand font files

See [identity-guidelines.md](identity-guidelines.md) §9.
