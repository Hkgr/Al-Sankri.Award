# Administration dashboard, phase 1 («ملفات الترشح» and «لجان الحكام»)

For the people who deploy, operate and review the platform. Related: [candidate-documentation-submission.md](candidate-documentation-submission.md) (the submission these pages show), [database-schema.md](database-schema.md) §A and §F (roles and committees), [database-rules.md](database-rules.md) §3 (conflict-of-interest bar).

## 1. What exists

`/admin` is a separate application shell (`frontend/src/pages/admin/`), with its own scoped styles (`admin.css`, `.admin-*` classes only). The navigation, in order:

| Item | State |
| --- | --- |
| «الرئيسية» | A short welcome linking to the two modules |
| «ملفات الترشح» | Complete: the submitted participations, read-only |
| «لجان الحكام» | Complete: the three main-domain judging committees |
| «المواعيد» | Shown as «قريباً», not a link: **deferred** |
| «السجل» | Shown as «قريباً», not a link: **deferred** |

Also deferred, deliberately:
- the judge portal and the evaluation workflow (rubrics, scores, rankings, results);
- assigning applications to judges;
- eligibility decisions, exports and bulk actions;
- editing a submitted application.

Real pages: `/admin`, `/admin/applications`, `/admin/applications/<id>`, `/admin/committees` (`routes/web.php`). Any other `/admin/…` path is a 404. Signed-out visitors are sent to sign in and brought back. A signed-in account without administration access gets the page with status 403, which shows a refusal.

## 2. Access and roles

The migration `2026_10_28_600001_provision_administration_and_judge_roles` adds two roles and two permissions to the existing tables (`roles`, `permissions`, `role_permissions`). It is additive and idempotent, and it never replaces an existing role.

| Role | Scope | Granted by | Permissions |
| --- | --- | --- | --- |
| `award_admin` «مدير الجائزة» | platform (`user_roles`) | the command below only | `applications.view_submitted`, `committees.manage_judging` |
| `judge` «حكَم» | cycle (`cycle_user_roles`) | appointing a judge in «لجان الحكام» | none (no judge portal yet) |

- **Server-side checks:** every admin API checks the permission of the account's current, unrevoked grants on the server (`App\Admin\AdminAccess`, middleware `admin:<permission>`). Candidates and judges are refused (`403 not_admin`). No role, permission or actor is read from a request.
- **Writes:** each write also carries the page's `account_context`. A page showing another account than the signed-in one changes nothing (`409 account_mismatch`). Audit rows name the signed-in administrator.
- **After sign-in:**
  - administrators go to `/admin`;
  - judges go to their account page, with a short note that there is no judge portal yet (never to the candidate dashboard just because they are judges);
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
