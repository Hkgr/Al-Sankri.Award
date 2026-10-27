# Public authentication: accounts, sessions and Google sign-in

This document is for the people who deploy and maintain the site. It covers:

- how account registration and sign-in work;
- what must be configured on Plesk and in Google Cloud;
- what was tested, and what still needs a real-world check.

**Scope.** Creating a public account also gives it the platform classification «مترشح» (`candidate`), in the same transaction. It creates **no** applicant, application, nomination, cycle choice or committee membership. The candidate dashboard and stage 1 are described in [candidate-onboarding.md](candidate-onboarding.md). Applications, judging and administration are later work.

## 1. How it works

| Piece | Implementation |
| --- | --- |
| Pages | `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` and `/account` are React pages. Laravel serves the compiled `public/index.html` for exactly these paths (`backend/routes/web.php`), so a direct visit or refresh works. Every other path keeps Laravel's own handling: API routes, `/auth/google/*`, `/up`, assets and real 404s. |
| Sessions | Laravel's session guard through Sanctum's SPA mode (`statefulApi()`). The session lives in an HttpOnly cookie. Nothing is stored in `localStorage`, and there are no bearer tokens or JWTs. |
| CSRF | The app calls `GET /sanctum/csrf-cookie`, then sends the `XSRF-TOKEN` cookie value back as `X-XSRF-TOKEN` on every POST. A 419 refreshes the cookie and retries once. |
| Passwords | Laravel's `hashed` cast (bcrypt). Rule: at least 8 characters, with at least one letter and one number (`Password::defaults()` in `AppServiceProvider`). |
| Emails | Email verification and password reset use Laravel's own notifications, with short Arabic right-to-left templates (`resources/views/mail/account-action*.blade.php`). |
| Google | Laravel Socialite, server-side authorization-code flow with session `state` and PKCE (§4). |

### API

All endpoints accept and return JSON. Messages are always Arabic: the `UseArabicLocale` middleware sets the locale whatever `APP_LOCALE` says.

| Method and path | Purpose | Limit |
| --- | --- | --- |
| `GET /api/auth/options` | `{ google: { available } }`: a yes/no flag only | none |
| `POST /api/auth/register` | name, email, password, password_confirmation → signed-in account | 10/min per IP |
| `POST /api/auth/login` | email, password, optional `redirect` → `{ data, redirect }` | 10/min per IP, and 5 failures/min per email + IP |
| `POST /api/auth/logout` | ends the session and rotates the CSRF token | signed-in only |
| `POST /api/auth/forgot-password` | always the same answer (no account enumeration) | 5/min per IP, 2/min per email |
| `POST /api/auth/reset-password` | token, email, password, password_confirmation | 10/min per IP |
| `GET /api/user` | the signed-in account: name, email, verification, has-password, Google-linked, `is_candidate`, and an opaque `account_context` for candidate pages | signed-in only |
| `POST /api/email/verification-notification` | resend the verification link | 3/min per account |
| `POST /api/account/google/link` | confirm the current password, then start linking Google | 5/min per account |
| `GET /email/verify/{id}/{hash}` | link in the verification email (signed, expires after 60 minutes) | 6/min per IP |
| `GET /auth/google/redirect`, `GET /auth/google/callback` | Google sign-in and linking | 20/min per IP |
| `GET /auth/google/confirm` | link in the email that confirms a pending Google sign-up (§4) | 20/min per IP; at most 3 emails per address per hour |

### Rules the backend enforces

- **No account enumeration on sign-in or recovery:**
  - an unknown email, a wrong password and a Google-only account all get the same login message, and the hashing time is equalised;
  - "forgot password" always answers the same, and sends a link only to active accounts.
- **Registration does reveal a used email.** It answers "this email is already registered", as Laravel does by default, and this is rate-limited.
- **Disabled accounts** (`users.status = 'disabled'`):
  - cannot sign in with a password or with Google;
  - an existing session is ended on its next request, with a 403 and `code: account_disabled`.
- **No client control over privileges.** The browser never sets `status`, verification, roles or permissions, because the account is created server-side with `forceFill` on the allowed fields only. The «مترشح» role is granted by the backend (`App\Auth\CandidateRole`) with every new account, including new Google accounts; linking Google never changes roles.
- **Session hygiene.** The session ID is regenerated at every sign-in. Sign-out invalidates the session and issues a new CSRF token.
- **Safe redirects.** After sign-in, only `/dashboard`, `/dashboard/basic-information`, `/account` and `/email/verify/…` are accepted as return destinations (`App\Auth\SafeRedirect`). Anything else, including other sites, falls back to the account's default: `/dashboard` for candidates, `/account` otherwise. A signed verification link opened while signed out still returns there after sign-in.

## 2. Plesk-only environment settings

Set these in the production `.env` **on Plesk only**. Nothing here is committed with real values; `backend/.env.example` holds empty placeholders.

| Variable | Production value | Why |
| --- | --- | --- |
| `APP_URL` | `https://<site domain>`, exactly as visitors use it | Sanctum treats this host as first-party. |
| `SESSION_DRIVER` | `database` | The `sessions` table already exists. |
| `SESSION_SECURE_COOKIE` | `true` | The session cookie is sent over HTTPS only. |
| `SESSION_SAME_SITE` | `lax` (the default) | Required for the Google callback to keep the session. |
| `SESSION_DOMAIN` | unset / `null` | Host-only cookie. |
| `SANCTUM_STATEFUL_DOMAINS` | unset, unless the site answers on more than one host | If both `www` and the bare domain serve the site, list both, e.g. `example.org,www.example.org`. Redirecting one host to the other is simpler. |
| `MAIL_*` | see §3 | Verification and reset emails. |
| `GOOGLE_AUTH_ENABLED`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | see §4 | Google stays disabled until all four are set. |

**After changing `.env`, clear the cached configuration.** If `php artisan config:cache` is used, run `php artisan config:clear` (or `config:cache` again).

## 3. Email (SMTP): required, and not yet tested in production

No SMTP credentials were available for this work. The flows were built and tested with Laravel's test mail facilities: the `array` transport in automated tests and the `log` mailer in the local end-to-end run.

**Production email delivery has not been tested.** Until SMTP is configured, verification and reset emails are not delivered:

- the account is still created;
- the account page offers "send a new verification link";
- forgot-password still answers generically and records the failure in the log.

Configure a mailbox on Plesk (for example `no-reply@<domain>`), then set:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps            # port 465; use "smtp" with port 587 for STARTTLS
MAIL_HOST=<the server's mail host, e.g. mail.<domain>>
MAIL_PORT=465
MAIL_USERNAME=<mailbox address>
MAIL_PASSWORD=<mailbox password, Plesk only>
MAIL_FROM_ADDRESS=<the same mailbox address>
MAIL_FROM_NAME="جائزة الدكتور عبد القادر سنكري"
```

**Also on Plesk:**

- enable SPF and DKIM for the domain;
- publish a DMARC record, so the messages are not treated as spam.

**Operator check after configuring:**

1. Register a test account with a mailbox you control, and confirm the Arabic verification email arrives.
2. Confirm its link starts with `https://<domain>/email/verify/` and that following it shows «تم تأكيد بريدك الإلكتروني».
3. Use "نسيت كلمة المرور؟" on that account, and confirm the link starts with `https://<domain>/reset-password?token=` and lets you set a new password.

If the links start with `http://` instead of `https://`, Laravel is not seeing HTTPS behind the Plesk proxy. Review the proxy headers before going live.

## 4. Google sign-in

### Behaviour

- **Scopes and tokens.** Only `openid email profile` are requested, with no offline access and no `include_granted_scopes`. Google's tokens are used once, on the server, to read the identity, and are then discarded. They are never stored or sent to the browser.
- **State protection.** The OAuth `state` and the PKCE verifier are kept in the session and checked on return. The flow is never stateless. A forged, missing or replayed state is refused before Google is contacted.
- **Identity key.** The identity key is Google's `sub` (`oauth_identities.provider_subject`), not the email.
  - A returning identity signs into its own account, even if its Google email has changed.
  - A new identity creates a passwordless account (`users.password` is NULL, never a dummy password).
  - Two simultaneous first sign-ins end in one account (unique keys, plus a retry on the losing insert).
- **Ownership rule.** A Google identity is only ever stored against an account whose email ownership is established. This prevents *account pre-hijacking*, where someone registers another person's address and plants their own Google identity on it before the owner arrives.
- **Authoritative addresses.** Google is authoritative for an address, following Google's guidance, when `email_verified` is true **and** it is a `@gmail.com` address or carries a Workspace `hd` claim.
- **New Google sign-ups:**
  - **Authoritative address:** the account is created at once, verified, with the identity linked.
  - **Any other address** (for example a Google account created with a third-party email, or a missing or false `email_verified`):
    - **nothing is created yet**: no account and no identity;
    - the claims wait in the visitor's session, and a one-time link is emailed to the address (`/auth/google/confirm`, valid for 60 minutes);
    - the sign-up completes only when that link is opened **in the same browser session that started it**, so the mailbox owner and the Google account holder must be the same person;
    - opened anywhere else, the link creates nothing;
    - if the address was registered locally in the meantime, the visitor is sent to sign in instead.
- **Existing local accounts:**
  - **No automatic linking.** Google is never attached just because the emails match. The visitor sees «يوجد حساب مسجّل بهذا البريد الإلكتروني…» and must sign in with their password first.
  - **Explicit linking.** The owner can then link Google on `/account` after confirming that password.
  - **Ownership at link time.** The link is stored only if the account's email is **already verified**, or the chosen Google identity is **authoritative for that same address**; in that case the address also becomes verified. An unrelated Google identity is refused («لم يُربط حساب Google: أكّد بريدك الإلكتروني أولاً…»).
  - **Identity in use.** An identity already linked to another account is refused.
- **Rechecks at Google's callback.** A linking request lasts 10 minutes and is bound to the account's current credentials and status (an HMAC of its password hash, remember token, email and status). It is rechecked when Google returns. These all void it:
  - a password reset;
  - disabling the account (the session is also ended);
  - signing in as another account in the same browser.
- **Returning sign-ins.** A linked identity whose account is not verified is refused, as a safeguard.
- **Graceful failures.** These outcomes return to the sign-in page with an Arabic message:
  - cancelling at Google;
  - an invalid state;
  - an error from Google;
  - missing configuration;
  - rate limiting.

  Logs record only the exception class and HTTP status: never the authorization code, tokens or response bodies.
- **Unavailable state.** When Google is not configured, `/api/auth/options` reports it as unavailable. The button is then shown disabled with «تسجيل الدخول عبر Google غير متاح مؤقتاً…», and email/password sign-in keeps working.
- **Button artwork.** The button follows Google's branding guidelines (light theme). The "G" is Google's official artwork from `signin-assets.zip` (`Android + Web/SVG/Light`, icon-only square variant). It is cropped to the G by changing only the SVG `viewBox`, and is not redrawn or recoloured.

### Google Cloud configuration (operator)

In the [Google Cloud console](https://console.cloud.google.com/), use a project owned by the award organisation, then go to **Google Auth Platform**:

1. **Branding.** Separately from the testing exception above, the production consent screen needs the items below. Submit for brand verification if Google asks:
   - app name: «جائزة الدكتور عبد القادر سنكري»;
   - a support email that the organisation monitors;
   - the app home page `https://<domain>/`;
   - the **privacy policy URL** (required; see §6);
   - authorised domain: `<domain>`.
2. **Audience.** Choose **External**.
   - **Testing:** while the app is in *Testing*, Google normally restricts it to listed test users (at most 100).
   - **The exception:** Google documents one exception that applies here. If the app requests only `openid`, `userinfo.email` and `userinfo.profile` (or their OpenID Connect equivalents name, email and profile), users do **not** need to be on the test-user list ([Google Cloud help, answer 15549945](https://support.google.com/cloud/answer/15549945?hl=en)). This integration requests only those scopes, so any Google account can sign in during Testing. Adding any other scope would remove the exception.
   - **Testing consents expire:** in *Testing*, a test user's authorization expires seven days after consent.
   - **Launch:** select **Publish app** (*In production*) for launch.
3. **Data access.** Add only `openid`, `.../auth/userinfo.email` and `.../auth/userinfo.profile`. Add no other scopes.
4. **Clients → Create client → Web application:**
   - **Authorised redirect URI:** `https://<domain>/auth/google/callback`, exactly, with no trailing slash.
   - **Authorised JavaScript origins:** not needed; the flow is server-side.
   - Use a **separate** development client for `http://localhost:5173/auth/google/callback`. Do not add localhost to the production client.
5. Copy the client ID and secret into the Plesk `.env` only:

```dotenv
GOOGLE_AUTH_ENABLED=true
GOOGLE_CLIENT_ID=<client id>.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=<client secret, Plesk only>
GOOGLE_REDIRECT_URI=https://<domain>/auth/google/callback
```

### Real Google authorization test (not yet performed)

The automated Google tests are **mocked at the HTTP boundary**. Socialite's real Google provider runs, including the state and PKCE checks, but Google's token and userinfo endpoints answer from a queue. No real Google authorization has been performed, because no credentials were supplied. After configuring, an operator should check:

1. `https://<domain>/api/auth/options` returns `{"google":{"available":true}}`, and the button on `/login` is active.
2. **New Gmail account.** «المتابعة مع Google» → choose a Gmail account that has no site account → you land on `/dashboard` as «مترشح», and `/account` shows «مؤكَّد» and "Google linked".
3. **Returning sign-in.** Sign out, sign in with Google again, and confirm it is the same account (no duplicate in `users`).
4. **Cancel.** Start again and press *Cancel* on Google's screen; `/login` shows «أُلغي تسجيل الدخول عبر Google…».
5. **Existing local account.** Register locally with a mailbox, then try Google with a Google account using the **same address**. You are refused with «يوجد حساب مسجّل…», nothing is linked, and you are not signed in.
6. **Linking.** Sign in to that local account with its password. On `/account`, enter the password and choose «تأكيد وربط حساب Google». It returns with «رُبط حساب Google بحسابك». Google sign-in now opens that account.
7. **Non-Gmail address (if available).** A Google account using a non-Gmail, non-Workspace email creates **no** account at first. `/login` shows «لم يُنشأ الحساب بعد…», and an email arrives at that address.
   - Opening its link in **another** browser creates nothing.
   - Opening it in the **same** browser creates the account, verified and linked.
8. **Linking needs ownership.** Register a local account with an address you control but do **not** verify it. Try to link a Google account with a different email: it is refused («لم يُربط حساب Google…»). Verify the email, then link again: it succeeds.
9. **Database and logs.** In the database, `oauth_identities` holds only the subject and timestamps, with no token columns. In `storage/logs`, there is no `code=` value from the callback.

## 5. Deployment on Plesk

`deploy.sh`, the Document Root and Plesk settings are **not** changed by this work. The deployment needs these steps, in this order:

1. **Back up** the database (Plesk "Export Dump" or `mariadb-dump`).
2. **Update the code** with the usual Plesk deployment.
3. **Install dependencies.** In `backend/`, run `composer install --no-dev --optimize-autoloader`. This adds the new runtime dependency `laravel/socialite` and its packages.
4. **Migrate.** In `backend/`, run `php artisan migrate --force`. Two additive migrations run:
   - `2026_10_27_200001_allow_passwordless_users` (`users.password` becomes nullable);
   - `2026_10_27_200002_create_oauth_identities_table`.

   Existing users, hashes and keys are unchanged.
5. **Build the frontend.** In `frontend/`, run `npm ci && npm run build`, and publish `dist/index.html` and `dist/assets/` into `backend/public/` as the deployment already does.
6. **Set the environment.** Put the §2–§4 values in the Plesk `.env`, then run `php artisan config:clear` (or `config:cache`) and `php artisan route:clear`.
7. **Check:**
   - `/up` → 200;
   - `/login`, `/register` and `/account` load directly and on refresh;
   - `/api/auth/options` → JSON;
   - `/no-such-page` → 404.

**Rollback.** `php artisan migrate:rollback` undoes only the latest batch. If any Google-only (passwordless) account exists, the rollback refuses **before changing anything**. `oauth_identities` rolls back first and makes the same check, so no table is dropped and no migration-history row is removed. Those owners must first set a password with "forgot password".

## 6. Content and policy still required (not invented here)

These are production requirements that this work deliberately leaves open:

- **Privacy policy.** No approved privacy policy page exists. Google's consent screen requires its public URL, and the registration page should link to it once approved.
- **Consent wording.** There is no legal consent or terms wording for account creation. None was added, and no checkbox exists until approved text is supplied.
- **Contact channel.** The disabled-account and support messages do not name a contact address. A public contact channel should be added when one is chosen.
- **Retention.** The retention period for inactive accounts and logs is not set.

## 7. Local development

```sh
# backend (uses backend/.env; MAIL_MAILER=log writes emails to storage/logs)
cd backend && php artisan migrate && php artisan serve          # http://127.0.0.1:8000

# frontend (Vite proxies /api, /sanctum, /auth/google and /email/verify to Laravel)
cd frontend && npm run dev                                      # http://localhost:5173
```

- **Dev server URL.** Open the site at `http://localhost:5173`. The proxy keeps the browser on one origin, so cookies and CSRF behave as in production. `localhost:5173` is in Sanctum's default stateful list.
- **Another backend address.** Set `VITE_BACKEND_URL` (for example in `frontend/.env.local`).
- **Google locally.** Use a development OAuth client with redirect `http://localhost:5173/auth/google/callback`, and set `GOOGLE_REDIRECT_URI` to the same value.

## 8. Tests

- **Backend feature tests.** Run `php artisan test` (SQLite, in memory). `tests/Feature/Auth/*` drive the API like the React app does: real cookies, real CSRF verification and no in-memory sign-in carried between requests (`tests/ActsAsSpaBrowser.php`). They cover:
  - registration, login, logout, session persistence and regeneration;
  - protected endpoints and disabled accounts;
  - Arabic validation messages and throttling;
  - safe redirects, verification emails and password reset (real `array` mail transport);
  - Google sign-in (mocked HTTP only; see §4), including account pre-hijacking regressions:
    - separate attacker and owner browsers;
    - linking refused for an unverified account;
    - owner recovery by password reset;
    - callbacks resumed after a password reset, after disabling the account, or after a change of account;
    - same-browser confirmation of unproven Google emails.
- **Schema tests.** These run on disposable MySQL / MariaDB databases (see [database-rules.md](database-rules.md) §7), and now include the two account migrations:
  - applying them on an already deployed platform schema;
  - rolling them back;
  - refusing to strand passwordless accounts, while keeping the identity table, its rows and both migration-history rows; the kept identity still signs in;
  - the uniqueness and CHECK constraints of `oauth_identities`.
