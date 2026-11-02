# Candidate dashboard and stage 1: basic information

This document is for the people who deploy, operate and review the platform. It covers:

- the «مترشح» classification and how existing accounts receive it;
- the candidate dashboard and stage 1 («استكمال البيانات الأساسية»);
- how the data maps onto the database, and how the read-only lock is enforced;
- the policy texts that must be published before stage 1 can be confirmed.

Related: [authentication.md](authentication.md) (accounts and sign-in), [database-schema.md](database-schema.md) §K (tables), [database-rules.md](database-rules.md) (rules).

**Scope.** This document covers stage 1. Stage 2 (domain, subcategory and work details) is described in [candidate-nomination-details.md](candidate-nomination-details.md). There are no uploads, judging, results or administration screens. Confirming the basic information does **not** submit a nomination: no award cycle, application or application version is created.

## 1. The «مترشح» classification

| Item | Value |
| --- | --- |
| Role | `roles.code = 'candidate'`, `name_ar = 'مترشح'`, `scope = 'platform'`, no permissions |
| Provisioned by | migration `2026_10_27_300001_provision_candidate_role`. It is idempotent: an existing `candidate` role is kept exactly as it is. When the migration creates the role itself, it records `role.provisioned` in `audit_logs`. |
| Granted to | every ordinary account without another effective role, regardless of email verification or profile completion; account creation and classification share a transaction |
| Grant row | `user_roles` and `user_role.granted` audit history; automatic reconciliation has a nullable system actor, while an administrative transition retains its actor |

**Account-creation paths that grant it:**

- email and password registration (`POST /api/auth/register`);
- a new Google account whose address Google is authoritative for;
- a Google sign-up completed through the same-browser email confirmation (`/auth/google/confirm`).
- an account created for a judge appointment (the candidate grant is then revoked by the appointment in that same transaction).

If the role is missing (migrations not run), account creation fails and nothing is written, rather than creating an unclassified account.

**Server policy:** `App\Auth\RoleExclusivity` supplies the effective platform/cycle queries and serializes grants, endings and reconciliation on the account row. Browser fields such as `role` and `is_candidate` cannot grant roles. Returning sign-in, Google linking and authenticated requests reconcile the stored classification with the current assignments.

**Exclusive default:** any other effective role, including `award_admin`, `judge` and custom roles in **any** cycle, excludes candidate. Other roles may coexist with each other. Revocation of candidate and the other grant occur in one transaction; ending the last other role restores candidate even if it was never held before. History is retained. Platform grants honor `assigned_at`, optional `starts_at` and exclusive `ends_at`; cycle grants honor `starts_at` and exclusive `ends_at`. Revoked, future and expired roles do not exclude candidate. Changing the displayed administration cycle changes candidature progress only. Candidate APIs refuse other-role accounts with `403 candidate_role_unavailable` (judges retain `is_judge`). Submitted versions never become editable because of a role transition. See [administration.md](administration.md) §2.

**Timed assignments:** the scheduler runs `roles:reconcile-candidates --apply` every minute, but correctness does not depend on that task: authenticated requests reconcile before authorization, and permission queries themselves check the effective window. The account snapshot refreshes when the browser tab regains focus. User lists and exports also reconcile pending classifications before querying the same effective grants.

**Default destination.** Administrators go to `/admin`, judges to `/judge`, candidates to `/dashboard`, and other-role accounts to `/account`. A legitimate authorized intended destination still wins, including signed email-verification links. A saved candidate destination cannot bypass the current role policy.

### Classifying existing accounts

After running migrations, preview and explicitly apply the repair to current state:

```sh
php artisan roles:reconcile-candidates --dry-run   # counts intended actions; no writes or audit events
php artisan roles:reconcile-candidates --apply     # rechecks each account under its lock and applies
# Optional: --user=123 limits either operation to one account.
```

Both the default invocation and `--dry-run` are read-only. The compatibility command `candidates:provision` delegates to this policy and also requires `--apply`. Missing candidate grants are restored; contradictory ones are revoked with `revocation_source=candidate_role_policy`. Correct accounts produce no new grant or audit event on repeats. Deleted accounts and archive identities (`account_removed` / `@deleted.invalid`) are skipped. Disabled ordinary accounts can have their classification repaired, but their status is never changed and they cannot sign in. Applications, attachments, scores and historical rows are untouched. The migration only adds role-window/system-revocation support; it does not bulk repair existing accounts. Its rollback refuses to discard role-window/reconciliation history once used.

## 2. Pages and API

| Path | What it is |
| --- | --- |
| `/dashboard` | Welcome, the stages of the nomination file and stage 1's real state |
| `/dashboard/basic-information` | The stage 1 form, or the permanent read-only summary once confirmed |
| `/policies/privacy-policy`, `/policies/terms-of-use` | The published text (or `?version=N` for an earlier published version) |
| `/account` | Account settings (verification, Google linking, sign-out), linked from the profile menu |

Laravel serves `index.html` for exactly these paths (`routes/web.php`); `/dashboard/anything-else` is a real 404.

| Method and path | Purpose |
| --- | --- |
| `GET /api/candidate/overview` | Stage states for the dashboard: status, completion time and type only. Requires the `X-Candidate-Context` header. |
| `GET /api/candidate/reference-data` | ISO countries (Arabic names) with calling codes |
| `GET /api/candidate/basic-profile` | The owner's confirmed information (or `null`) and the confirmation requirements. Requires the `X-Candidate-Context` header. |
| `POST /api/candidate/basic-profile/check` | Validates one section; saves nothing (30/min per account). Requires `account_context` in the body. |
| `POST /api/candidate/basic-profile` | Confirms stage 1, once (10/min per account). Requires `account_context` in the body. |
| `GET /api/policies/{privacy-policy,terms-of-use}` | Public: the published version, or `?version=N` (never a draft) |

Every `/api/candidate/*` endpoint requires a session, an active account and the candidate role, sends `Cache-Control: no-store, private`, and has no identifier in its URL: it always concerns the signed-in account. State-changing requests use the existing cookie and CSRF protection. There are no bearer tokens.

`/api/user` includes `is_candidate`, the effective `roles` with their scopes/cycles, current administration permissions, `is_judge` and the opaque `account_context` (see «Account binding» in §4). It never includes profile data.

## 3. The dashboard

- **Header:** the original horizontal logo (at least 200px wide, with clear space), today's Gregorian date and the time labelled «بتوقيت دمشق» (`Asia/Damascus`), and an account menu with the name, an initials avatar and «مترشح».
- **Clock:** it reads the device clock, changes once a minute, makes no server requests and is not a live region. Stored times stay in UTC.
- **Menu:** «لوحة المترشح», «إعدادات الحساب», «العودة إلى الموقع العام», «تسجيل الخروج». It is a disclosure; Escape or clicking outside closes it.
- **Navigation:** two links (الرئيسية, البيانات الأساسية) instead of a sidebar.
- **Stage 1 card:**
  - «لم تبدأ بعد» with «ابدأ الاستكمال»;
  - after confirmation: «مكتملة» with the time, and «عرض البيانات»;
  - «غير متاحة بعد», with the reason, when the policies are not published.
- **Later stages:** one card says they will become available later. No stages, fields, dates or percentages are invented.
- **Unverified email:** the dashboard and the form remain usable, with the existing resend action. Confirmation itself requires a verified email.

## 4. Stage 1: the form

**Sections:**

1. Applicant type: «فرد» or «مؤسسة», as radio cards.
2. For an individual: personal identity, then residence and contact. For an institution: institution details, then representative and contact.
3. Review and declarations.

These are internal sections of stage 1, not award stages.

**Behaviour:**

- **Type changes:** the type can change until confirmation. If the other type already holds input, a warning asks before discarding it.
- **Checks before moving on:** each section is checked in the browser, then by the server (`/check`) with the same rules, so phone and country errors appear before the review.
- **Memory only:** values stay in memory while moving between sections and after failed requests. Nothing is stored in `localStorage` or `sessionStorage`.
- **Leaving:** a link, back/forward, reload or closing the tab asks first while there is unsaved input.
- **Request failures:**
  - **network failure during a section check:** the input is kept. The message says so, because a check never saves anything;
  - **no answer to the final save** (network failure, or a 5xx that may come after the commit): the outcome is unknown, and the form never claims that nothing was saved. It asks the server what this account now has (`GET /api/candidate/basic-profile`, with the form's account context):
    - a stored profile is shown read-only, saved once;
    - with no stored profile, the outcome is still **not** terminal: on a server with several workers, the original request may still be running and commit after that read. The form therefore says no saved profile is visible yet and the result is unconfirmed, keeps the input, and explains that retrying is safe: if the first request succeeded, the retry returns the stored profile and saves nothing twice (§6);
    - if that read fails too, it says the result could not be confirmed, with the same retry explanation;
    - from then on, the leave warning also says the result is unconfirmed (it may or may not have been saved, and can be checked on the dashboard);
  - **401 (session ended):** the input is kept, with a link to sign in again **with the same account** in a new tab. A 401 is decided before the save, so nothing was saved;
  - **409 `account_mismatch`:** the browser is now signed in as another account (see «Account binding» below);
  - **419:** retried once by the existing client;
  - **403 (disabled account):** signs the visitor out;
  - **422:** returns to the section with the first error;
  - **other 409s:** see §6.
- **Account binding:** what a candidate page shows comes from one account snapshot. The opaque `account_context` (an HMAC of the account id with the application key, `App\Candidates\AccountContext`) travels **with the signed-in account** in `/api/user`, together with the name and email the page displays and pre-fills. It is never taken from a profile response.
  - **Every read and write is checked:** candidate pages send the value with every read of candidate state (`X-Candidate-Context` header on `GET /api/candidate/overview` and `GET /api/candidate/basic-profile`, including the form's reconciliation read) and every write (`account_context` in the body of `/check` and of the confirmation).
  - **Mismatch:** the `candidate.context` middleware compares the value with the signed-in account's own value and refuses a mismatch, or a missing value, with `409 account_mismatch`. This happens before validation, before the transaction, and before any stored profile or stage state is read or returned.
  - **A page with no input yet** (for example the dashboard, or stage 1 being opened after another tab signed in as someone else): it reloads the account snapshot, then shows the account now signed in, with its own name, pre-filled email and data, and a notice «تغيّر الحساب المسجّل دخوله…». Pages are keyed by the snapshot, so another account never inherits a page's state.
  - **Authorization:** the value is only compared, never used to choose an account; the session's account remains the only authority.
  - **The open form** (with input): it never switches account by itself. It keeps its input and explains the situation, and its email re-check never adopts another account. The person can sign in again with the original account in another tab and retry, or discard the input («تجاهل ما أدخلته…»), which reloads the dashboard of the account now signed in.
  - **Same account:** signing in again with the same account, in a new session, resumes the same form.
- **Review:** the review groups everything by section, each with «تعديل», shows empty optional fields as «لم يُضف», and displays the lock warning: «يرجى مراجعة بياناتك بعناية. بعد تأكيد الحفظ، ستصبح البيانات الأساسية متاحة للعرض فقط ولن تتمكن من تعديلها من حسابك.»
- **Declarations:** separate unchecked boxes for the privacy policy, the terms of use and the accuracy of the data, plus authority to represent the institution for institutions. The policy links open the exact version in a new tab, so the form is not lost.
- **Contact email:** prefilled from the login email as an editable suggestion. It is stored only with the applicant and never changes the login email or its verification.

### Fields and validation

The server is authoritative (`App\Http\Requests\Candidate\BasicProfileRequest`); the browser mirrors the same rules.

| Field | Rule |
| --- | --- |
| Names (first, middle optional, last; representative) | Letters and marks of any script, spaces, `'` `’` `.` `-` and the Persian joiners; 80 characters each (representative 150). Google's display name is never split into these fields. |
| Birth date | Day, month and year controls; a real Gregorian date from 1900 to today (Damascus date); no age rule |
| Nationality, residence, institution country | ISO 3166-1 alpha-2 from the 249 assigned codes, Arabic names from CLDR (`App\Support\Countries`) |
| Gender | `male` or `female`, explicitly chosen; there is no default |
| Identity number, licence number | Text up to 50 characters: letters, digits, spaces and `- / .`; leading zeros kept; no format assumed, no uniqueness rule |
| Phone numbers | A calling code plus a number, validated for that country with libphonenumber and stored as E.164. They are never marked verified, and there is no SMS. The backup number is optional. |
| Emails | Up to 255 characters, format checked only |
| Website and social links | `http`/`https` only, a host name, no embedded credentials, 500 characters; at most 5 links. They are never fetched by the server. |
| Address, activity, title | Required text: address 500, activity 150, title 150, institution name 255 |

Arabic-Indic (٠–٩) and Persian (۰–۹) digits are converted to 0–9 in dates, numbers and identifiers. The fields of the other type are refused (`prohibited_unless`), and only the listed fields are read.

## 5. Where the data goes

| Form value | Individual | Institution |
| --- | --- | --- |
| Applicant | `applicants.type = 'individual'`; `name_ar` = first, middle and last names | `applicants.type = 'organization'` (the existing general organization type); `name_ar` = institution name |
| Account link | `applicants.representative_user_id` = the account | same |
| Country | `headquarters_country_code` = country of residence | `headquarters_country_code` = institution country |
| Address | `headquarters_address` | not collected |
| Main phone, email | `contact_phone` (E.164), `contact_email` | `contact_phone`, `contact_email` (the institution's) |
| Licence number | — | `registration_number` |
| Type-specific fields | `applicant_individual_details`: names, birth date, nationality, gender, encrypted identity number, backup phone | `applicant_institution_details`: activity type, website |
| Representative | — | `applicant_members`: the account (`user_id`), name, job title, primary contact |
| Links | `applicant_social_links` (positions 1–5) | same |
| Completion | `candidate_profiles`: account, applicant, type, declaration times, `completed_at` | same, plus `representation_declared_at` |
| Policy acceptance | `account_policy_acceptances`: account, exact policy version, server time | same |

The account itself (name, login email, verification) is never changed. The other applicant types (`team`, `initiative`, `official_institution`) remain in the schema and are not offered here. No award cycle, application, version or consent row is created.

## 6. Confirmation and the read-only lock

First, before validation, the request's `account_context` must belong to the signed-in account (§4, «Account binding»). A form showing another account gets `409 account_mismatch`: it is never treated as that account's retry, and never receives its stored data.

Then `App\Candidates\BasicProfiles::finalize` runs in one transaction:

1. lock the account's `users` row (`SELECT … FOR UPDATE`). This is a stable parent that always exists, unlike the profile row being created;
2. if a profile already exists, return it unchanged (`409 profile_already_completed`, with the stored data);
3. require a verified email (`403 email_unverified`);
4. require both policies to be published (`409 policies_not_configured`) and the accepted ids to be exactly the published versions, read under a shared lock (`409 policy_changed`, with the current versions);
5. write the applicant, details, member, links, acceptances, profile and audit entry.

The unique key `uq_candidate_profiles_user` is the final guard: a losing parallel insert returns the stored profile.

**Retries:** double clicks, a second tab, retries and a lost response all end in exactly one profile, and later attempts never overwrite it.

**After confirmation:**

- there is no update, replace or delete endpoint. `PUT`, `PATCH` and `DELETE` answer 405, and any further `POST` answers 409 without changing anything, including a request to switch type;
- the read-only page has no inputs. The lock is enforced by the server, not by hiding buttons;
- there is no administrative unlock or correction in this phase.

## 7. Private data

- **Identity number:** encrypted at rest with Laravel's `encrypted` cast and the existing `APP_KEY`. It is hidden from model serialization and has no index or search column. **Do not rotate `APP_KEY`** without re-encrypting it, or it becomes unreadable.
- **Where details appear:** only `BasicProfileResource`, for the owner, behind `no-store`. The identity number, birth date and address are not in `/api/user`, the overview, URLs or logs.
- **Audit entries:** codes and ids only (`candidate_profile.completed` with type, applicant id and policy version ids; `user_role.granted`; `account_policy.published` with a text hash).

## 8. Policy texts: a prerequisite before launch

**There is no approved privacy policy or terms of use in the repository.** None was written or invented. The system is ready for them:

| Piece | Behaviour |
| --- | --- |
| Storage | `account_policy_versions` (cycle-independent; `policy_versions` stays for cycle consents with submitted applications), one published version per policy |
| Not configured | Stage 1 shows «غير متاحة بعد» with the reason, the form is not offered, and the server refuses confirmation (`409 policies_not_configured`). The policy pages say the text is not published yet. |
| Acceptance | Exact version id, account and server time in `account_policy_acceptances` |
| New version | The previous one is retired. The next confirmation needs the new version, and earlier acceptances keep pointing at what was accepted. |

### Activation (minimal procedure, no admin portal)

Once the owner has approved the Arabic texts:

1. Save each approved text as a UTF-8 plain-text file on the server, outside the web root.
2. In `backend/`, check, then publish:

   ```sh
   php artisan account-policies:publish privacy_policy /path/privacy.txt --title="سياسة الخصوصية" --dry-run
   php artisan account-policies:publish privacy_policy /path/privacy.txt --title="سياسة الخصوصية"
   php artisan account-policies:publish terms_of_use   /path/terms.txt   --title="شروط الاستخدام"
   ```

   The command shows the version number and the text's SHA-256, asks for confirmation (`--force` skips it), retires any previous version and publishes the new one in one transaction.
3. Check `/policies/privacy-policy` and `/policies/terms-of-use`, and that stage 1 on the dashboard now offers «ابدأ الاستكمال».

To change a text later, publish again: published text is never edited.

### Preview fixtures (development only)

`php artisan db:seed --class=PreviewAccountPolicySeeder` publishes texts clearly marked «نص تجريبي … غير معتمد». It refuses to run in production, and `DatabaseSeeder` never calls it. Tests use the same labelled factory. Consents recorded against preview texts have no value and exist only on test databases.

### Declarations

The two declarations are single sentences in the interface, recorded as times on `candidate_profiles`:

- «أُقرّ بأن البيانات التي أدخلتها صحيحة وكاملة على حدّ علمي.»
- for institutions: «أُقرّ بأنني مخوَّل بتمثيل المؤسسة المذكورة وتقديم بياناتها.»

The owner may wish to review their wording. No marketing, publication, photo-use or field-visit consent exists.

## 9. Deployment (Plesk; nothing deployed by this work)

1. **Back up** the database.
2. **Dependencies:** `composer install --no-dev --optimize-autoloader` adds `giggsey/libphonenumber-for-php-lite` (phone validation metadata; no network access).
3. **Migrate:** `php artisan migrate --force` runs three additive migrations:
   - `300001` provisions the role;
   - `300002` creates the account policy tables;
   - `300003` creates the candidate profile tables.

   Existing data is not changed.
4. **Classify existing accounts:** `php artisan candidates:provision --dry-run`, review the counts, then run it without `--dry-run`.
5. **Frontend:** build and publish `dist/` into `backend/public/` as today.
6. **Policies:** publish the approved texts (§8). Until then, stage 1 stays unavailable.
7. **Check:**
   - `/dashboard` and `/dashboard/basic-information` load directly and on refresh;
   - `/dashboard/x` answers 404;
   - a new registration lands on `/dashboard`.

**Rollback.** `php artisan migrate:rollback` refuses, before changing anything, while any confirmed profile, policy acceptance or candidate role grant exists. That data would otherwise be lost, and accounts would lose their classification.

When rollback does go ahead, it removes the `candidate` role only if this migration created it (a `role.provisioned` audit entry) and nothing refers to it. A role that existed before the migration is kept unchanged, together with its permission links.

## 10. Tests

- **Feature tests** (SQLite by default; also run on MariaDB 10.11 and MySQL 8.4 against a disposable `*_schema_test` database):
  - `tests/Feature/Candidate/CandidateClassificationTest.php`: the role, atomic creation, destinations and provisioning rules;
  - `tests/Feature/Candidate/BasicProfileTest.php`: both branches, validation, policies, the lock, privacy and encryption;
  - Google classification tests in `tests/Feature/Auth/GoogleAuthenticationTest.php`.
- **Schema tests:** `tests/Feature/CandidateSchemaTest.php`, opt-in on disposable MySQL/MariaDB databases ([database-rules.md](database-rules.md) §7). They cover applying the migrations on a deployed schema, rollback refusal and the new constraints.
