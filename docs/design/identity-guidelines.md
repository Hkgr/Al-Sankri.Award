# Digital identity guidelines

**جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية**

These guidelines set out how the award's supplied identity should appear on the public website. They are the Phase 0 foundation. No pages are built yet.

- **Source of truth:** `docs/جائزة السنكري visual identity.pdf`. Page numbers below are physical PDF pages.
- **Brand font:** the owner-supplied OTF files in `docs/fonts/` (§5.1).
- **Evidence:** [source-audit.md](source-audit.md) records what the guide contains.
- **Assets:** [assets-manifest.md](assets-manifest.md) lists the extracted brand artwork.
- **Tokens:** `frontend/src/styles/brand-tokens.css` implements these rules (not yet imported by the app).
- **Visual reference:** [preview.html](preview.html).

Each rule carries the label of its source:

- **Specified**: stated by the identity guide.
- **Observed**: visible in the guide's artwork or layouts, but not stated as a rule.
- **Supplied**: provided by the brand owner as a separate file, not specified in the guide (the font files).
- **Proposed**: a web-specific decision made here.
- **Unresolved**: needs the brand owner.

Proposed rules may be changed by the brand owner. Specified rules may not be changed by the web team.

---

## 1. Brand character

The site should read as a **contemporary Arabic cultural and community award with institutional clarity**.

| Quality | Where it comes from | Label |
| --- | --- | --- |
| Dignified, steady, lasting | Deep green "gives a sense of stability, dignity and sustainability" (page 8) | Specified |
| Honour used sparingly | Gold is "the colour of honour and value" (page 8). In the guide it is used on small, precise elements: the dot, the tagline bar, the inner domain elements, titles | Specified (meaning), Observed (sparing use) |
| Calm, uncluttered, heritage-aware | The palette gives "a heritage character that is not crowded" (طابعاً تراثياً غير مزدحم, page 8) | Specified |
| Growth from a stable base | The mark rises from a fixed base towards a golden point (page 7) | Specified |
| Generous space, restrained detail | Every guide page centres a single idea on a quiet ground with wide margins | Observed |

**Proposed web translation:**

- Quiet light surfaces with generous reading space.
- Deep green for structure and text; gold as a precise accent, never as a flood.
- Square, architectural shapes.
- One clear idea per section.
- No ornament for its own sake.

---

## 2. Logo

### 2.1 Approved variants

| File | Use on | Source | Label |
| --- | --- | --- | --- |
| `logo-horizontal-light-bg.svg` | `#F4F7FC`, white, or other light surfaces | Page 2 | Specified |
| `logo-vertical-light-bg.svg` | Light surfaces | Page 3 | Specified |
| `logo-horizontal-dark-bg.svg` | `#14413D` (deep green) | Page 4 | Specified |
| `logo-vertical-dark-bg.svg` | `#14413D` | Page 5 | Specified |

- **Dark backgrounds need the supplied dark variant.** Never derive it from the light logo with CSS `filter`, `mix-blend-mode`, `currentColor` substitution or SVG recolouring. The variants differ in more than one colour: on light, the tagline lettering is `#FFFFFF`; on dark, it is `#F4F7FC`.
- **Choosing an arrangement** (Proposed):
  - Use the horizontal logo in wide, shallow spaces such as a header or footer row.
  - Use the vertical logo in centred, tall compositions such as an about-section opener or print-like hero.
- The guide shows the dark variants only on `#14413D`. Placing them on photographs or other dark colours is **Unresolved**. Until approved, place the logo on a solid brand surface.

### 2.2 Aspect ratio and positioning

- **Specified (by the artwork):**
  - Horizontal: 3.086 : 1.
  - Vertical: 0.739 : 1.
- **Proposed:**
  - Always scale proportionally. Set one dimension, leave the other `auto`.
  - In RTL layouts, the horizontal logo sits at the inline-start edge (the right).
  - The artwork itself is **never mirrored**.

### 2.3 Clear space (Proposed: the guide is silent)

Use the **height of the logo's gold tagline bar** as the unit, because it scales with the logo:

| Arrangement | Clear space on every side | At minimum size |
| --- | --- | --- |
| Horizontal | ≥ 1 × tagline-bar height (≈ 20.7% of logo height) | ≈ 13px, round up to **16px** |
| Vertical | ≥ 1 × tagline-bar height (≈ 7.7% of logo height, ≈ 10.4% of width) | ≈ 13px, round up to **16px** |

Keep text, other logos, controls and image edges out of this zone.

### 2.4 Minimum size (Proposed: the guide is silent)

The smallest detail is the white tagline lettering. Both minimums keep that lettering block about 10 CSS px tall:

| Arrangement | Minimum width | Preferred width | Token |
| --- | --- | --- | --- |
| Horizontal | **200px** | ≥ 240px | `--sa-logo-horizontal-min-width` |
| Vertical | **128px** | ≥ 160px | `--sa-logo-vertical-min-width` |

Below these sizes, a symbol-only or simplified mark would be needed. None is supplied (**Unresolved**). Do **not** crop the symbol out of the lockup to make one; ask the designer.

### 2.5 Background contrast

| Variant | Approved backgrounds | Avoid |
| --- | --- | --- |
| Light-bg | `#F4F7FC`, `#FFFFFF`, `--sa-color-surface-subtle` (`#E8EDF5`), the guide's line pattern | Gold, photos, mid-tones |
| Dark-bg | `#14413D` | Gold, `#0F3330` (unapproved, though visually close), photos |

The logo's white-on-gold tagline lettering has a ratio of 2.57:1. Logotypes are exempt from WCAG text-contrast requirements, and this artwork is not altered. The minimum sizes above exist partly to protect its legibility.

### 2.6 Forbidden treatments

Do not:

- Stretch, squash, skew or change the proportions of the logo.
- Rotate it, or mirror it for RTL.
- Redraw or retype the lettering. Do not set the name in a web font beside the symbol to imitate the lockup.
- Recolour it, apply gradients, shadows, glows or outlines, or simulate a variant with CSS filters.
- Animate its shape: no morphing, scroll-driven deformation, assembling or "drawing on" effects.
- Rearrange the symbol and lettering into new lockups.
- Place it on busy imagery or on low-contrast surfaces.
- Replace the symbol, or any domain symbol, with a generic icon, emoji or stock trophy.
- Reproduce the trophy, emblem, icons or layouts from the rejected AI-generated website concept.

---

## 3. Domain symbols

### 3.1 The six symbols

| Arabic label (always shown in Arabic) | File | Page |
| --- | --- | --- |
| الثقافة والفنون | `domain-culture-and-arts.svg` | 10 |
| التراث المادي واللامادي | `domain-tangible-and-intangible-heritage.svg` | 11 |
| العمل التطوعي | `domain-volunteer-work.svg` | 12 |
| المسؤولية المجتمعية | `domain-social-responsibility.svg` | 13 |
| ريادة الأعمال والابتكار | `domain-entrepreneurship-and-innovation.svg` | 14 |
| الجودة | `domain-quality.svg` | 15 |

**Specified:** each symbol belongs to exactly one domain. Never swap symbols, reuse one symbol for two domains, or show a symbol beside the wrong label.

**Proposed:** the default order is the guide's order (pages 6 and 9): culture, heritage, volunteering, social responsibility, entrepreneurship, quality. In RTL, the first item appears on the right.

### 3.2 Usage rules

- **Labels.** Pair each symbol with its Arabic label as live text (Proposed). The guide's label lettering uses print kashida stretching (e.g. الثقافـــة); web labels use the plain official spelling without tatweel characters, so they stay searchable and readable aloud.
- **Accessibility.**
  - When a visible label is adjacent, mark the symbol decorative: `alt=""` or `aria-hidden="true"`.
  - When it stands alone, its accessible name is the Arabic label.
- **Size.** Minimum width **48px**; preferred ≥ 64px, where the culture symbol's gold pen stays crisp (Proposed).
- **Clear space.** At least 25% of the symbol width on every side (Proposed).
- **Backgrounds.** Light backgrounds only. No dark-background versions are supplied (**Unresolved**). Do not invert or recolour them.
- **Forbidden treatments.** The same as for the logo (§2.6).
- **Quality domain.** Showing الجودة's symbol does **not** decide whether quality is an independent nomination category or a cross-cutting method. The plan describes it as "منهج أساسي متقاطع". That policy decision stays open.

---

## 4. Colour

### 4.1 Official colours (Specified: never adjust)

| Token | Value | Guide's meaning |
| --- | --- | --- |
| `--sa-brand-deep-green` | `#14413D` | Primary identity colour: stability, dignity, sustainability |
| `--sa-brand-gold` | `#CC9933` | Honour and value: a sense of distinction |
| `--sa-brand-light` | `#F4F7FC` | Clean and restful background |

`#FFFFFF` appears in the logo artwork and is used as the raised surface (**Observed**).

### 4.2 Digital extensions (Proposed)

These do not replace or alter brand colours. They fill roles the guide does not cover.

| Token | Value | Why |
| --- | --- | --- |
| `--sa-ext-green-900` | `#0F3330` | Hover/pressed green. Lets gold carry small text (5.32:1) |
| `--sa-ext-green-950` | `#0C2B28` | Active/pressed state |
| `--sa-ext-gold-text` | `#8A6417` | Gold-family text on light surfaces. Brand gold fails as text there |
| `--sa-ext-gold-tint` | `#F6ECD6` | Quiet accent surface |
| `--sa-ext-neutral-100` | `#E8EDF5` | Subtle surface |
| `--sa-ext-neutral-200` | `#D5DDE9` | Decorative dividers only |
| `--sa-ext-neutral-500` | `#6B807D` | Form-control borders (≥ 3:1) |
| `--sa-ext-neutral-600` | `#4B5F5C` | Secondary text |
| `--sa-ext-neutral-on-dark` | `#B9C8C6` | Secondary text on deep green |
| `--sa-ext-disabled-text` | `#5D6D6B` | Disabled labels |
| `--sa-ext-error` / `-tint` | `#B42318` / `#FDECEA` | Error messages |
| `--sa-ext-success` / `-tint` | `#1E6A3F` / `#E7F3EC` | Success messages |

The neutrals are green-tinted so they sit with the palette.

### 4.3 Semantic roles (Proposed)

| Role | Token | Value |
| --- | --- | --- |
| Page surface | `--sa-color-surface` | `#F4F7FC` |
| Card/field surface | `--sa-color-surface-raised` | `#FFFFFF` |
| Dark band | `--sa-color-surface-dark` | `#14413D` |
| Body text | `--sa-color-text` | `#14413D` |
| Secondary text | `--sa-color-text-muted` | `#4B5F5C` |
| Accent text on light | `--sa-color-text-accent` | `#8A6417` |
| Text on dark | `--sa-color-text-on-dark` | `#F4F7FC` |
| Links | `--sa-color-link` (underlined) | `#14413D` |
| Focus ring (light / dark) | `--sa-color-focus-ring` / `-on-dark` | `#14413D` / `#CC9933` |
| Control border | `--sa-color-border-control` | `#6B807D` |
| Primary button | bg / fg | `#14413D` / `#F4F7FC` |
| Accent button | bg / fg | `#CC9933` / `#0F3330` |

### 4.4 Measured contrast (WCAG 2.x relative luminance)

Ratios were calculated from the hex values using the WCAG formula.

**Thresholds:**

- AA normal text: 4.5:1.
- AA large text (≥ 24px, or ≥ 18.66px bold): 3:1.
- Non-text UI and focus indicators: 3:1.

| Foreground | Background | Ratio | Verdict |
| --- | --- | --- | --- |
| Deep green `#14413D` | Light `#F4F7FC` | **10.55** | ✅ All text |
| Deep green | White | **11.33** | ✅ All text |
| Deep green | Line-pattern area (effective `#EEF1F5`) | **10.00** | ✅ All text |
| Light `#F4F7FC` | Deep green | **10.55** | ✅ All text |
| Light | Green-900 `#0F3330` | **12.73** | ✅ Hover state |
| Muted `#4B5F5C` | Light / White / Subtle | **6.33 / 6.79 / 5.78** | ✅ All text |
| Gold-text `#8A6417` | Light / White | **4.99 / 5.36** | ✅ All text |
| Gold-text | Subtle `#E8EDF5` / Gold tint `#F6ECD6` | **4.56 / 4.57** | ✅ All text (narrow margin) |
| On-dark muted `#B9C8C6` | Deep green | **6.55** | ✅ All text |
| Brand gold `#CC9933` | Deep green | **4.41** | ⚠️ Large text and non-text only |
| Brand gold | Green-900 | **5.32** | ✅ All text |
| Green-900 `#0F3330` | Brand gold | **5.32** | ✅ Accent-button label |
| Deep green | Brand gold | **4.41** | ⚠️ Large text only |
| Brand gold | Light | **2.39** | ❌ Decorative only: not text, not a sole boundary |
| Brand gold | White | **2.57** | ❌ Decorative only |
| White / Light | Brand gold | **2.57 / 2.39** | ❌ Never put text on gold in white or light |
| Control border `#6B807D` | Light / White / Subtle | **3.90 / 4.19 / 3.57** | ✅ Non-text 3:1 |
| Error `#B42318` | Light / White / Error tint | **6.12 / 6.57 / 5.75** | ✅ All text |
| Success `#1E6A3F` | Light / Success tint | **6.13 / 5.78** | ✅ All text |
| Disabled `#5D6D6B` | Subtle `#E8EDF5` | **4.62** | ✅ (disabled controls are exempt; kept legible anyway) |
| Focus ring deep green | Light | **10.55** | ✅ |
| Focus ring gold | Deep green | **4.41** | ✅ ≥ 3:1 |

**Rules that follow:**

1. Gold is **never** text on light surfaces. Use `--sa-color-text-accent` (`#8A6417`) for gold-family text, or deep green.
2. On deep green, gold text must be large (≥ 24px regular or ≥ 18.66px bold). For smaller gold text, use the `#0F3330` surface.
3. Gold buttons carry `#0F3330` labels, never white.
4. Gold on light may be used for decorative accents: the vertical rule, small graphic details, the brand artwork itself. It must never be the only thing that defines a control's edge or state.

---

## 5. Typography

### 5.1 Brand typeface (Supplied)

- The guide does **not** name a typeface, and the PDF embeds **no fonts**. All its lettering is outlined.
- The brand owner has **supplied** the award's fonts separately: **itf rayat** by iwantype, version 1.001, in `docs/fonts/`. The internal metadata is recorded in [source-audit.md](source-audit.md) §10.2.
- We do not claim the guide's own lettering uses this face; that was not compared.
- **Unresolved:** the files embed no licence text. Before the font goes live (Phase 1), the brand owner must confirm that the licence covers self-hosting on a public website.

| Weight | `font-weight` | Original | Web file |
| --- | --- | --- | --- |
| Light | 300 | `docs/fonts/itfrayat-Light.otf` | `itf-rayat-light.woff2` |
| Regular | 400 | `docs/fonts/itfrayat-Regular.otf` | `itf-rayat-regular.woff2` |
| Medium | 500 | `docs/fonts/itfrayat-Medium.otf` | `itf-rayat-medium.woff2` |
| Bold | 700 | `docs/fonts/itfrayat-Bold.otf` | `itf-rayat-bold.woff2` |
| Black | 900 | `docs/fonts/itfrayat-Black.otf` | `itf-rayat-black.woff2` |

- All five are **static and upright**. There is **no 600** and **no italic**.
- The web files live in `frontend/src/assets/brand/fonts/`, declared by `fonts.css` under the alias `"SA ITF Rayat"`.
- The alias deliberately differs from the installed name, and `fonts.css` has no `local()` sources, so a copy installed on a device can never stand in.
- **Do not set the logo's lettering in this font.** The logo lettering is **artwork** and is never re-set.

### 5.2 Font stack (Proposed)

```css
--sa-font-family-brand: "SA ITF Rayat";
--sa-font-family-base: var(--sa-font-family-brand), "Noto Sans Arabic",
  "Segoe UI", Tahoma, "Geeza Pro", system-ui, -apple-system, sans-serif;
--sa-font-family-heading: var(--sa-font-family-base);
```

- **One family for headings and body.** Only one family was supplied, and its five weights give enough hierarchy.
- **The fallbacks stay**, for two reasons:
  - **Before the font loads.** `font-display: swap` shows text in a fallback first.
  - **For characters the font lacks.** These include `…` `•` `©` `®` `™` `·` `→` `§`, accented Latin (é, ü, ç), `~` `^` `` ` ``, the Arabic thousands separator ٬, and Persian-only letters and digits. Each such character is drawn from the next font in the stack.
- **Keep copy within coverage where it matters.**
  - In Arabic UI text, prefer "..." (three full stops) to `…`.
  - Avoid `•` as a typed bullet: list markers are drawn by the browser and are fine.
  - Accept that a name with accents will mix faces.
- **`font-synthesis: none`** belongs in the Phase 1 base stylesheet, so the browser never fakes a weight or an italic. The reference sheet already sets it.
- The optional features `dlig`, `salt` and `ss01`–`ss04` stay **off**. Turning them on is a brand decision.
- Fonts are loaded only by the isolated reference sheet in this phase. The running app imports neither `fonts.css` nor the tokens.

### 5.3 Weight roles (Proposed)

| Token | Value | Use | Never |
| --- | --- | --- | --- |
| `--sa-font-weight-light` | 300 | Large secondary display text, 24px and up | Body, captions, UI |
| `--sa-font-weight-regular` | 400 | Body, lead, helper text, captions, inputs | — |
| `--sa-font-weight-medium` | 500 | Field labels, buttons, tags, h4, table heads, `dt` | Long paragraphs |
| `--sa-font-weight-bold` | 700 | h1–h3, domain labels, `<strong>` in body | — |
| `--sa-font-weight-black` | 900 | Short display lines (a slogan) at h1 size and up | Paragraphs, text under 34px |

The earlier `--sa-font-weight-semibold` (600) token has been **removed**. With this font, a request for 600 renders Bold, so UI emphasis now uses Medium (500). `<strong>` inside body text resolves to 700 (Bold), and inside Bold text to 900 (Black). Both are real faces.

### 5.4 Type scale (Proposed)

| Token | Range (390 → 1440px viewport) | Line height | Weight | Use |
| --- | --- | --- | --- | --- |
| `--sa-font-size-h1` | 34 → 52px | 1.3–1.4 | 700 (900 for short display lines) | One per page |
| `--sa-font-size-h2` | 28 → 40px | 1.4 | 700 | Section titles |
| `--sa-font-size-h3` | 24 → 30px | 1.4 | 700 | Sub-sections, card titles |
| `--sa-font-size-h4` | 20 → 24px | 1.4 | 500 | Minor headings |
| `--sa-font-size-lead` | 18 → 21px | 1.8 | 400 | Introductions |
| `--sa-font-size-body` | 16 → 18px | **1.8** | 400 | Paragraphs |
| `--sa-font-size-small` | 16px | 1.7 | 400 | Meta, helper text |
| `--sa-font-size-caption` | 14px | 1.6 | 400 | Captions only. Never smaller. |

All sizes are in `rem` and scale with the reader's font-size setting and browser zoom.

**Sizes and line heights are unchanged from the system-stack version, because the font's measured proportions do not justify a change:**

| Measure | itf rayat Regular | Segoe UI |
| --- | --- | --- |
| Arabic tooth height (س) | 0.379 em | 0.382 em |
| Latin x-height | 0.505 em | 0.500 em |

- **Why it still looks smaller.** Rayat is narrower and has a tight word space: 0.175 em, against 0.274 em in Segoe UI. Words such as الدكتور عبد can look close together. This is the designer's spacing. **No `word-spacing` override is proposed**; review it with the brand owner on real copy.
- **Line box.** The default line box is 1.43–1.48 em. The tallest glyph, the lam of `الله`, reaches 1.15–1.20 em above the baseline. Two-line Bold and Black headings at line height 1.3 were checked and do not collide.
- **Optical centring.** The font's descent is large (about 0.53 em), so a single Arabic line sits slightly high in a fixed-height button. This is acceptable in the reference sheet. Revisit it with real components in Phase 1.
- **The Latin digit `1` resembles a lowercase `l`** at small sizes (for example, "h1"). Avoid relying on it in codes and identifiers; set them in the mono stack.

### 5.5 Arabic paragraph treatment (Proposed)

- **Line height.** At least 1.3 for headings and 1.8 for body text, so dots and diacritics never clip.
- **Line length.** Paragraph measure is at most `--sa-measure-prose` (42rem, about 70–80 Arabic characters).
- **Alignment.** Start-aligned, which is right-aligned in RTL. Do **not** justify on the web. The guide's print layouts justify with kashida, but browser justification of Arabic produces uneven gaps.
- **Never use `letter-spacing` on Arabic.** It breaks joining.
- **Never insert tatweel (ـ) for visual stretching** in web text. It breaks search, copy and screen readers.
- **Emphasis.** Use weight (500 for UI, 700 in text) or colour plus weight, as the guide does with bold deep green. No italics for Arabic. No `text-transform`.
- **Headings.** Section titles may echo the guide (centred, gold) **only** where the contrast rules allow: on light surfaces, use deep green or `--sa-color-text-accent`, not brand gold.

### 5.6 Mixed Arabic/Latin content (Proposed)

- Set `lang="ar"` and `dir="rtl"` at the document level.
- **Isolate direction-neutral runs** with `<bdi>`, or with `dir="ltr"` on an inline element. This applies to:
  - email addresses
  - URLs
  - phone numbers
  - reference/ID codes
  - Latin product names
  - code

  Otherwise surrounding punctuation reorders.
- **Form fields** for email, URL and phone use `dir="ltr"` with start-aligned text, so the cursor and the value read naturally. Their labels stay RTL.
- **Numerals.** The source documents use Western digits (0–9), e.g. "10 دقائق" and "3 مراحل" (Observed in the DOCX plans). Use them consistently. Dates and times need a content decision (**Unresolved**).
- **Latin passages** get `lang="en"` so screen readers switch voice.

---

## 6. Layout and spacing (all Proposed: the guide covers print pages only)

### 6.1 Spacing scale (4px base)

`--sa-space-1` … `--sa-space-9`: 4, 8, 12, 16, 24, 32, 48, 64, 96px.

`--sa-space-section` (48 → 96px) is the vertical rhythm between page sections.

### 6.2 Containers and grid

| Token | Value | Use |
| --- | --- | --- |
| `--sa-container-max` | 75rem (1200px) | Standard content |
| `--sa-container-wide` | 85rem (1360px) | Full-bleed bands, galleries |
| `--sa-container-narrow` | 48rem (768px) | Forms, articles |
| `--sa-measure-prose` | 42rem | Running text |
| `--sa-gutter` | 16 → 32px | Inline page padding |
| `--sa-grid-gap` | 16 → 24px | Grid/card gaps |

**Breakpoints** (reference values; CSS custom properties can't be used in `@media`):

| Name | Min width | Columns |
| --- | --- | --- |
| base | 0 | 4 |
| sm | 480px | 4 |
| md | 768px | 8 |
| lg | 1024px | 12 |
| xl | 1280px | 12 |

Design mobile-first. Content must reflow at 320px without horizontal scrolling.

### 6.3 RTL with logical properties

- Write all layout with logical properties, so the same CSS works if an LTR (English) edition is ever added: `margin-inline-start`, `padding-inline`, `inset-inline-end`, `border-inline-start`, `text-align: start`.
- **Avoid** physical `left` and `right`.
- The guide's gold vertical rule beside text becomes `border-inline-start: var(--sa-border-width-accent) solid var(--sa-color-rule-accent)`. In RTL it sits on the right, as in the guide (pages 10–15).

### 6.4 Icon mirroring

| Mirror in RTL | Do **not** mirror |
| --- | --- |
| Back/forward arrows, chevrons in breadcrumbs and pagination, "next step" indicators, progress direction | The logo, all domain symbols, check marks, search, close (×), play/pause and media controls, clocks, external-link glyphs that depict a real object |

Do not mirror brand artwork under any circumstances.

### 6.5 Borders, radii and shadows

- **Radii:** 0 / 2 / 4 / 8px. The guide's tagline bar and page bands are square, so keep corners restrained. Use 4px for buttons and fields and 8px at most for cards. Do not use pill shapes for primary UI.
- **Borders:** 1px hairline (`--sa-color-border-subtle`, decorative) and 1px control borders (`--sa-color-border-control`, 3:1). Use 2px for strong outlines and 4px for the gold accent rule.
- **Shadows:** `--sa-shadow-raised` for cards and `--sa-shadow-overlay` for menus and dialogs. Both are green-tinted and soft. Prefer a border to a shadow; the guide is flat.

### 6.6 Image proportions

`--sa-ratio-wide` (16:9) for video and hero media, `--sa-ratio-photo` (3:2) for news and event photos, `--sa-ratio-standard` (4:3) for cards, `--sa-ratio-square` (1:1) for thumbnails.

Crop with `object-fit: cover` and a deliberate `object-position`. Never distort.

---

## 7. Interaction and accessibility (Proposed)

The aim is WCAG 2.2 Level AA. The specs below describe visual states only. Production components belong to later phases.

### 7.1 Links

| State | Treatment |
| --- | --- |
| Default | `--sa-color-link`, **underlined** (do not rely on colour alone), `text-underline-offset: 0.25em` |
| Hover | `--sa-color-link-hover`, underline thickens to 2px |
| Focus-visible | Focus ring (below) |
| Visited | Same as default. A distinct visited colour is unresolved and not needed for Phase 0 |
| On dark | `--sa-color-link-on-dark`, underlined |

### 7.2 Buttons

| Variant | Default | Hover | Active | Disabled |
| --- | --- | --- | --- | --- |
| Primary | Deep green bg, light text | `#0F3330` bg | `#0C2B28` bg | Subtle bg, disabled text, subtle border, `cursor: not-allowed` |
| Accent (one per view at most) | Gold bg, `#0F3330` text | Gold bg plus 2px `#0F3330` inner outline | `#0F3330` bg, gold text (5.32:1) | As primary |
| Secondary | Transparent, deep-green 2px border and text | Subtle bg | Neutral-200 bg | As primary |

- **Size:** minimum 44 × 44 CSS px (`--sa-target-min`); padding `--sa-space-3` × `--sa-space-5`.
- **Labels:** verbs in Arabic. Icons are optional and never the only label.
- **Loading:** keep the button's width, swap the label for "جارٍ الإرسال…" (or similar) with a non-animated or reduced-motion-safe indicator, and set `aria-busy="true"` and `aria-disabled="true"`. Do not shrink the button into a spinner.
- **Disabled:** prefer explaining *why* nearby over disabling silently.

### 7.3 Fields

- **Label** above the field, always visible. Placeholders are never labels.
- **Default:** white surface, 1px `--sa-color-border-control` border, 4px radius, minimum 44px tall.
- **Hover:** border becomes `--sa-color-border-strong`.
- **Focus-visible:** the focus ring.
- **Error:**
  - Border uses `--sa-color-error` at 2px.
  - The message sits below the field and starts with an icon **and** the word "خطأ:" or a clear sentence, so colour is not the only signal.
  - The field gets `aria-invalid="true"`, and the message is linked with `aria-describedby`.
- **Disabled:** subtle surface, disabled text colour, `disabled` attribute.
- **Helper text:** `--sa-color-text-muted`, linked with `aria-describedby`.
- **LTR values** (email/URL/phone): `dir="ltr"` on the input.

### 7.4 Cards

- **Default:** white surface, 1px subtle border, up to 8px radius, optional `--sa-shadow-raised`.
- **Accent:** an optional `border-inline-start` gold rule echoes the guide.
- **Interactive cards:**
  - Make the heading link the single interactive element, stretching its hit area over the card if needed. Do not nest multiple links.
  - **Hover:** the border becomes `--sa-color-border-strong`. No lift-and-scale animation.
  - **Focus-visible:** the focus ring around the whole card.

### 7.5 Focus

- Use `outline: var(--sa-focus-ring-width) solid var(--sa-color-focus-ring)` with `outline-offset: var(--sa-focus-ring-offset)`: a 3px deep-green ring with a 2px gap.
- On deep green, switch to `--sa-color-focus-ring-on-dark` (gold, 4.41:1).
- Use `:focus-visible`, so mouse users don't see rings on click while keyboard users always do.
- **Never** `outline: none` without a replacement.

### 7.6 Meaning beyond colour

Status, selection, errors, required fields and the current navigation item must each have a non-colour cue, such as text, an icon, an underline, weight or a border. Required fields are marked with the word "(مطلوب)" or an explained asterisk.

### 7.7 Motion

- **Durations:** fast 120ms (hover, colour), base 200ms (menus, disclosure), slow 320ms (overlays). Easing is `--sa-easing-standard`.
- **Animate** only opacity, colour and small translations of UI. Do not animate brand artwork.
- **No continuous decorative animation:** no looping patterns, floating symbols or parallax.
- **No scroll-driven logo deformation**, shrinking-logo morphs or animated line-pattern drift.
- **`prefers-reduced-motion: reduce`:** the token file sets the motion durations to 0ms. Components must also remove non-essential transforms. Essential feedback stays as an instant state change.

---

## 8. Imagery and decoration

### 8.1 Photography and illustration

- Use **authentic, appropriately licensed** photographs of real cultural, heritage and community activity, with permission from identifiable people. Credit photographers where required.
- Do **not** invent or AI-generate:
  - portraits
  - testimonials or endorsements
  - partner or sponsor logos
  - statistics
  - winners, nominees or award outcomes
  - event photos

  Where real content is missing, use clearly marked placeholders in design files only, never in production.
- Page 1's product mockup (the award object and box) is a render in the guide. Using it on the website is **Unresolved**. It must not be presented as a photograph of a real ceremony or a delivered object.
- Do not invent trophy imagery or emblems. Do not reproduce elements from the rejected AI website concept.

### 8.2 The background line pattern

- **Observed:** a fine stepped diagonal line pattern, `#CCCCCC` at 15% opacity, on light pages only. Supplied as `background-line-pattern.svg`.
- **Proposed rules:**
  - Use it as quiet, supporting decoration on light surfaces, such as a page-top band or section background. Never place it behind dense body text unless it stays at the source opacity. Body text over it keeps 10.00:1 contrast.
  - Keep the supplied colour and opacity. Do not darken it, colour it gold or green, or put it on dark surfaces. The guide shows dark pages without it.
  - It is a page-sized composition, not a tile. Use `background-size: cover`. Do not repeat it visibly or animate it.
- **Avoid** unrelated ornamental patterns (generic arabesques, geometric stars, stock "heritage" borders) that the guide does not contain.

### 8.3 Page bands and rules (Observed → Proposed)

The guide closes each page with a thin full-width band: deep green on light pages, gold or 15%-grey on dark pages. A thin deep-green band at the foot of the site footer, or a gold rule above the footer, is a faithful web echo.

Use the gold vertical rule beside key text blocks, as on pages 10–15.

---

## 9. Unresolved decisions (for the brand owner)

1. Brand font licence: confirmation that the itf rayat licence permits self-hosting on a public website (the files embed no licence text). The font files themselves are now supplied (§5.1).
2. Original master logo files (AI/SVG) to replace the PDF-derived SVGs.
3. Symbol-only mark, favicon and app icon, plus social-sharing images.
4. Dark-background versions of the six domain symbols.
5. Monochrome or single-colour logo variants (for example, for partners' materials).
6. An official English name and logo, if an English edition is planned.
7. Approved logo placement on photography.
8. Official clear-space and minimum-size rules (the Proposed values here are placeholders).
9. Whether الجودة is an independent nomination category or a cross-cutting method.
10. Date and numeral conventions for web copy.
11. Use of the page 1 award-object mockup in public communications.
12. Whether any of the font's optional features (`dlig`, `salt`, stylistic sets `ss01`–`ss04`) or a wider word spacing should be used on the web. They stay at the font's defaults until the brand owner decides.
