# Source audit: public website identity

Phase 0 of the public website. This audit records what the supplied documents actually contain, so later design work can tell official brand rules apart from web-specific proposals.

Audit date: 2026-10-26. Branch: `design/public-site-identity-foundation`.

## Provenance labels

Every finding below carries one of these labels. The same labels are used in [identity-guidelines.md](identity-guidelines.md) and in the comments of `frontend/src/styles/brand-tokens.css`.

| Label | Meaning |
| --- | --- |
| **Specified** | Stated explicitly in the supplied identity guide (as text, a colour value, or a labelled artwork). |
| **Observed** | Visible in the guide's artwork or layouts but not stated as a rule. |
| **Supplied** | Provided by the brand owner as a separate file, not specified in the guide (the font files in `docs/fonts/`). |
| **Proposed** | A digital-use decision made in this phase, where the guide is silent. |
| **Unresolved** | Missing from the supplied material. Needs a decision or asset from the brand owner. |

## 1. Source documents

| Path | Type | Role in this phase |
| --- | --- | --- |
| `docs/جائزة السنكري visual identity.pdf` | PDF, 16 pages, A4 landscape (841.89 × 595.28 pt). Produced by Adobe Illustrator 30.2, dated 2026-09-28. | **Primary authority** for brand appearance. |
| `docs/خطة جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية.docx` | Award plan | Official name, slogan, implementing organisation, the six domains. No visual rules. |
| `docs/التصور التنفيذي لحفل الإطلاق الرسمي.docx` | Official launch-event plan | Event context and naming only. No visual rules. |
| `docs/fonts/itfrayat-{Light,Regular,Medium,Bold,Black}.otf` | Five OpenType (CFF) font files, supplied separately by the brand owner as the award's fonts | **Supplied** brand typeface. See §10. |

None of the source files were modified.

### How the PDF was inspected

- All 16 pages were rendered to images and visually inspected. Text extraction returns **no text on any page**: every word, including body paragraphs and colour codes, is outlined as vector paths. The PDF embeds **no fonts**.
- Page 1 is a single raster image (3508 × 2480 px). Pages 2–16 are pure vector artwork.
- Vector paths, fill colours and graphics-state opacity were read programmatically with PyMuPDF 2.0 (MuPDF 1.28.5) from a temporary tool environment outside the repository.

## 2. Page map (physical page numbers)

| Page | Content |
| --- | --- |
| 1 | Raster product mockup: the award object in a green presentation box and a green slipcase with gold foil logo. |
| 2 | Horizontal logo on light background. |
| 3 | Vertical logo on light background. |
| 4 | Horizontal logo on deep-green background. |
| 5 | Vertical logo on deep-green background. |
| 6 | Master horizontal logo above the six domain symbols with their Arabic labels. |
| 7 | فكرة الجائزة العامة: the award concept (text). |
| 8 | دليل الألوان: the colour palette (text and swatches). |
| 9 | نظام الجوائز: the award symbol system, six symbols without labels (text and artwork). |
| 10–15 | One page per domain: symbol, Arabic label, gold vertical rule, description. |
| 16 | ختاماً: closing statement (text). |

## 3. Naming

| Item | Value | Source | Label |
| --- | --- | --- | --- |
| Full official name | جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية | Both DOCX files; PDF pages 7, 16 | Specified |
| Logo lettering | جائزة الدكتور عبد القادر سنكري, plus the tagline للثقافة والتراث والتنمية المجتمعية in a gold bar | PDF pages 2–6 | Specified (artwork) |
| Slogan | "وفاءٌ للأثر... وتمكينٌ للمبادرة" | Both DOCX files | Specified (in the plans, not in the identity PDF) |
| Implementing organisation | مؤسسة الروّاد للتعاون والتنمية | Both DOCX files | Specified (in the plans) |
| Closing line | إنها جائزة تحتفي بمن يصنع أثراً... ويبقيه حاضراً في الذاكرة والمجتمع | PDF page 16 | Specified |

Note: the PDF filename says **السنكري** (with the definite article), but the logo lettering and both plans say **سنكري**. Web copy should follow the official name.

## 4. Logo arrangements and backgrounds

| Arrangement | Page | Background | Artwork colours (read from vector data) | Label |
| --- | --- | --- | --- | --- |
| Horizontal (symbol on the right, lettering on the left) | 2 | `#F4F7FC` with line pattern | Symbol and lettering `#14413D`; dot and tagline bar `#CC9933`; tagline lettering `#FFFFFF` | Specified |
| Vertical (symbol above lettering) | 3 | `#F4F7FC` with line pattern | Same as above | Specified |
| Horizontal | 4 | `#14413D`, no pattern | Symbol and lettering `#F4F7FC`; dot and tagline bar `#CC9933`; tagline lettering `#F4F7FC` | Specified |
| Vertical | 5 | `#14413D`, no pattern | Same as page 4 | Specified |

- The dark-background logo is a **distinct supplied variant**: its colours are swapped in the artwork itself.
- Proportions measured from the vector data:
  - Horizontal logo: 287.50 × 93.15 pt (aspect ratio 3.086 : 1).
  - Vertical logo: 138.13 × 186.85 pt (0.739 : 1).
- The guide states **no** clear space, minimum size, or misuse rules. **Unresolved**; see the proposals in the guidelines.
- No symbol-only (no lettering), lettering-only, single-colour, or Latin-script logo is supplied. **Unresolved.**
- Page 1 shows the logo foil-stamped in gold on a green box. **Observed**, print/merchandise context only.

## 5. Domain symbols

The guide presents one shared cup-shaped mark whose inner element changes per domain (page 9: "يعتمد النظام على رمز موحد للجائزة، ثم يتغير العنصر الداخلي بما يناسب كل محور"). **Specified.**

The labels below were verified against the PDF artwork (pages 6, 10–15) and both DOCX plans. They match the brief.

| # | Arabic label (official) | Working English gloss (not official) | Page | Inner element (described on the page) |
| --- | --- | --- | --- | --- |
| 1 | الثقافة والفنون | Culture and Arts | 10 | Open notebook with a gold pen |
| 2 | التراث المادي واللامادي | Tangible and Intangible Heritage | 11 | Architectural arch, like a heritage doorway, with a gold crown |
| 3 | العمل التطوعي | Volunteer Work | 12 | Raised hand beneath a gold dot |
| 4 | المسؤولية المجتمعية | Social Responsibility | 13 | Three gold dots |
| 5 | ريادة الأعمال والابتكار | Entrepreneurship and Innovation | 14 | Gold dot at the top, rising lines like wings |
| 6 | الجودة | Quality | 15 | Gold check mark |

Order on pages 6 and 9 (right to left): 1 → 6, as listed.

All six symbols use only `#14413D` and `#CC9933`. **Specified.** They are supplied **only for light backgrounds**. No dark-background domain symbols exist. **Unresolved.**

**Policy note.** The award plan describes quality as "الجودة: كمنهج أساسي متقاطع في كافة المحاور" (a cross-cutting method across all domains). The launch plan lists it as one of six domains. Whether quality is an independent nomination category is **not decided** by this foundation; showing its symbol does not settle the question.

## 6. Colour

From page 8 (دليل الألوان). The page text describes "مزيج رسمي من الأخضر العميق والذهبي المطفي، مع خلفيات عاجية وبيضاء هادئة".

| Role (from the guide) | Value | Guide's description | Label |
| --- | --- | --- | --- |
| Deep green: primary identity colour | `#14413D` | اللون الأساسي للهوية يمنح شعوراً بالثبات والوقار والاستدامة | Specified |
| Matte gold: honour and value | `#CC9933` | لون التكريم والقيمة يضيف إحساساً بالفخامة | Specified |
| Light background | `#F4F7FC` | لون نظيف ومريح للعين | Specified |

The page 8 artwork prints these codes in lowercase (`#14413d`, `#cc9933`, `#f4f7fc`). Reading the actual vector fill values gives the same three colours exactly (`0.078 0.255 0.239`, `0.8 0.6 0.2`, `0.957 0.969 0.988` in RGB).

Other colours present in the artwork:

- `#FFFFFF`: tagline lettering on the light-background logos. **Observed**, not on the palette page.
- `#CCCCCC` at 15% opacity: the background line pattern and the page-4 bottom band. **Observed.**

The guide gives no tints, shades, neutrals, status colours, or contrast guidance. **Unresolved.** All web extensions are **Proposed**.

## 7. Background line pattern

- A single vector path filled with `#CCCCCC`, drawn at **15% opacity** (ExtGState `/ca 0.149994`). On `#F4F7FC` the effective colour is about `#EEF1F5`. **Observed** (read from the PDF).
- Form: fine, parallel, diagonal, stepped (zig-zag) lines running across the full page. The brief calls this a "topographic contour" pattern. It reads as stepped linework rather than true topographic contours.
- Present on every light page (2, 3, 6–16). The pattern data is byte-identical on all of them. **Absent** on the dark pages 4–5.
- It is a page-sized composition, **not a seamless tile**.
- The guide states no rule for its use. Treating it as quiet supporting decoration is **Proposed**.

## 8. Page furniture observed in the guide

- **Bottom band**, about 11 pt high (≈1.9% of page height):
  - Light pages: deep green.
  - Page 4: `#CCCCCC` at 15% opacity.
  - Page 5: gold.

  **Observed.**
- **Gold vertical rule** beside the domain descriptions (pages 10–15): 5.33 × 108.44 pt. **Observed.**
- **Typographic treatment.** **Observed** only; these are print-layout choices, not stated rules.
  - Section titles are gold and centred.
  - Body text is deep green, with bold deep-green emphasis.
  - Paragraphs are justified, with stretched kashida (tatweel) in titles and labels.

## 9. Concept (page 7)

The visual idea combines **the cup, the flower, and the rising person** (الكأس والزهرة والإنسان الصاعد). The guide describes it (translated here) as an open mark of honour that grows from a stable base and rises towards a golden point representing impact, achievement and recognition. **Specified.**

## 10. Typography

### 10.1 What the guide says

- **The guide does not name a typeface.** All lettering is outlined, and **no fonts are embedded** in the PDF.
- The DOCX files set no explicit run fonts, and they are not typographic references for the brand.
- The PDF's outlined lettering was **not** compared with the supplied fonts below. Nothing here claims that the guide was set in them.

### 10.2 Supplied font files (Supplied)

After the guide, the brand owner supplied five OTF files in `docs/fonts/` as the award's fonts. They were inspected programmatically with fontTools 4.67 (in a temporary environment outside the repository). Every statement below comes from the files' internal tables, not their filenames.

| File | Bytes | Family (nameID 16, else 1) | Style (nameID 17, else 2) | Full name | PostScript name | `usWeightClass` | CFF `Weight` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `itfrayat-Light.otf` | 61,312 | itf rayat | Light | itf rayat Light | `itfrayat-Light` | 300 | Light |
| `itfrayat-Regular.otf` | 63,044 | itf rayat | Regular | itf rayat Regular | `itfrayat-Regular` | 400 | Regular |
| `itfrayat-Medium.otf` | 63,832 | itf rayat | Medium | itf rayat Medium | `itfrayat-Medium` | 500 | Medium |
| `itfrayat-Bold.otf` | 64,884 | itf rayat | Bold | itf rayat Bold | `itfrayat-Bold` | 700 | Bold |
| `itfrayat-Black.otf` | 63,392 | itf rayat | Black | itf rayat Black | `itfrayat-Black` | 900 | Black |

**Evidence that they form one family.** All five share:

- the typographic family name "itf rayat". Light, Medium and Black use the usual legacy grouping (nameID 1 "itf rayat Light" / "itf rayat Med" / "itf rayat Black", subfamily "Regular") with nameID 16 = "itf rayat"; Regular and Bold carry "itf rayat" in nameID 1;
- the manufacturer and designer "iwantype" and the vendor URL `iwantype.com`;
- version 1.001 and the same unique-ID pattern (`Version 1.001;;itfrayat-<Style>;2024;FL842`);
- 800 units per em, 592 glyphs and an identical 301-character map.

**Style metadata.**

- All five are **static** fonts: no `fvar`, `gvar` or `CFF2` table.
- All five are **upright**: italic angle 0, no italic bit set. There is no italic.
- There is **no 600 (SemiBold)** weight. Bold sets the bold bit (`fsSelection`, `macStyle`); the others set "Regular".
- `fsType` is 0 (installable embedding).

**Character coverage** (identical in all five):

| Range | Coverage |
| --- | --- |
| Arabic letters U+0621–U+064A | 37 of 42. All 28 letters plus hamza forms, ta marbuta and alef maqsura. Missing only the rare U+063B–U+063F. |
| Arabic marks U+064B–U+0652, U+0653–U+0655, superscript alef U+0670 | Complete |
| Arabic-Indic digits U+0660–U+0669, ٪ ٫ ، ؛ ؟, tatweel | Present. U+066C (Arabic thousands separator) is missing. |
| Extended Arabic-Indic (Persian) digits, ژ گ ک ی | Missing. پ چ ڤ are present. |
| Basic Latin A–Z a–z 0–9 | Complete. ASCII punctuation lacks `^`, `` ` `` and `~`. |
| Latin-1 accented letters (é, ü, ç, …) | **None** |
| « » – — ‘ ’ “ ” ‹ › € × ÷ | Present |
| … • © ® ™ · → § | **Missing** |
| ZWJ, ZWNJ, LRM, RLM | No glyphs (invisible format characters; shaping engines handle them without one) |

**Shaping tables.**

- GSUB, GPOS and GDEF are present, with `DFLT`, `arab` (default plus `URD`) and `latn` scripts.
- **GSUB features:** `ccmp` (Arabic), `init`, `medi`, `fina`, `rlig`, `liga`, `dlig`, `salt`, `aalt`, and stylistic sets `ss01`–`ss04`. The sets are named "morsala", "yeh" and "dots" for ss01, ss02 and ss04; ss03 has no name.
- The font includes a dedicated `الله` ligature.
- **GPOS features:** `kern`, `mark`, `mkmk`. There is no legacy `kern` table.
- The optional sets (`dlig`, `salt`, `ss01`–`ss04`) are off by default in browsers. They were not enabled or evaluated.

**Vertical metrics.**

- `hhea` ascent/descent ranges from 720/−420 (Light) to 740/−440 (Black). Windows ascent/descent is 720/450. `USE_TYPO_METRICS` is off. The default line box is therefore 1.43–1.48 em.
- A few glyphs rise above the Windows ascent. The tallest is the medial lam of the `الله` ligature: 922 units (1.15 em) in Regular and 961 units (1.20 em) in Bold.

**Attribution and licence.**

- There is **no** copyright (nameID 0), licence description (13) or licence URL (14) record in any file.
- The only attribution is iwantype as manufacturer and designer.
- **Unresolved:** whether the award's licence permits self-hosting these fonts on a public website. See §11.

**Provenance.**

- **Supplied:** the user supplied the files as the award's fonts.
- **Not specified by the identity guide:** the PDF does not name them.
- The role each weight plays on the web is a **Proposed** decision, documented in [identity-guidelines.md](identity-guidelines.md) §5.

## 11. Assets

| Asset | Status |
| --- | --- |
| Standalone logo files (SVG/AI/EPS/PNG) | **Not supplied.** Extracted faithfully from the PDF's vector paths; see [assets-manifest.md](assets-manifest.md). |
| Domain symbols | **Not supplied** separately. Extracted from pages 10–15. |
| Background line pattern | Extracted from page 2. |
| Font files | **Supplied** separately: five static OTF weights of "itf rayat" in `docs/fonts/` (§10.2). Converted losslessly to WOFF2 for the web; see [assets-manifest.md](assets-manifest.md). Web licence **Unresolved**: the files embed no licence text. |
| Dark-background domain symbols, monochrome logos, symbol-only mark, favicon/app icon | **Not supplied.** Unresolved. Do not improvise them. |
| Photography | **None supplied.** Page 1 is a product mockup render, not documentary photography. |

## 12. Extraction limitations

- Extracted SVG coordinates are rounded to 0.001 pt (≈0.00035 mm). Browser renders match PDF rasters with **no solid-region mismatches**; the only differences are antialiased edges (method in the manifest).
- The PDF was exported from Illustrator, so these SVGs are faithful *derivatives*, not the designer's master files. Original `.ai`/`.svg` masters should replace them if the designer supplies them.
- The label lettering on pages 6 and 10–15 was **not** extracted. The web will use live text for labels; see the guidelines.
- No text could be machine-extracted from the PDF. The Arabic quotations in this audit were transcribed from the rendered pages and cross-checked against the DOCX plans where the wording overlaps.
