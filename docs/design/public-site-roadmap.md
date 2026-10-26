# Public website roadmap

This roadmap covers the **public, informational website** of جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية, phase by phase.

Each phase is one focused, reviewable pull request (or a short series), merged into `develop` before the next phase starts.

## Outside this roadmap

The following are separate products with their own security, data-protection and governance requirements:

- the nomination/application portal
- eligibility screening
- judging and field verification
- public voting (جائزة الجمهور)
- administrative dashboards
- launch controls (for example, the live "launch" button described in the launch-event plan)
- any CMS or back-office tooling

The public site may **link** to such a portal once it exists. It does not implement any of them.

## Open policy questions

The roadmap does not decide these questions:

- **Quality domain.** Whether الجودة is an independent nomination category or a cross-cutting method. The award plan calls it "منهج أساسي متقاطع في كافة المحاور". Pages will present the six domains as the guide does, and leave the copy for this point to the award owner.
- **Content.** All award rules, dates, criteria, prizes and partner names come from the award owner. None are invented.

---

## Phase 0: Digital identity foundation *(this PR)*

**Scope:**

- Source audit.
- Digital identity guidelines.
- `brand-tokens.css` (not imported into the app).
- Faithful vector brand assets and their manifest.
- Self-hosted WOFF2 conversions of the owner-supplied brand font, with `fonts.css` (not imported into the app).
- Isolated reference sheet `docs/design/preview.html`.
- This roadmap.

**Dependencies:** the supplied identity PDF and plans in `docs/`, and the brand font files in `docs/fonts/`.

**Acceptance criteria:**

- [ ] The three official colours are preserved exactly. Every web extension is labelled as proposed.
- [ ] Every proposed text pairing meets WCAG AA, with measured ratios.
- [ ] Brand assets are extracted, not redrawn. They are verified against the PDF, and their provenance is documented.
- [ ] Missing items (font licence confirmation, master files, dark domain symbols, …) are listed as unresolved, not fabricated.
- [ ] Only the supplied font weights are declared and demonstrated, with no synthetic bold or italic.
- [ ] No change to production pages, the app entry point, routing, backend, manifests, lockfiles, build configuration or CI.
- [ ] The existing frontend lint and build still pass.

---

## Phase 1: Shared public layout

**Scope:**

- Import `fonts.css` and `brand-tokens.css` into the app.
- Add a minimal, scoped base stylesheet: `lang="ar" dir="rtl"`, body typography (with `font-synthesis: none`), focus styles and reduced-motion handling.
- Shared layout components:
  - skip link
  - header with logo
  - primary navigation
  - accessible mobile menu
  - footer (dark variant logo, contact placeholder slots, legal links)
  - page templates (standard, article, listing)
- Routing scaffold for the public pages, with empty or "content pending" states.
- A shared `DomainSymbol` component mapping each domain key to its single correct asset and Arabic label.
- Remove the Vite starter content.

**Dependencies:**

- Phase 0 merged.
- Decisions on navigation labels and the page list.
- Confirmation from the brand owner that the font licence permits self-hosting on the public site. Without it, ship with the fallback stack only.

**Acceptance criteria:**

- [ ] Header, menu and footer work by keyboard, with visible focus. The mobile menu traps and restores focus correctly and closes on Escape.
- [ ] Layout is correct at 320, 390, 768, 1024 and 1440px, with no horizontal scrolling.
- [ ] Logo meets the minimum sizes and clear space in the guidelines. The dark variant is used on dark surfaces, with no CSS filters.
- [ ] Only logical properties are used for inline layout. RTL is verified, and directional icons are mirrored correctly.
- [ ] Lint, type-check and build pass. Automated accessibility checks (e.g. axe) show no serious or critical issues.

---

## Phase 2: Homepage

**Scope:**

- Homepage sections built from approved content:
  - introduction to the award and slogan
  - the six domains (symbols plus labels, linking to Phase 3 pages)
  - the award journey summary
  - latest news (once Phase 4 exists; otherwise omitted)
  - call to action to the external portal (only once the portal exists)

**Dependencies:**

- Phase 1.
- Approved homepage copy.
- Licensed imagery.
- A decision on whether the page 1 mockup may be used.

**Acceptance criteria:**

- [ ] All copy is supplied or approved by the award owner. There are no invented statistics, partners, testimonials, winners or photos.
- [ ] Domain symbols are paired with their correct Arabic labels, in the guide's order.
- [ ] No continuous decorative animation and no logo deformation. Reduced-motion is respected.
- [ ] Largest Contentful Paint is under 2.5 s on a mid-range mobile profile. Images are responsive and lazy-loaded below the fold.
- [ ] Contrast and heading structure (one `h1`) are verified.

---

## Phase 3: Award overview, domains and journey

**Scope:**

- **About the award:** concept, purpose, implementing organisation.
- **Domains index**, plus **one detail page per domain**, with each domain's description and criteria as supplied.
- **Award journey page:** launch and support → evaluation and governance → award week, as described in the award plan.

**Dependencies:**

- Phase 1.
- Approved domain descriptions and criteria.
- A decision on how to present الجودة.

**Acceptance criteria:**

- [ ] Each domain page uses only its own symbol. URLs and slugs are stable.
- [ ] Journey stages match the award plan. Dates are shown only when officially confirmed.
- [ ] Quality-domain copy reflects the owner's decision. No policy is implied by layout alone.
- [ ] Pages are readable at 200% zoom and in Windows High Contrast / forced-colors mode.

---

## Phase 4: Media centre, news/events, contact and FAQ

**Scope:**

- News and event listing and detail templates.
- Media gallery with captions and credits.
- Contact page: verified contact details only. The form only if a backend endpoint is approved; otherwise, use mailto or phone.
- FAQ with accessible disclosure widgets.

**Dependencies:**

- Phase 1.
- A content source decision: static files vs an API.
- Approved contact details.
- Licensed media with credits.

**Acceptance criteria:**

- [ ] Emails, URLs and phone numbers render in correct LTR isolation inside RTL text.
- [ ] Every image has meaningful alt text or is marked decorative. Credits are shown where required.
- [ ] Any form has visible labels, accessible errors and spam protection, and is reviewed by the backend owner.
- [ ] Empty states (no news yet) are designed and do not look broken.

---

## Phase 5: Launch readiness

**Scope:**

- **Indexing strategy:** titles, descriptions, Open Graph images (from approved assets), `sitemap.xml`, `robots.txt`, canonical URLs, structured data where appropriate. Decide whether pre-launch environments are excluded from indexing.
- **Full accessibility audit:** automated plus manual, keyboard and screen reader (NVDA/VoiceOver with Arabic).
- **Performance budget and optimisation.**
- **Content QA:** Arabic proofreading, names, dates, links.
- **Responsive and cross-browser verification.**
- **Favicon and app icons**, once the brand owner supplies them.

**Dependencies:**

- Phases 1–4.
- Final approved content.
- Production domain.
- Supplied icon assets.

**Acceptance criteria:**

- [ ] WCAG 2.2 AA conformance is checked and remaining exceptions documented.
- [ ] Core Web Vitals are "good" on mobile for key pages.
- [ ] No placeholder or test content in production. All links resolve.
- [ ] Verified in current Chrome, Safari (iOS and macOS), Firefox and Edge at the agreed breakpoints.
- [ ] Indexing settings are confirmed per environment.
