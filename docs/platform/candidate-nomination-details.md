# Stage 2: nomination category and work details («فئة الترشح وبيانات العمل»)

For the people who deploy, operate and review the platform. Related: [candidate-onboarding.md](candidate-onboarding.md) (Stage 1, the dashboard, account binding), [database-schema.md](database-schema.md) §L, [database-rules.md](database-rules.md).

**Scope.** After Stage 1 is confirmed, the candidate chooses one domain and one subcategory and describes the work: title, purpose and mission, objectives, impact, dates, places of implementation, funding declaration and optional partners. Confirming saves a **draft** application and makes these fields read-only for the candidate.

**What confirming does not do:**
- submit the application, create a submitted version, assign a reference number or set `first_submitted_at`;
- start eligibility review or judging;
- publish anything.

**Not added:** uploads, voting, field visits, scoring, administration screens and later stages.

## 1. Approved taxonomy and artwork

| Domain (code) | Shown as | Subcategories (codes) | Award artwork |
| --- | --- | --- | --- |
| `culture_and_arts` | الثقافة والفنون | `culture` الثقافة، `arts` الفنون | `domain-culture-and-arts.svg` |
| `heritage` | التراث | `tangible_heritage` التراث المادي، `intangible_heritage` التراث اللامادي | `domain-tangible-and-intangible-heritage.svg` |
| `creativity` | الإبداع | `social_responsibility` المسؤولية المجتمعية، `entrepreneurship` ريادة الأعمال، `quality` الجودة | `domain-volunteer-work.svg` (the former Volunteer Work award shape, approved by the owner) |

- **Source of truth:** the codes are defined in `App\Candidates\NominationTaxonomy`. Relationships come from codes, never from labels.
- **Artwork:** the original extracted SVGs are shown unchanged, with alt text «جائزة محور …». All three Creativity subcategories use the Creativity artwork.
- **No per-subcategory machinery:** subcategories have no award slots, rankings, committees or rubrics of their own.

## 2. Activation (operator workflow; there is no admin portal)

**Conditions for Stage 2 to be available:** exactly **one** cycle must be usable. A usable cycle:
- is published;
- has its `nomination_details` stage enabled, with now inside the stage window when one is set;
- has its implementation country configured (Syria, which has a vetted list of the 14 governorates);
- has at least one open approved domain with an active subcategory.

With none, or with more than one, the stage shows «غير متاحة حالياً». Nothing is chosen by default.

Run these in `backend/` on the server. `--actor` must be the email of an existing account, and every change is recorded under it in `audit_logs`. Check with `--dry-run` first: it performs every step in a transaction and rolls it back.

```sh
# 1. An existing cycle (preferred): name it by its code.
php artisan nomination:setup <cycle-code> --actor=<operator email> --country=SY --open-domains --dry-run

# 1'. Or create it as a draft. Every detail is explicit; nothing is invented.
php artisan nomination:setup <cycle-code> --actor=<operator email> --create-cycle \
    --name="<Arabic cycle name>" --award-name="<Arabic award name>" --year=<year> --timezone=Asia/Damascus \
    --country=SY --open-domains --dry-run

# 2. Apply (the same command without --dry-run). Add an optional window in the cycle timezone:
#    --opens-at="YYYY-MM-DD HH:MM" --closes-at="YYYY-MM-DD HH:MM"

# 3. Open the stage, and publish the cycle if it is still a draft:
php artisan nomination:setup <cycle-code> --actor=<operator email> --enable --publish-cycle

# Close it later:
php artisan nomination:setup <cycle-code> --actor=<operator email> --close
```

**What the command does:**
- **Taxonomy:** creates any of the three domains, their cycle domains and the seven subcategories that are missing. Existing records and identifiers are kept unchanged.
- **Country:** sets the cycle's `implementation_country` if none is set, and refuses to overwrite a different value.
- **Stage:** creates the `nomination_details` stage as `planned`; `--enable` and `--close` change its status.
- **Report:** prints every change and the resulting availability.

**What it never does:** create or publish a cycle without the explicit flags, invent a year, dates or submission rules, open final submission, create versions or award slots, or touch other stages.

**Closing during a candidate's session:** a candidate's open form is refused (`409 nomination_unavailable`) and keeps its input. A form opened while another cycle was the usable one is refused (`409 journey_changed`). Retries of an already-confirmed stage still return the stored result after closure.

**Preview data:** for local or test use, run the same command against a local or disposable database with an obviously test cycle code. Never run it with preview values against production.

## 3. The form and its rules

Five sections inside one form, all held in memory only:
1. «المحور والفئة»
2. «تعريف العمل وأثره»
3. «التواريخ وأماكن التنفيذ»
4. «التمويل والشراكات»
5. «المراجعة والتأكيد»

Each section is checked by the server (`POST /api/candidate/nomination-details/check`, which saves nothing). The final «حفظ المرحلة والمتابعة» is the only write. The server's `NominationDetailsRequest` is authoritative.

| Field | Rule |
| --- | --- |
| Domain, subcategory | Exactly one of each (strings), from the open domains and active subcategories of the usable cycle; the subcategory must belong to the chosen domain. Changing the domain clears the subcategory, after a warning. |
| Title / purpose and mission / objectives / impact | Required plain text: 255 / 3000 / 3000 / 4000 characters. Control characters are refused, line breaks kept, nothing truncated, nothing rendered as HTML. Objectives: one per line. Impact: concrete outcomes, beneficiaries and evidence, without required counters. |
| Dates | Start date required. The end date is required for completed work and refused for ongoing work («العمل مستمر» → `implementation_status = ongoing`, no end date). Real Gregorian dates, not in the future (today in Damascus), end not before start. Stored as `DATE` (no time-zone shift). No minimum age or duration. |
| Places | 1–20 rows: governorate (ISO 3166-2:SY code) required; city, town or village required (150); address optional (500). Country = the cycle's (Syria). Duplicates, ignoring case and spacing, are refused. No maps, geocoding or external services. |
| Funding («الإقرار المالي») | One of four choices, none preselected. **ممول بالكامل / ممول جزئيًا:** funder name + amount, which is the funding reported for that funder; no project cost or remainder is inferred. **تمويل شخصي:** self-funded amount only. **لا يحتاج تمويل:** nothing else. Fields that do not apply are refused if sent. |
| Amounts | US dollars only: digits with an optional point and up to 2 decimals, above 0, up to 12 integer digits. Arabic-Indic or Persian digits and the Arabic decimal sign (٫) are accepted. Grouping commas are refused rather than guessed. Stored as `DECIMAL(14,2)`; no floating point anywhere. |
| Partnerships | Yes or No (required). Yes needs 1–10 rows: partner name required (255), the nature of the partnership optional (1000). No sends and stores no rows; switching to No warns before clearing them. Partners are not accounts or applicants, and no contact or financial data is kept for them. |

## 4. Saving, the lock, and retries

`App\Candidates\NominationDetails::confirm` runs in one transaction:
1. lock the account's `users` row;
2. check Stage 1 again, any already-confirmed journey for the form's journey key, the usable cycle and the journey key;
3. read the stage and the chosen options under shared locks;
4. create the draft application, its places and partners, the `candidate_nominations` row and an audit entry (ids and codes only).

If anything fails, nothing is left behind.

**Idempotency:** `uq_candidate_nominations_journey` (one per candidate profile and cycle) backs it. A retry (double click, second tab, lost answer) returns the stored stage with `409 nomination_details_already_confirmed` and never overwrites it.

**Read-only afterwards:** there is no update or delete endpoint. Only Stage 2's own fields are fixed; the draft keeps its other columns for later stages.

**Journey identity.** The form receives a server-issued `journey_key` (an HMAC of the candidate profile and cycle). The application is the one this confirmation creates; no existing application is ever picked by "latest id" or attached. The journey table limits this dashboard journey only. `applications` gets no new unique key, and the application-count policy stays open.

**Uncertain saves.** A lost answer or a 5xx after saving is not reported as a failure. The form re-reads the account's stage:
- a stored stage is shown read-only;
- otherwise it says no saved data is visible yet and the result is unconfirmed (the request may still be running), keeps the input, and the leave warning says the same.

**Account binding.** As in Stage 1, reads carry `X-Candidate-Context`, and writes carry `account_context` plus the `journey_key`. A page or form showing another account than the one signed in is refused (`409 account_mismatch`) before anything is read or written.

## 5. API

| Method and path | Purpose |
| --- | --- |
| `GET /api/candidate/nomination-details` | The confirmed stage, or availability, options (domains, subcategories, artwork keys, governorates) and `journey_key`. `409 basic_information_required` before Stage 1. |
| `POST /api/candidate/nomination-details/check` | Checks one section (`domain`, `work`, `dates_locations`, `funding`); saves nothing. 30/min per account. |
| `POST /api/candidate/nomination-details` | Confirms the stage, once. 10/min per account. |
| `GET /api/candidate/overview` | Adds `nomination_details` (statuses and dates only). |

All candidate routes require a session, an active account, the candidate role, CSRF on writes and `Cache-Control: no-store`. Writes also pass `nomination.gate`, before validation. Places and funding appear only in the owner's `NominationDetailsResource`, never in `/api/user`, the overview or logs.

## 6. Deployment

1. Back up the database.
2. Run `php artisan migrate --force` for three additive migrations (`400001`–`400003`; schema §L). Existing applications are unchanged. Rollback is refused before anything changes while any Stage 2 data exists.
3. Build and publish the frontend as today.
4. Run the activation workflow (§2) for the real cycle.

No `.env`, Plesk, Document Root or `deploy.sh` change is needed.
