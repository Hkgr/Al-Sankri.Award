# Homepage: implementation and verification notes

This is the first public homepage, together with only the shared shell it needs. It is a single client-rendered page in the existing React 19 + TypeScript + Vite app. **No dependency was added.**

## Structure

| Path | Role |
| --- | --- |
| `frontend/index.html` | `lang="ar" dir="rtl"`, title, description, Open Graph text, theme colour, a `<noscript>` line, and an empty favicon (none has been supplied). |
| `frontend/src/main.tsx` | Imports `fonts.css`, `brand-tokens.css`, `styles/base.css` and the layout styles. |
| `frontend/src/styles/base.css` | Scoped base: body typography, `font-synthesis: none`, focus ring, buttons, containers, smooth scrolling only without reduced motion. |
| `frontend/src/components/layout/` | `SiteHeader` (skip link, logo, in-page navigation, disclosure menu), `SiteFooter`, `layout.css`. |
| `frontend/src/components/Photo.tsx` | `<picture>` helper: WebP `srcset`, art-directed narrow crops, JPEG fallback, intrinsic sizes. |
| `frontend/src/content/home.ts` | All homepage copy and data, with the source of each statement. |
| `frontend/src/pages/home/` | `HomePage.tsx` (six sections) and `home.css`. |
| `frontend/src/assets/photos/` | Photo derivatives; see [photo-provenance.md](photo-provenance.md). |

**Removed:** the Vite starter (`App.css`, `index.css`, `hero.png`, `react.svg`, `vite.svg`, `public/favicon.svg`, `public/icons.svg`).

## Where the content comes from

| Section | Source |
| --- | --- |
| Name, slogan, implementing organisation | Award plan and launch plan (`docs/*.docx`) |
| Opening explanation | Identity guide p. 7 («جائزة سنوية ذات طابع ثقافي وتنموي ومجتمعي…»), plus the implementing organisation from the plan |
| Why this award | Guide p. 16 (statement) and the plan's «الغاية الأساسية» (three aims) |
| The human legacy | HAND, *USD 40 Million Donation by Dr. Sankari…* (20 Dec 2025) and *In the historic surroundings of the Umayyad Mosque…* (11 May 2026). **No amounts, dates beyond those stated, quotations or endorsements were used.** The closing sentence connects his documented preference for keeping out of the spotlight to the award's documented aim of recognising overlooked impact. |
| Six domains | Labels from guide pp. 6, 10–15. Each description and symbol meaning is taken from that domain's own page. |
| Quality | «منهج أساسي متقاطع في كافة المحاور» (plan). It is shown apart from the five domains as a cross-cutting approach. The page says nothing about how it is nominated. |
| Award journey | The plan's three stages and their contents. «التمكين البعدي» comes from the launch plan. No dates are given, and nothing says applications are open. |
| Closing | Guide p. 16 |

**Deliberately absent:** contact details, social accounts, legal pages, news, winners, counters, countdowns, application buttons and nomination forms. The only external links are verified ones: Al-Rowad's own site and the two HAND source pages.

## Design decisions (Proposed, following the merged identity)

- **Opening.** Photography leads.
  - On narrow screens, the portrait comes first.
  - From 768px, it sits beside the text, with the guide's gold vertical rule between them.
  - The slogan is the only `h1`, set in Black 900 with a deliberate break after the ellipsis.
- **Surfaces.** Light and white surfaces alternate, and the closing and footer are deep green. The line pattern appears once, behind the opening. Gold is used only for rules, markers and large text on deep green (4.41:1).
- **Logo.**
  - The light-background logo is used in the header.
  - The dark-background logo is used only on `#14413D`.
  - Neither is ever animated or deformed, and both stay at or above the 200px minimum.
- **Domains.**
  - **Layout:** three then two across, with Quality in its own band.
  - **Symbols:** they are decorative (`alt=""`), because the visible label names them.
  - **«معنى الرمز»:** a native `<details>` disclosure.
- **Motion.** One 640ms fade-and-rise on the opening, plus 120ms colour transitions. All are removed under `prefers-reduced-motion`, which also disables smooth scrolling.
- **Mobile menu.** It is a non-modal disclosure, so focus is not trapped.
  - The toggle's `aria-expanded` and `aria-controls` reflect the menu's state.
  - Escape closes the menu and returns focus to the toggle.
  - Following a link or clicking outside closes it.
  - Widening the viewport to the inline navigation (64rem) closes it.
  - Below 24rem, the toggle shows its icon only, keeping «القائمة» as its accessible name.

## Verification performed

**Environment:** Chromium 151 driven by Playwright 1.62 on Windows, against the production build served by `vite preview`. axe-core 4.10.3 was installed in a temporary folder outside the project.

| Check | Result |
| --- | --- |
| `npm run lint` | Pass |
| `npm run build` (`tsc -b && vite build`) | Pass |
| `git diff --check` | Clean |
| Widths 320, 390, 768, 1024, 1440 | No horizontal overflow. All 11 images load, with the expected WebP chosen per width. All requests are local, with no HTTP errors and no console errors or warnings. |
| Fonts | All five `SA ITF Rayat` faces `loaded`. Per text node (CDP), every node renders with the itf rayat face matching its weight: 107 nodes, 0 wrong faces, 0 fallback glyphs. |
| axe (WCAG 2.0/2.1/2.2 A–AA + best practice) | **0 violations** at every width. "Incomplete" covers only the opening's photo credit over the background pattern. Checked by hand: the worst case is muted text on the darkest pattern line, at 6.00:1. |
| Headings | One `h1` (the slogan) → `h2` per section → `h3` items |
| Keyboard | Tab order is skip link → logo → navigation → opening actions → photo credits → six «معنى الرمز» disclosures → footer links, and every stop shows a visible 3px ring (deep green on light, gold on deep green). The skip link moves focus to `<main>`. The disclosures open and close with Enter. |
| Mobile menu (390px) | `aria-expanded` toggles. The menu opens with Enter and Space. Escape closes it and returns focus to the toggle. An outside click and following a link both close it. Widening the viewport closes it. |
| Links | 18 links. Every in-page target exists, and the three external URLs are verified sources. The only `<button>` is the menu toggle. |
| Reduced motion | 0 running animations, `scroll-behavior: auto`, 0s transitions |
| **Real 200% browser zoom** | Headed Chromium, with the profile's default page zoom set to 200% in a 1280px window. The browser reports `devicePixelRatio` 2 and a 632px CSS viewport, which confirms genuine zoom rather than DPR emulation. Result: no overflow, the page reflows to one column, and the menu works. |
| Photos | The face is fully visible in every crop at all five widths and at 200% zoom (screenshots reviewed). |

**Review screenshots:** [screenshots/homepage-1440.jpg](screenshots/homepage-1440.jpg) (desktop, full page, scaled to 1000px) and [screenshots/homepage-390.jpg](screenshots/homepage-390.jpg) (mobile, full page).

**Not checked:**

- Safari/iOS and Firefox;
- screen readers (NVDA/VoiceOver);
- real mobile devices;
- non-Windows fallback fonts.

## Known limitations

- **Indexing.** The page is **client-rendered** (Vite SPA), with no SSR or prerendering. Crawlers that do not run JavaScript see only the `<title>`, the meta description and a `<noscript>` line. Whether to prerender, add SSR, or exclude pre-launch environments from indexing is a Phase 5 decision. No `robots` rules, canonical URL or `og:image` are set, because there is no production domain or approved sharing image yet.
- **Photographs.** Reuse permission from HAND and approval of his portrait's prominence are still to be confirmed (see [photo-provenance.md](photo-provenance.md)). The portrait source is 1200 × 790.
- **Favicon.** None has been supplied. `index.html` uses an empty icon so that browsers do not request a missing file.
- **Content decisions.**
  - The visit caption writes the month as «أيار/مايو». The date convention is still open (identity-guidelines §9).
  - Domain descriptions and stage texts come from the source documents. Edited copy should come from the award owner.
