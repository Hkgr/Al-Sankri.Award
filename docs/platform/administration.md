# Administration dashboard («المستخدمون»، «ملفات الترشح»، «لجان الحكام»، «السجل» and reports)

For the people who deploy, operate and review the platform. Related: [candidate-documentation-submission.md](candidate-documentation-submission.md) (the submission these pages show), [database-schema.md](database-schema.md) §A and §F (roles and committees), [database-rules.md](database-rules.md) §3 (conflict-of-interest bar).

## 1. What exists

`/admin` is a separate application shell (`frontend/src/pages/admin/`), with its own scoped styles (`admin.css`, `.admin-*` classes only). The navigation, in order:

| Item | State |
| --- | --- |
| «الرئيسية» | A short welcome linking to the modules |
| «المستخدمون» | Complete: every registered account, read-only, with roles and candidate progress (§6) |
| «ملفات الترشح» | Complete: the submitted participations, read-only |
| «لجان الحكام» | Complete: the three main-domain judging committees |
| «المواعيد» | Shown as «قريباً», not a link: **deferred** |
| «السجل» | Complete: «سجل الحركات» (the audit trail) and «الأخطاء التقنية» (sanitized application-log summaries) (§8) |

Also deferred, deliberately:
- rankings and award results (a judge's mark and the committee average are recorded; rankings are not);
- eligibility decisions and bulk actions;
- editing a submitted application.

Real pages: `/admin`, `/admin/users`, `/admin/applications`, `/admin/applications/<id>`, `/admin/committees`, `/admin/log` (`routes/web.php`). Any other `/admin/…` path is a 404. Signed-out visitors are sent to sign in and brought back. A signed-in account without administration access gets the page with status 403, which shows a refusal. Judges use `/judge` and `/judge/projects/<id>`.

## 2. Access and roles

The migration `2026_10_28_600001_provision_administration_and_judge_roles` adds two roles and two permissions to the existing tables (`roles`, `permissions`, `role_permissions`). It is additive and idempotent, and it never replaces an existing role.

| Role | Scope | Granted by | Permissions |
| --- | --- | --- | --- |
| `award_admin` «مدير الجائزة» | platform (`user_roles`) | the command below only | `applications.view_submitted`, `committees.manage_judging`, `users.view_registered` (added by `2026_10_28_700001_add_registered_users_permission`; existing administrators receive it through their existing grant. The migration records the permission and the `award_admin` link it creates separately in `audit_logs`, and its rollback removes only those. A rollback is refused, with nothing changed, while another role is linked to a permission it created) |
| `award_admin`, continued | | | `reports.export` «تصدير التقارير», `audit.view` «عرض سجل الحركات», `logs.view_system` «عرض الأخطاء التقنية» (added by `2026_10_29_800001_add_reports_and_audit_permissions`, with the same per-row ownership markers and the same refusing rollback) |
| `judge` «حكَم» | cycle (`cycle_user_roles`) | appointing a judge in «لجان الحكام» | none. The portal checks this grant, not a permission |

- **Server-side checks:** every admin API checks the permission of the account's current, unrevoked grants on the server (`App\Admin\AdminAccess`, middleware `admin:<permission>`). Candidates and judges are refused (`403 not_admin`). No role, permission or actor is read from a request.
- **Writes:** each write also carries the page's `account_context`. A page showing another account than the signed-in one changes nothing (`409 account_mismatch`). Audit rows name the signed-in administrator.
- **After sign-in:**
  - administrators go to `/admin`;
  - judges go to `/judge` (never to the candidate dashboard just because they are judges);
  - candidates go to `/dashboard` as before.
- **Registration:** public registration still grants only the candidate classification. Existing roles and their history are kept.

### Granting administration access (the only way)

```bash
php artisan admin:grant-access --email="existing-account@example.com" --dry-run   # shows what would change
php artisan admin:grant-access --email="existing-account@example.com"
```

- **The account must already exist.** Register it first through the site, or sign in with Google.
- **Disabled accounts are refused.**
- **Idempotent:** running it again changes nothing.
- **Recorded:** the grant goes to `user_roles` and to `audit_logs` (`user_role.granted`, source `admin_grant_access_command`).
- **No revoke command or role-management screen in this phase.** To withdraw access, a database operator sets `revoked_at` and `revoked_by` on that `user_roles` row.

## 3. «ملفات الترشح»

- **Who appears:** applications with a submitted version (`current_version_id`), whatever workflow status they have moved to. Drafts never appear.
- **The table:**
  - **filters:** cycle, main domain, subcategory (depends on the domain), applicant type and status;
  - **search:** reference number, work title or candidate/institution name;
  - **sorting:** whitelisted columns only;
  - **pages:** 10, 25 or 50 rows;
  - **state in the address:** the filters live in the page address, so a refresh or «العودة إلى جدول ملفات الترشح» restores them.
  - **one cycle scope:** the address's `cycle` (a cycle, or `all` for «كل الدورات») is the table's scope. The header's cycle choice and the toolbar's cycle filter both change it, which resets the page and drops a domain or subcategory the new scope doesn't have. Back/forward and the return link restore it, and the header follows. The request always uses that same scope, and the results line names it.
- **One application** (`/admin/applications/<id>`) is read-only. It shows:
  - **Stages 2 and 3, declarations and accepted policies:** taken from the **immutable submitted version** (`application_versions.data_snapshot`), with the files linked to that version;
  - **Stage 1:** the profile that the version references. It is confirmed once and never edited.

  Administrators see the full basic information there, the identity number included. The number stays encrypted at rest, and it never appears in the table, in `/api/user`, in logs or in the snapshot.
- **Files:** `GET /api/admin/applications/<id>/files/<link>`, only for a file linked to that application's submitted version.
  - **Delivery:** documents as attachments; videos inline for native playback (byte ranges, `nosniff`, a sandbox CSP), exactly like the owner's route.
  - **What is never revealed:** storage paths and public URLs.
  - **Owner routes unchanged:** the candidate's owner-only routes are not changed.
  - **Scan status:** stays honest: `pending` files are labelled as not scanned for malware.

## 4. «لجان الحكام»

**Structure:** one `committees` row with `type = judging` per **main domain** of a cycle (`cycle_domain_id`). There are three: `culture_and_arts`, `heritage` and `creativity`. Each covers all subcategories of its domain; nothing is classified by subcategory. Members are `committee_members` rows; there is no parallel table.

| Action | Rule (checked inside the transaction) |
| --- | --- |
| Create committee | Only when asked; as a draft; one current per cycle and domain (`uq_committees_current`). No members or sample data are created. |
| Add judge | Only while the committee is a draft. The person must have an active account, no current judging membership in the cycle (also guarded by `uq_cycle_user_roles_current` on the judge grant) and **no submitted application in the cycle**. Adds the membership and a `judge` grant for the cycle. |
| End membership | Only while the committee is a draft. Sets `left_at` (the row stays as history) and revokes the cycle's judge grant. Refused if judging assignments exist for that member. |
| Approve | Exactly **3 or 5** current members, all with active accounts. |
| Return to draft | From approved, so the membership can change; refused once judging assignments exist for the committee. |

**Judge accounts** (the «إضافة حكَم» dialog):
- **Existing account:** shown as it is first, then appointed only on explicit confirmation. Its password, verified email, Google sign-in, name and phone are never changed. A disabled account is refused, not reactivated.
  - **The confirmation is bound to the account shown.** The lookup returns an `account_ref` (an HMAC of the account id and normalized email). The appointment sends that account's own email with it, and the server checks both again under the account's lock. If the email no longer names exactly that account, nothing happens (`409 account_changed`) and the administrator looks the email up again. An account is never resolved by email alone.
  - **In the dialog:** a late or out-of-order lookup answer is dropped, editing the email discards the account shown, and confirmation is unavailable while a lookup is pending.
- **New email:**
  - **What is created:** an account with the given name and optional phone, **no password**, an unverified email and no candidate classification.
  - **Delivery of the link:** after the commit, the existing password-reset link is sent (same broker, mailer, 60-minute expiry and throttle). The appointment does not depend on the email.
  - **If sending fails:** the page says so, and «إعادة إرسال رابط الإعداد» in the members table retries.
  - **Not done:** no shared or default password, and no new invitation system.
- **Concurrent requests:**
  - two requests for the same new email: the second becomes "account exists";
  - two appointments of the same person: the second is refused.

**Conflict of interest** (`App\Candidates\Submissions`, at submission). These accounts cannot submit:
- a current `award_admin` (any cycle);
- a current `judge` of the application's cycle;
- a current member of the cycle's judging, integrity or trustees committee (unchanged).

The bar works both ways: a person who already submitted in the cycle is not appointed as one of its judges. Candidate history and the candidate classification are never removed.

**Lock order:** account rows first (the administrator's shared and the judge's for update, in ascending id order; a new account's row is always the newest), then the committee row. The submission locks the candidate's own row first, so an appointment and a submission of the same person run one after the other, and each sees the other's committed result.

**Audit (`audit_logs`, attributed to the administrator; ids and codes only, no personal data or passwords):**
- `committee.created`, `committee.approved`, `committee.returned_to_draft`;
- `user.created` (source `judge_appointment`);
- `cycle_user_role.granted`, `cycle_user_role.revoked`;
- `committee_member.added`, `committee_member.ended`.

## 5. Deployment

1. Back up the database.
2. `php artisan migrate --force`. This runs one additive migration, which adds rows only (roles, permissions, `role_permissions`). Earlier migrations are unchanged. Its rollback is refused while any account holds or held one of these roles.
3. Build and publish the frontend as today.
4. Grant the first administrator: `php artisan admin:grant-access --email="…"`.

Nothing else changes: no `.env`, Plesk, Document Root, `deploy.sh` or mail settings. The setup link uses the mailer already configured for password resets.

## 6. «المستخدمون»

`/admin/users` (permission `users.view_registered`) lists **every registered account exactly once**. That includes accounts without a candidate profile, staff, and unverified or disabled accounts. It is read-only: no editing, deletion, role assignment, password reset, impersonation, bulk action or messaging.

**Columns:** name; email with a verified/unverified badge; current roles; account state; registration date; last sign-in («لم يسجّل دخولاً» when there is none); candidate progress; final submission, with a link to the read-only application page for administrators who may open it.

**Roles:**
- **Platform roles:** current, unrevoked `user_roles` grants, all shown (for example «مترشح» and «مدير الجائزة» together).
- **Cycle roles:** current `cycle_user_roles` grants (not revoked, inside `starts_at`/`ends_at`), shown with their cycle and, for a judge, the main domain of the current membership.
- **Hidden:** revoked or expired grants.

**Progress** is computed for the **cycle selected in the header**, from stored records only. There are no progress columns and no second copy of stage state.

| Label | Stored state in the selected cycle |
| --- | --- |
| «لم يستكمل البيانات الأساسية» | No `candidate_profiles` row |
| «بانتظار فئة الترشح وبيانات العمل» | A profile (Stage 1 is account-level), but no `candidate_nominations` row for this profile **and this cycle** |
| «بانتظار ملفات العمل» | The journey exists; no documentation change saved (`documentation_revision = 0`) |
| «توثيق العمل غير مكتمل» | Documentation changes saved, but not confirmed for the current revision (including a confirmation made stale by a later change) |
| «جاهز للإرسال» | `documentation_confirmed_revision = documentation_revision` and not submitted |
| «تم إرسال ملف الترشح» | The journey's application has a submitted version, whatever its workflow status became since |
| «غير مطلوب لهذا الدور» | No profile, and the account is an administrator, a current judge, or has no candidate classification |
| «البيانات الأساسية مكتملة (لا دورة)» | No cycle exists, so only Stage 1 can be known |

- **What isn't tracked:** form input that was never saved (Stages 1–2) isn't stored, so there are no "started" states or completion percentages.
- **Other cycles:** a participation in another cycle never counts for the selected one, and no user's "latest" application is chosen.
- **Closed stages:** closing a cycle or stage doesn't erase completed stages. The page says separately when the selected cycle's nomination stage isn't open now.
- **Changing cycle:** changing the cycle changes the progress shown and resets the page, but never removes accounts.
- **Filters:** search (name/email), role (with «حكَم» scoped to the selected cycle), account state, email verification, progress (the same expression as the labels), sorting by name, registration date and last sign-in, and 10/25/50 per page. All of them live in the page address.
- **Privacy:** the list returns only the table's fields (`no-store`). Never included: identity numbers, addresses, profile details, funding, narratives, password hashes, tokens or storage paths.

## 7. Reports and exports

Every export is made on the server, from the same services as the page it comes from: the same validation, permission checks, projections, progress rules, cycle and sort. There are no per-row API calls and no paging loops. An export needs `reports.export` **and** the permission of the page it exports. Candidates and judges get nothing.

| Export (where) | Source permission | Code in `/api/admin/exports/{code}` |
| --- | --- | --- |
| «المستخدمون» (results bar) | `users.view_registered` | `users` |
| «ملفات الترشح» (results bar) | `applications.view_submitted` | `applications` |
| Committee members (each committee panel) | `committees.manage_judging` | `committee-members` |
| Judges' marks (an application's «قرار اللجنة») | `applications.view_submitted` | `judge-scores` |
| «سجل الحركات» | `audit.view` | `audit` |
| «الأخطاء التقنية» | `logs.view_system` | `system-logs` |

- **What is exported:** **all** rows matching the current filters, search, sort and cycle, never just the visible page. Each file states the scope, the filters in Arabic, the generation time (Damascus) and the row count.
- **Limits** (one place: `App\Reports\ExportLimits`): 1,000 rows for PDF and 10,000 for Excel. Above a limit the export is refused with a clear message (`422 export_too_large`) and nothing is cut. Narrow the filters, or use Excel.
- **Excel (.xlsx):** a real workbook (OpenSpout). The sheet is right-to-left, with Arabic headings, a styled frozen heading row, column widths and filters. Values are written as text cells (only whole counts are numbers), so a value starting with `=`, `+`, `-` or `@` is never evaluated as a formula.
- **Table PDF:** the page size follows the column count (A4 portrait, A4 landscape above 5 columns, A3 landscape above 9). Headings repeat on every page and cells wrap. Actions and internal fields are left out.
- **«بطاقة الترشح» (application card PDF):** on an application's page, «تنزيل بطاقة الترشح PDF». It is built from the same authorized detail service as the page:
  - Stage 1 comes from the profile the submitted version references;
  - Stages 2–3 come from the submitted snapshot and its version-linked file manifest;
  - judging is a separate section, «حالة التحكيم وقت التصدير».

  It lists file names, types, sizes and scan status, and the links as text. It never embeds files, storage paths, temporary URLs or credentials, and never fetches links. Every value is escaped.
- **Downloads:**
  - authenticated, `no-store`, and bound to the displayed account (`X-Candidate-Context`; another account gives `409`);
  - rate-limited to 10 a minute per account (`throttle:admin-exports`);
  - a 2-minute client deadline with loading and error feedback;
  - ASCII file names such as `applications-2026-10-29-143000.xlsx`.
- **Storage:**
  - PDFs are generated in memory;
  - an Excel file is written to `storage/app/private/exports/` (never under `public/`) and deleted once it is sent; leftovers from an interrupted request are removed after an hour;
  - mPDF's temporary files live in `storage/framework/cache/mpdf/`.
- **Audit:** every export and card is recorded as `report.exported` (table code, format and row count, plus the application and cycle for a card). Filter values are not recorded. A protected attachment opened by an administrator or a judge is recorded once as `file.downloaded`; range requests from video playback don't add rows.

### Dependencies and fonts

| Package | Licence | Use |
| --- | --- | --- |
| `mpdf/mpdf` 8.3 | **GPL-2.0-only** | PDF (Arabic shaping, RTL). **The owner should confirm this licence is acceptable.** The alternative, TCPDF (LGPL), shapes Arabic noticeably worse |
| `openspout/openspout` 4.32 | MIT | XLSX |
| Tajawal (`backend/resources/fonts/tajawal/`) | SIL OFL 1.1 (`OFL.txt` beside it) | PDF text only. The site font Rayat ships as CFF-based OpenType (`OTTO`), which mPDF cannot embed. Noto Sans Arabic and Noto Naskh Arabic failed in mPDF's OpenType layout. DejaVu Sans (bundled with mPDF) covers missing glyphs |

Both packages are pure PHP and install with a normal `composer install`: no browser, daemon or external service. **Required PHP extensions:** `gd`, `mbstring`, `zip`, `dom`, `xml`, `xmlreader`, `fileinfo`, `intl`, `zlib` (plus `filter`, `libxml` and `iconv`, which are usually built in).

## 8. «السجل»

`/admin/log` has two tabs, each shown only with its permission.

### «سجل الحركات» (`audit.view`)

It reads the existing `audit_logs` table; no second log is added.
- **Columns:** time (Damascus); actor («النظام» when no account is recorded); Arabic action label; record type and reference (application reference, committee name or account name); the cycle recorded with the event («بلا دورة» otherwise); a short description; expandable details.
- **Order and paging:** newest first, with a stable order (time, then id); 25/50/100 per page, paged on the server.
- **Filters** (all kept in the page address): date range, actor (a name or email, or `system`), action, record type and number, cycle (one cycle, «أحداث بلا دورة», or all), and request ID. A row's request ID filters to every event of that request.
- **Details:** only allowlisted scalar fields are shown. The rest is only counted («حقول أخرى»). A judge's mark is never shown.

**Recorded events.** Each is written once per real change, in the same transaction as the change, so a refused or repeated request adds nothing. Each carries the request ID, and the cycle where one really applies.

| Area | Events |
| --- | --- |
| Accounts and sign-in | `user.registered` (password and Google), `auth.login` (with the method), `auth.login_failed` (the account if one exists; never the typed email or password), `auth.login_throttled`, `auth.login_refused` (disabled account), `auth.logout`, `auth.password_reset`, `auth.email_verified`, `auth.google_linked` |
| Grants | `user_role.granted`, `cycle_user_role.granted` / `.revoked`, `user.created` (judge appointment) |
| Candidate stages | `candidate_profile.completed` (Stage 1 is account-level, so **no cycle**), `nomination_details.confirmed`, `documentation.file_added` / `.file_removed` / `.links_saved` / `.confirmed`, `application.submitted` |
| Judging | `committee.created` / `.approved` / `.returned_to_draft`, `committee_member.added` / `.ended`, `judging_assignments.provisioned`, `evaluation.finalized` |
| Reports and files | `report.exported`, `file.downloaded` |
| Set-up commands and migrations | award cycle, domain, subcategory, stage and policy set-up; `role.provisioned`, `permission.provisioned`, `role_permission.provisioned` |

A test fails if any recorded action lacks an Arabic label.

**Not recorded:**
- viewing pages and tables;
- saving Stage 1–2 drafts (only confirmations are recorded);
- failed validation;
- password-reset link requests (recording them would reveal whether an email exists).

**Historical rows:**
- **Older rows:** rows written before this release have no request ID, and most have no cycle. They show as «بلا دورة» and are never back-filled or guessed.
- **Migration markers:** the markers that migrations rely on for ownership are kept unchanged.
- **Payloads:** no stored payload is modified.

### «الأخطاء التقنية» (`logs.view_system`)

A read-only view of Laravel's own log files. Nothing is installed or streamed.
- **Sources:** only the files of the configured `single`/`daily` channels that lie directly in `storage/logs` (`laravel.log`, `laravel-YYYY-MM-DD.log`). They are chosen by an id from a server list. There is no path input, and the reader cannot follow symlinks, open other folders or delete anything.
- **Reading:**
  - newest first, backwards, at most 1 MB scanned per page (32 MB per export), 50 entries per page;
  - «قراءة إدخالات أقدم» continues from a byte cursor;
  - each multiline entry (a stack trace) is read as one entry, and its body is then discarded.
- **Shown fields:** time, level, exception class or category, the file and line where it was thrown (base name only), request ID and a generic Arabic summary.
- **Never shown:** messages, SQL, payloads, links, credentials, OAuth codes or stack traces. An entry without an exception gets the generic «إدخال سجل نصي؛ محتواه مخفي لأسباب أمنية».
- **Coverage:** the page states the channels read, the retention (`days` of the daily channel), the oldest file present, and the window actually scanned.
- **Limits of coverage:** it covers the whole application (not per cycle), and only what the log files still hold. Errors logged elsewhere (the web server, PHP-FPM, Plesk) are not visible here.
- **No files:** when no file can be read, the page says so plainly.

## 9. Deploying reports and «السجل»

1. Back up the database.
2. `composer install --no-dev --optimize-autoloader` adds `mpdf/mpdf` and `openspout/openspout`. Check the PHP extensions in §7.
3. `php artisan migrate --force` runs one additive migration (three permissions linked to `award_admin`). Existing administrators receive them through their existing grant.
4. Build and publish the frontend as today.
5. `storage/` must stay writable (already required). The `storage/app/private/exports/` and `storage/framework/cache/mpdf/` folders are created on first use.

No `.env`, Plesk, Document Root, `deploy.sh`, queue or scheduler change is needed.
