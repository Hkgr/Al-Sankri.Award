# Account removal, current award conditions and identity — 2 November 2026

This change continues the implemented site and bilingual platform from `develop` (`6548a0a`). Existing public routes, fonts, domain symbols, submission authorization and historical documents remain in place. It introduces no voting or field-verification features.

## Source and current content

- Current Arabic conditions: [the supplied Word original](نهائي/الشروط%20العامة%20للتقديم.docx), extracted in full (38 paragraphs, no tables). Only the obvious typo in the poetry prior-award clause was corrected.
- The canonical bilingual text is `backend/resources/award/terms-2026-11-02.json`: five general conditions, three domains, six categories and sixteen category-specific requirements. Public pages and candidate guidance consume this same source; the backend publishes its full text for consent.
- Official Arabic name: «جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع». The project’s established English spelling is retained: “Dr. Abdulkader Sankari Award for Culture, Heritage and Creativity”.
- The supplied PNG is preserved byte for byte in the web assets and report resources: SHA-256 `89d33f142e9d99ec35d2ee3094ba85f0b82d18dee297eea97fbdd31b56ae3161`, 3561 × 1203, with its transparency. All live award-logo imports use it, with a white surrounding surface on dark shells. The existing favicon/domain symbols are retained.
- PDF reports use `award-logo-report.png`, a proportional 1187 × 401 RGBA derivative (exactly one-third dimensions), to avoid mPDF’s high memory use when processing the full palette image. The original is also kept. No text, color or shape was redrawn.
- The source DOCX SHA-256 is `e24602c0bda61384a95236f5cccd9db4adc7872a3ea86573f6319ae9c9a75c5f`.

## Database changes and release order

These are deployment instructions for review; no deployment or production operation was performed.

1. Take and verify a database/private-storage backup before a future release. Use the existing deployment process and prevent writes while applying schema/data migrations.
2. Release the matching backend code and canonical JSON, then run `php artisan migrate --force`. The new migrations run after the existing migrations, in this order:
   - `2026_11_02_093117_add_account_removal_support`: soft-removal column, idempotency ledger, durable file-removal queue and `users.remove_accounts` permission for `award_admin`.
   - `2026_11_02_093121_update_award_categories_and_terms`: current taxonomy, application review flags and bilingual immutable application-condition versions.
3. Build/release the matching frontend with the normal project process. Its production build reads the canonical JSON from the repository; keep the backend resource alongside it in source builds.
4. Arrange an operator/worker retry of `php artisan accounts:purge-files --limit=100`. It is also attempted immediately after a successful removal transaction. A nonzero exit means paths still await removal; investigate storage availability or a protected reference, then retry. This change does not install or alter a production scheduler.

The taxonomy update can be rerun without duplicates. It retains the IDs of `culture` → `poetry` and `arts` → `story`, updates only approved domain codes/names, and deactivates obsolete category choices. If both old/new category rows already exist, live application references are consolidated onto the original stable ID. Immutable submitted snapshots and hashes are untouched.

Applications previously assigned Culture, Arts or Quality require deliberate category review. Quality is never automatically mapped to an unrelated category. A draft owner reselects an active category **within the same main domain** while applications are open. Submitted applications require the existing authorized administrative review screen to confirm their category. Other application text, files and journey IDs remain intact. Referral/provisioning, judge access and current aggregation are blocked while the review flag remains. The original submitted category is still shown in its historical snapshot; current administrative classification is shown separately, including on newly generated application cards.

The migration appends `general_application_terms` policy versions to draft/published cycles; setup of a new cycle does the same. An identical latest version is reused. New submissions explicitly accept the latest version ID, with its bilingual text hash stored in the immutable snapshot and its consent row. An outdated or missing consent is refused. Previous account-policy acceptances and previously accepted application-condition versions are not overwritten. A submitted review resolves its accepted version rather than silently showing newer conditions.

Rollback is deliberately refused once taxonomy/cycle data or account-removal history exists. Reverting these data changes requires a verified backup and an operator-reviewed recovery plan. An unused account-removal migration can be rolled back, but it refuses to remove a permission with additional custom grants. Physical file deletion and permanent data removal cannot be undone by code rollback.

## Removal behavior and ownership

The action is available from registered candidates and judging committee members. Both preview and confirmation require `users.remove_accounts`; confirmation checks the account context, permissions and scope again under locks. Self-removal and every current/historical administrative platform role, including multi-role accounts, are refused on the server. A preview fingerprint becomes invalid when relevant application/score/account/committee/file state changes. A request UUID records one completed operation; a retry returns that result without repeating deletion. Conflicting reuse of a UUID is refused.

**Retain history:** the account becomes disabled and soft-removed, current memberships end, role grants are revoked, and sessions, access tokens, Google links and recovery tokens are removed. Historical relationships, applications, versions, files, marks, decisions and logs remain. Account identity becomes “Deleted account”; the login email is released. The same email can register a fresh account with a new ID, with no automatic access to old profiles or applications.

**Permanent owned-data removal:** an explicit email confirmation is required. The transaction removes owned candidate applications/versions and their dependent links, consents, appeals and evaluations, plus the removed judge’s assignments/marks. Other accounts, applications, judges’ marks and shared files/entities remain. Files with a surviving link or surviving derivative are protected. Remaining shared attribution foreign keys use a disabled, soft-removed generic archive identity with no old personal details; attribution alone never causes deletion of someone else’s entity.

Affected published results are withdrawn, cached score summaries cleared and linked published stories unpublished before scores are removed. Owned stories and results are removed; only empty withdrawn result-version anchors needed by a surviving supersession chain remain. Current committees losing a member return to draft and require replacement and reapproval. Retained former marks appear as historical and never enter the new average. Current aggregation is recomputed from the current approved membership; it remains incomplete until all required current marks arrive. Existing finalized marks of other current judges remain unchanged.

Private file rows/links become unavailable atomically with database deletion. Physical bytes are deleted only after commit through the durable queue. Storage failures leave a retryable job; a referenced disk/path is never deleted. A failed database transaction leaves both records and bytes intact. The limited removal audit/ledger records actor, time, numeric target ID, mode and counts, without copying names, emails, narratives or attachments.

## Verification evidence

Tests use isolated local databases on **MariaDB 10.11.18**, bound to loopback on a temporary port; no production database was connected. The relevant existing candidate/admin/judging suites and new ownership/terms/taxonomy regressions are exercised. Tests include permission and administrative multi-role protection, access revocation, same-email isolation, shared files/attributions, transaction rollback, idempotency, changed previews/scores, historical judge marks, recomputation, published results and result chains, migration reruns/stable IDs and immutable consent versions.

Final backend result: **74 tests, 2349 assertions, all passed** across `AccountRemovalTest`, `AwardTermsAndTaxonomyTest`, `NominationDetailsTest`, `DocumentationSubmissionTest`, `AdministrationTest` and `JudgePortalTest`.

Frontend verification includes Node tests, ESLint, TypeScript project checks and the Vite production build. The build retains the existing advisory about a JavaScript chunk over 500 kB.

Final commands: `npm test` (**31 passed**), `npm run lint`, `npx tsc -b --pretty false`, `npm run build`, `php vendor/bin/pint --dirty --format agent`, and `git diff --check` all passed. Final browser database checks showed two completed retention removals and two completed purges, zero pending file removals, the keeper’s shared file intact and the purged private file row absent.

Browser evidence uses Chromium with real session/CSRF authentication and the isolated MariaDB fixture database, at 1440 × 1000 and 390 × 844. It checks both candidate removal modes, judge removal, exact-email confirmation, legacy draft correction, consent gating, all sixteen conditions in both languages, organization link attributes, locale preservation, RTL/LTR, logo loading/aspect and horizontal overflow. Screenshots are in [the review gallery](design/screenshots/account-removal-and-terms/).

The initial Git index was empty. The pre-existing modified ceremony DOCX and untracked `docs/privacy.txt` / `docs/terms.txt` are preserved and excluded from this change. Their text is not published as policy content.
