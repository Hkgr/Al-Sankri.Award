# Public website: implementation and operator notes

Updated 2026-10-29. This continues the existing React 19 / TypeScript / Vite implementation and Laravel routes. No dependency was added.

## Pages and presentation

| Path | Content |
| --- | --- |
| `/` | Official name and slogan, authentic portrait, countdown, introduction, three domains, journey preview and platform actions. |
| `/about` | Purpose, values, documented impact, implementing organization and Dr. Sankari's legacy with the mosque-visit photograph. |
| `/journey` | Preparation, applications, administrative review, judging and planned award week. |
| `/eligibility` | Shared eligibility, evidence, conflicts of interest and evaluation criteria. |
| `/eligibility/culture-and-arts` | Culture and arts. |
| `/eligibility/heritage` | Tangible and intangible heritage. |
| `/eligibility/creativity` | Social responsibility, entrepreneurship and quality. |

Copy and asset mappings live in `frontend/src/content/site.ts` (replacing `content/home.ts`). Domain pages reuse the documented shared conditions; their scope descriptions introduce no extra eligibility requirements. No prize amounts, winners or ceremony dates are invented. Applications go through administrative review before committee referral.

The header links to four pages and marks the current section, including eligibility detail pages. The disclosure menu closes on Escape and restores toggle focus. The footer groups pages, domains, platform and policy links. The existing router retains hash navigation, history and unsaved-input protections. Page titles/descriptions update on public navigation.

The green/gold identity, supplied original vector artwork and five locally hosted SA ITF Rayat weights are preserved. Creativity deliberately imports `domain-volunteer-work.svg`. Photographs retain their embedded watermarks and responsive derivatives. Separate visible photo-source credits were removed; sources and hashes remain in [photo-provenance.md](photo-provenance.md). HAND and Al-Rowad mentions link to their supplied organization URLs with `target="_blank"`, `rel="noopener noreferrer"` and accessible new-tab wording.

## Schedule behavior

`GET /api/public/schedule` is an unauthenticated, throttled, `no-store` read. It returns the server clock, selected published cycle, application window and optional judging window, with UTC instants. It writes nothing and does not refer files, approve committees or grant judging permissions.

The application window is `cycle_stages.nomination_details`, the existing server submission cutoff. Open applications additionally require `NominationCycles::current()` to find exactly one usable cycle with supported geography and active domain/subcategory options. Missing or ambiguous configuration never produces an open CTA. Otherwise, the latest configured published cycle can supply informational dates after closure.

The judging window is the same `judging` stage used by the administration schedule merged in PR #18. Public reads preserve the existing judging gate. `nomination:setup` creates a missing judging row as `planned` and preserves an existing row's status and activation metadata. Updating the dates of an already enabled judging stage also updates the calendar window enforced by that existing gate; the CLI does not enable it.

Confirmed 2026 schedule, Asia/Damascus:

| Period | Last included day | Exclusive ending instant | UTC |
| --- | --- | --- | --- |
| Applications | 15 November, throughout the day | `2026-11-16T00:00:00+03:00` | `2026-11-15T21:00:00Z` |
| Judging, starting 16 November | 25 November, throughout the day | `2026-11-26T00:00:00+03:00` | `2026-11-25T21:00:00Z` |

Minute-only CLI input resets seconds to zero. Client arithmetic uses absolute instants and a server clock offset; displayed dates always use Damascus. The countdown ends at the exclusive cutoff without a reload, changes the signed-out CTA from registration to login, and announces phase changes politely without reading every tick. After judging, application and judging phases say «انتهت»; award week stays «مخطط لها». No results or ceremony announcement is inferred.

The hook shares requests for up to one minute between mounts. A mounted page keeps its received schedule until it is remounted/reloaded; operator date changes are not pushed live. Failed/missing responses show confirmed informational text without a running timer or an open invitation.

Application availability and calendar completion are separate. A server-refused application period before its deadline says «الترشح غير متاح حالياً» and labels the future deadline as its configured closing date. Journey marks applications complete only once that deadline has elapsed. Judging is current only inside a complete, valid announced interval; a missing window or a gap before judging does not imply current judging. Registration CTAs remain unavailable whenever the server refuses applications.

## Configure an existing cycle

These are operator instructions, not commands executed against production during this task. From `backend`, first confirm the existing cycle code, its `Asia/Damascus` timezone and the existing operator account. The admin schedule page identifies the selected cycle and timezone. Replace the two placeholder values below; do not invent a production cycle code.

```sh
CYCLE_CODE='REPLACE_WITH_EXISTING_CYCLE_CODE'
OPERATOR_EMAIL='REPLACE_WITH_EXISTING_OPERATOR_EMAIL'

php artisan nomination:setup "$CYCLE_CODE" --actor="$OPERATOR_EMAIL" --closes-at="2026-11-16 00:00" --judging-opens-at="2026-11-16 00:00" --judging-closes-at="2026-11-26 00:00" --dry-run --no-interaction

# After reviewing the dry run, apply the same configuration:
php artisan nomination:setup "$CYCLE_CODE" --actor="$OPERATOR_EMAIL" --closes-at="2026-11-16 00:00" --judging-opens-at="2026-11-16 00:00" --judging-closes-at="2026-11-26 00:00" --no-interaction
```

No `--create-cycle`, `--publish-cycle`, `--enable` or `--open-domains` is included. The existing application opening instant is preserved; the command configures dates and repairs missing approved taxonomy records. Check the reported availability and the public endpoint afterward. A newly planned judging stage requires the existing administration workflow to activate judging; this public feature does not activate it. The administration schedule UI edits the same records using inclusive closing calendar days.

Use the repository's existing release process for Laravel code and the built frontend assets; this feature adds no migration beyond those already on `develop`. Apply the existing base migrations in their normal release order before verifying the shared schedule. No `.env`, Plesk, Document Root or `deploy.sh` change is needed. No deployment or policy database publication was performed here.

## Verification and walkthrough corrections

The corrected [walkthrough](public-site-walkthrough.py) retains all original checks and uses observable readiness:

- **Artwork:** compares canonical rendered SVG content against the original file, including paths and colours. Vite's data URL has no filename; the original failure was a brittle test, and artwork code was already correct.
- **Navigation:** waits for route, rendered heading, title, main focus and completed scroll after the committed browser frame. The original route-only assertion raced React effects. A final integrated run also reproduced a real smooth-scroll race: route, title and focus were ready, but scrolling settled at 3px. Ordinary page changes now reset the top instantly on the next animation frame, cancelling unfinished hash scrolling. Six rapid keyboard-navigation repetitions passed after the fix. Hash navigation, history and unsaved-input protections retain their existing branches.
- **Journey after judging:** waits for the schedule response body and the two completed statuses plus planned award-week status. The original check read the loading state. The application behavior was already correct.

Two real schedule issues were fixed separately: CLI dates inheriting the operator's current seconds, and the API advertising open applications despite incomplete nomination configuration. Focused regressions also cover ambiguous cycles, dry-run rollback, admin schedule compatibility and unchanged judging activation. Integration with PR #18 updates the stale frontend route test to recognize `/admin/schedule`.

The focused PR #19 availability/link correction passes 28 frontend tests (including 10 schedule tests), lint, explicit TypeScript checking and production build. One targeted Chromium run passed 10/10 checks: three unavailable scenarios on the homepage and Journey, plus the exact conflict wording and accessible Al-Rowad link on all four eligibility pages. Backend code was unchanged; backend tests, database matrices and the full walkthrough were not repeated for this correction.

The original full-feature checks, before that focused correction, ran against the branch integrated with `develop` / PR #18:

| Check | Result |
| --- | --- |
| Frontend tests | 24 passed, including 6 countdown/date tests |
| ESLint, explicit TypeScript check, production build | All exit 0; existing bundle-size warning remains |
| `PublicScheduleTest` + `SpaRoutingTest` | 9 tests, 156 assertions passed on in-memory SQLite |
| Pint | Passed for all seven changed PHP files; `--dirty` also passed |
| Corrected browser walkthrough | 84/84 checks passed, exit 0 |
| axe WCAG A/AA checks | No violations on all seven public pages at desktop width |
| `git diff --check` | Clean |

Browser checks use Chromium, desktop 1440px, mobile 390px and 320px navigation/footer, plus a simulated deadline transition with a Los Angeles device timezone. Synthetic responses are used only for boundary/unavailable-state checks; the main walkthrough uses a real local Laravel endpoint and disposable SQLite database. The script refuses nonlocal base URLs. Screenshots wait for the rendered schedule and disable transient entrance animations.

Review screenshots: [desktop hero](screenshots/public-site/home-hero-1440.png), [mobile hero](screenshots/public-site/home-hero-390.png), [inner page](screenshots/public-site/creativity-1440.png), [320px menu](screenshots/public-site/menu-320.png), [footer](screenshots/public-site/footer-320.png), [closed deadline](screenshots/public-site/transition-closed-1440.png), [journey after judging](screenshots/public-site/journey-judged-390.png).

```sh
# Frontend (frontend/)
npm test
npm run lint
npx tsc -b --pretty false
npm run build

# Backend (backend/); override any application database and config cache.
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' APP_CONFIG_CACHE=/tmp/public-site-no-config.php php artisan test --compact --filter='PublicScheduleTest|SpaRoutingTest'
php vendor/bin/pint --dirty --format agent

# Root, against a separately prepared disposable local preview:
python docs/design/public-site-walkthrough.py docs/design/screenshots/public-site /path/to/axe.min.js http://127.0.0.1:8021
git diff --check
```

## Handoff and limitations

- The Git index was empty at takeover. `docs/privacy.txt`, `docs/terms.txt` and the modified `docs/نهائي/التصور التنفيذي لحفل الجائزة.docx` were unstaged and remain unchanged local work, outside this feature's commits. No staged document was omitted.
- All 22 untracked preview copies initially under `backend/public/index.html` and `backend/public/assets` matched the task build byte for byte. They were moved to a temporary archive outside the repository; no tracked, initially staged or unknown public asset was removed. Final preview served `frontend/dist` directly. No preview bundle is committed.
- **Official logo limitation:** supplied logo artwork still contains the older award wording/tagline. It is preserved unchanged; surrounding copy and accessible names use the approved name/slogan. Updated official master artwork is needed to change the lettering inside the logo.
- Vite SPA rendering remains client-side. A production canonical URL, sharing image and favicon have not been supplied. The combined application bundle still produces Vite's over-500-kB warning.
- Existing photo-permission and source-resolution notes remain in the provenance record. Browser coverage is Chromium on Windows; Safari/iOS, Firefox, physical mobile devices and screen readers were not tested.
