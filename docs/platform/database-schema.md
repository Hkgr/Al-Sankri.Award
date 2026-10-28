# Platform database schema dictionary

This document describes the database foundation of the digital award platform for **جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع**. It covers 36 logical domain tables: the existing `users` table, which this work extends, and 35 new tables. They are created by 12 migrations, `backend/database/migrations/2026_10_27_100001_*` to `…100012_*`.

Related documents:

- [database-erd.md](database-erd.md): Mermaid diagrams by module.
- [database-rules.md](database-rules.md): what the database enforces, what the application must enforce, and which business decisions remain open.

**Not present by design:** public voting, voters, an audience award, field visits and field verifiers. These were cancelled, and no table, column, status or permission for them exists.

Generated from the migrated schema on MySQL 8.4.7 (information_schema), then annotated. Regenerate it whenever a migration changes. MariaDB creates the same named constraints, keys and indexes. Its own differences in type names and CHECK formatting (for example `json` stored as `LONGTEXT` with a `json_valid()` check) are not repeated here.

## Contents

- **A. Accounts and permissions:** [`users`](#users), [`roles`](#roles), [`permissions`](#permissions), [`role_permissions`](#role_permissions), [`user_roles`](#user_roles), [`cycle_user_roles`](#cycle_user_roles)
- **B. Award cycles and domains:** [`award_cycles`](#award_cycles), [`cycle_stages`](#cycle_stages), [`award_domains`](#award_domains), [`cycle_domains`](#cycle_domains), [`award_slots`](#award_slots)
- **C. Applicants and applications:** [`applicants`](#applicants), [`applicant_members`](#applicant_members), [`applications`](#applications), [`application_locations`](#application_locations), [`application_versions`](#application_versions)
- **D. Files and consent:** [`files`](#files), [`file_links`](#file_links), [`policy_versions`](#policy_versions), [`application_consents`](#application_consents)
- **E. Eligibility and completion:** [`eligibility_reviews`](#eligibility_reviews), [`completion_requests`](#completion_requests)
- **F. Committees and judging:** [`committees`](#committees), [`committee_members`](#committee_members), [`evaluation_rubrics`](#evaluation_rubrics), [`evaluation_criteria`](#evaluation_criteria), [`judging_assignments`](#judging_assignments), [`conflict_declarations`](#conflict_declarations), [`evaluations`](#evaluations), [`evaluation_scores`](#evaluation_scores)
- **G. Appeals:** [`appeals`](#appeals), [`appeal_decisions`](#appeal_decisions)
- **H. Results and ratification:** [`award_results`](#award_results), [`result_ratifications`](#result_ratifications)
- **I. Public stories and auditing:** [`published_stories`](#published_stories), [`audit_logs`](#audit_logs)
- **J. External sign-in (account migrations):** [`oauth_identities`](#oauth_identities)
- **K. Candidate onboarding (stage 1):** [`account_policy_versions`](#account_policy_versions), [`account_policy_acceptances`](#account_policy_acceptances), [`candidate_profiles`](#candidate_profiles), [`applicant_individual_details`](#applicant_individual_details), [`applicant_institution_details`](#applicant_institution_details), [`applicant_social_links`](#applicant_social_links)
- **L. Candidate Stage 2 (nomination details):** [`cycle_domain_subcategories`](#cycle_domain_subcategories), [`applications` additions](#applications-stage-2-columns), [`application_partnerships`](#application_partnerships), [`candidate_nominations`](#candidate_nominations)
- **M. Candidate Stage 3 and final submission:** [`files` addition](#files-upload-operation), [`application_documentation_links`](#application_documentation_links), [`candidate_nominations` additions](#candidate_nominations-documentation-columns)

## Conventions

- **Engine and character set.** InnoDB, `utf8mb4` with `utf8mb4_unicode_ci` (the repository's configured collation).
- **Identifiers.** Every `id` is `bigint unsigned` auto-increment, and every foreign key column matches that type.
- **Time.** New date-time columns are `DATETIME` and hold **UTC** values written by the application (`app.timezone = UTC`).
  - `DATETIME` is not shifted by the MySQL session time zone and has no 2038 limit.
  - `audit_logs.occurred_at` keeps microseconds.
  - The baseline `users` timestamps keep their original `TIMESTAMP` type.
- **Calendar dates.** Dates without a time use `DATE` (implementation dates).
- **Numbers.** Scores, maxima and weights are `DECIMAL`, never floating point:
  - scores and maxima: `DECIMAL(6,2)`, up to 9999.99;
  - weights: `DECIMAL(7,4)`, a percentage of 0–100 with four decimals.
  - Coordinates are `DECIMAL(9,6)`.
- **Status and type codes.** Short `VARCHAR` codes whose allowed values are listed in a named CHECK constraint. There are no MySQL `ENUM`s. Adding a value later means one migration that drops and re-adds that CHECK.
- **JSON.** Used only for submission snapshots, structured questionnaires and checklists, explicitly variable configuration (cycle rules), score summaries and audit before/after data.
- **Row timestamps.**
  - Mutable records have `created_at` and `updated_at`.
  - Event and decision records have only their explicit occurrence time: `application_consents.accepted_at`, `appeal_decisions.issued_at`, `result_ratifications.decided_at`, `audit_logs.occurred_at`.
  - Immutable versions have `submitted_at` plus `created_at`.
- **Deletion.**
  - **Restrict:** every foreign key is `RESTRICT` unless listed below.
  - **Cascade:** only for disposable rows that cannot be historical evidence:
    - `role_permissions` (both sides);
    - `applicant_members` and `application_locations`, the live, editable rows of an applicant or draft (submitted content is preserved in version snapshots);
    - `evaluation_criteria` with its rubric (a rubric in use cannot be deleted anyway, because assignments and scores restrict it).
  - **Set null:** only `applicant_members.user_id`, so the member record survives without its account.
  - There is no blanket soft deletion.
- **Generated helper columns.**
  - `is_current`, `primary_flag` and `scope_key` are virtual columns used in unique keys. Each is `1` for the single row that must be unique, and `NULL` otherwise; InnoDB unique indexes allow any number of `NULL`s.
  - `role_scope` and `committee_type` are stored constant columns that let a composite foreign key require a particular role scope or committee type.
- **Constraint names.** Explicit and at most 64 characters: `uq_` unique, `idx_` index, `fk_` foreign key, `chk_` check.
- **Value lengths.**
  - codes: 16–100;
  - names and titles: 150–255;
  - short reasons: 500–1000;
  - longer text: `TEXT` or `MEDIUMTEXT`;
  - phone numbers: 32 (E.164 plus formatting);
  - country codes: `CHAR(2)` (ISO 3166-1 alpha-2, uppercase);
  - SHA-256 values: `CHAR(64)` (lowercase hex);
  - file paths: 500 (the `(storage_disk, storage_path)` unique key stays under InnoDB's 3072-byte limit).

---

## A. Accounts and permissions

### `users`

Existing Laravel login accounts, **extended** (not recreated) by `2026_10_27_100001_extend_users_for_platform_accounts`. The columns marked *new* are added by that migration and removed on rollback; existing rows keep working (`status` defaults to `active`). Passwords stay hashed in the existing `password` column. A phone number is **not** a unique identity.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `name` | `varchar(255)` | no | — |  |
| `email` | `varchar(255)` | no | — |  |
| `phone` | `varchar(32)` | yes | — | *new.* Optional contact number, not unique. |
| `phone_verified_at` | `datetime` | yes | — | *new.* When the phone number was verified. |
| `email_verified_at` | `timestamp` | yes | — |  |
| `password` | `varchar(255)` | yes | — | Existing. Hashed by the framework; never plaintext. NULL only for accounts created with Google (`…200001_allow_passwordless_users`); never a shared dummy value. |
| `status` | `varchar(16)` | no | `active` | *new.* `active` or `disabled`. |
| `disabled_at` | `datetime` | yes | — | *new.* Set if and only if `status = disabled`. |
| `disabled_reason` | `varchar(500)` | yes | — | *new.* Why the account was disabled. |
| `last_login_at` | `datetime` | yes | — | *new.* Last successful sign-in (UTC). |
| `remember_token` | `varchar(100)` | yes | — |  |
| `created_at` | `timestamp` | yes | — |  |
| `updated_at` | `timestamp` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `users_email_unique` (`email`)
- Index `idx_users_phone` (`phone`)
- Index `idx_users_status` (`status`)

**CHECK constraints**

- `chk_users_disabled`: `((status = 'disabled') = (disabled_at is not null))`
- `chk_users_status`: `(status in ('active','disabled'))`

### `roles`

Role catalogue. `scope` separates platform-wide roles (granted in `user_roles`) from cycle-scoped roles (granted in `cycle_user_roles`). Nothing is seeded; migrations provision `candidate` (platform), `award_admin` (platform, with `applications.view_submitted` and `committees.manage_judging`) and `judge` (cycle), see [candidate-onboarding.md](candidate-onboarding.md) and [administration.md](administration.md).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `code` | `varchar(64)` | no | — | Stable machine code. |
| `name_ar` | `varchar(150)` | no | — |  |
| `description` | `text` | yes | — |  |
| `scope` | `varchar(16)` | no | — | `platform` or `cycle`. |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_roles_code` (`code`)
- **Unique** `uq_roles_id_scope` (`id`, `scope`)
- Index `idx_roles_scope` (`scope`)

**CHECK constraints**

- `chk_roles_scope`: `(scope in ('platform','cycle'))`

### `permissions`

Permission catalogue grouped by `module`. No permissions are seeded, and none exist for voting or field visits.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `code` | `varchar(100)` | no | — | Stable machine code, e.g. `applications.review`. |
| `name_ar` | `varchar(150)` | no | — |  |
| `module` | `varchar(64)` | no | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_permissions_code` (`code`)
- Index `idx_permissions_module` (`module`)

### `role_permissions`

Which permissions a role carries. A disposable association: rows cascade with either side.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `role_id` | `bigint unsigned` | no | — |  |
| `permission_id` | `bigint unsigned` | no | — |  |
| `created_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_role_permissions_pair` (`role_id`, `permission_id`)
- Index `fk_role_permissions_permission` (`permission_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_role_permissions_permission` | (permission_id) | `permissions` (id) | CASCADE |
| `fk_role_permissions_role` | (role_id) | `roles` (id) | CASCADE |

### `user_roles`

Platform-wide role grants with an audit trail. **Strategy against duplicate effective grants:** `is_current` is `1` while `revoked_at` is null, and `(user_id, role_id, is_current)` is unique. So there is at most one unrevoked grant per user and role, while any number of revoked grants remain as history. The composite key `(role_id, role_scope)` → `roles (id, scope)`, with `role_scope` fixed to `platform`, rejects cycle-scoped roles.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `user_id` | `bigint unsigned` | no | — |  |
| `role_id` | `bigint unsigned` | no | — |  |
| `role_scope` | `varchar(16)` | yes | generated (stored): `'platform'` | Always `platform`; part of the role foreign key. |
| `assigned_by` | `bigint unsigned` | yes | — | Null for system assignments. |
| `assigned_at` | `datetime` | no | — |  |
| `revoked_at` | `datetime` | yes | — |  |
| `revoked_by` | `bigint unsigned` | yes | — | Refinement: who revoked the grant (required when revoked). |
| `is_current` | `tinyint unsigned` | yes | generated (virtual): `(case when (revoked_at is null) then 1 end)` | Helper for the one-effective-grant unique key. |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_user_roles_current` (`user_id`, `role_id`, `is_current`)
- Index `fk_user_roles_assigned_by` (`assigned_by`)
- Index `fk_user_roles_revoked_by` (`revoked_by`)
- Index `fk_user_roles_role` (`role_id`, `role_scope`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_user_roles_assigned_by` | (assigned_by) | `users` (id) | RESTRICT |
| `fk_user_roles_revoked_by` | (revoked_by) | `users` (id) | RESTRICT |
| `fk_user_roles_role` | (role_id, role_scope) | `roles` (id, scope) | RESTRICT |
| `fk_user_roles_user` | (user_id) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_user_roles_revoked`: `((revoked_at is null) or (revoked_at >= assigned_at))`
- `chk_user_roles_revoked_by`: `((revoked_at is null) or (revoked_by is not null))`

### `cycle_user_roles`

Role grants that apply inside one award cycle (for example a cycle coordinator or eligibility reviewer). These are kept apart from global roles: `role_scope` is fixed to `cycle` in the role foreign key. At most one unrevoked grant per cycle, user and role. `starts_at`/`ends_at` bound the effective window; revocation keeps the row.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `user_id` | `bigint unsigned` | no | — |  |
| `role_id` | `bigint unsigned` | no | — |  |
| `role_scope` | `varchar(16)` | yes | generated (stored): `'cycle'` | Always `cycle`; part of the role foreign key. |
| `assigned_by` | `bigint unsigned` | yes | — |  |
| `starts_at` | `datetime` | no | — |  |
| `ends_at` | `datetime` | yes | — | Null means open-ended within the cycle. |
| `revoked_at` | `datetime` | yes | — |  |
| `revoked_by` | `bigint unsigned` | yes | — | Refinement: who revoked the grant. |
| `revocation_reason` | `varchar(1000)` | yes | — | Required when revoked. |
| `is_current` | `tinyint unsigned` | yes | generated (virtual): `(case when (revoked_at is null) then 1 end)` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_cycle_user_roles_current` (`cycle_id`, `user_id`, `role_id`, `is_current`)
- Index `fk_cycle_user_roles_assigned_by` (`assigned_by`)
- Index `fk_cycle_user_roles_revoked_by` (`revoked_by`)
- Index `fk_cycle_user_roles_role` (`role_id`, `role_scope`)
- Index `idx_cycle_user_roles_user` (`user_id`, `cycle_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_cycle_user_roles_assigned_by` | (assigned_by) | `users` (id) | RESTRICT |
| `fk_cycle_user_roles_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_cycle_user_roles_revoked_by` | (revoked_by) | `users` (id) | RESTRICT |
| `fk_cycle_user_roles_role` | (role_id, role_scope) | `roles` (id, scope) | RESTRICT |
| `fk_cycle_user_roles_user` | (user_id) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_cycle_user_roles_revoked`: `((revoked_at is null) or ((revoked_by is not null) and (revocation_reason is not null)))`
- `chk_cycle_user_roles_window`: `((ends_at is null) or (ends_at > starts_at))`

---

## B. Award cycles and domains

### `award_cycles`

One annual award edition. `award_name_ar` records the award name used in that cycle (currently جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع); no row is seeded. **Unresolved submission policies stay unset:** `submission_rules` and `eligibility_rules` are null until decided, with no defaults. Their future keys are listed in database-rules.md.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `code` | `varchar(32)` | no | — |  |
| `name_ar` | `varchar(255)` | no | — |  |
| `award_name_ar` | `varchar(255)` | no | — |  |
| `year` | `smallint unsigned` | no | — |  |
| `timezone` | `varchar(64)` | no | — | IANA zone used to display and interpret stage deadlines, e.g. `Asia/Damascus`. Stored times remain UTC. |
| `status` | `varchar(16)` | no | `draft` | `draft`, `published`, `closed`, `archived`. |
| `eligibility_rules` | `json` | yes | — | Explicitly variable configuration (for example the cycle's geographic scope). Null = not yet configured. |
| `submission_rules` | `json` | yes | — | Explicitly variable configuration (for example application limits or third-party nominations). Null = undecided. Never defaulted. |
| `published_at` | `datetime` | yes | — |  |
| `archived_at` | `datetime` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_award_cycles_code` (`code`)
- Index `idx_award_cycles_status_year` (`status`, `year`)

**CHECK constraints**

- `chk_award_cycles_archived`: `((status = 'archived') = (archived_at is not null))`
- `chk_award_cycles_published`: `((status not in ('published','closed')) or (published_at is not null))`
- `chk_award_cycles_status`: `(status in ('draft','published','closed','archived'))`

### `cycle_stages`

Named stages of a cycle (for example introductory sessions, submissions, eligibility, judging, appeals, ratification, award week). **Stages may overlap.** Each has its own window and status, so the cycle is not modelled as one exclusive stage. Stage codes are data, not schema.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `code` | `varchar(64)` | no | — |  |
| `name_ar` | `varchar(150)` | no | — |  |
| `opens_at` | `datetime` | yes | — |  |
| `closes_at` | `datetime` | yes | — |  |
| `status` | `varchar(16)` | no | `planned` | `planned`, `enabled`, `closed`, `cancelled`. |
| `display_order` | `smallint unsigned` | no | `0` |  |
| `enabled_by` | `bigint unsigned` | yes | — | Required once the stage is enabled. |
| `enabled_at` | `datetime` | yes | — |  |
| `last_change_reason` | `varchar(1000)` | yes | — | Why the stage window or status last changed (full history in `audit_logs`). |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_cycle_stages_code` (`cycle_id`, `code`)
- Index `fk_cycle_stages_enabled_by` (`enabled_by`)
- Index `idx_cycle_stages_status` (`cycle_id`, `status`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_cycle_stages_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_cycle_stages_enabled_by` | (enabled_by) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_cycle_stages_enabled`: `((status <> 'enabled') or ((enabled_at is not null) and (enabled_by is not null)))`
- `chk_cycle_stages_status`: `(status in ('planned','enabled','closed','cancelled'))`
- `chk_cycle_stages_window`: `((opens_at is null) or (closes_at is null) or (closes_at > opens_at))`

### `award_domains`

Catalogue of award domains. The three confirmed domains (الثقافة والفنون، التراث، الإبداع; codes `culture_and_arts`, `heritage`, `creativity`) are entered as data: no migration seeds them, and `php artisan nomination:setup` provisions them for an explicitly named cycle. Their confirmed subcategories live in `cycle_domain_subcategories` (§L).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `code` | `varchar(64)` | no | — |  |
| `name_ar` | `varchar(150)` | no | — |  |
| `description` | `text` | yes | — |  |
| `is_active` | `tinyint(1)` | no | `1` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_award_domains_code` (`code`)

### `cycle_domains`

A domain as defined for one cycle: its name, description, eligibility requirements and supplementary questionnaire. `(id, cycle_id)` is the parent key for composite foreign keys that must stay inside one cycle.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `domain_id` | `bigint unsigned` | no | — |  |
| `name_ar` | `varchar(150)` | no | — |  |
| `description` | `text` | yes | — |  |
| `eligibility_requirements` | `json` | yes | — | Structured, cycle-specific requirements (JSON). |
| `supplementary_questions` | `json` | yes | — | Structured questionnaire definition (JSON); answers go to `applications.supplementary_answers`. |
| `display_order` | `smallint unsigned` | no | `0` |  |
| `is_open_for_applications` | `tinyint(1)` | no | `0` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_cycle_domains_id_cycle` (`id`, `cycle_id`)
- **Unique** `uq_cycle_domains_pair` (`cycle_id`, `domain_id`)
- Index `fk_cycle_domains_domain` (`domain_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_cycle_domains_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_cycle_domains_domain` | (domain_id) | `award_domains` (id) | RESTRICT |

### `award_slots`

The individual jury awards of a cycle domain (the planned allocation is 2 + 2 + 3). `slot_number` identifies a slot within its domain; **it is not a rank or place**. Award counts are data: no constraint fixes how many slots a domain has. There is no audience-award type.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_domain_id` | `bigint unsigned` | no | — |  |
| `code` | `varchar(64)` | no | — |  |
| `name_ar` | `varchar(150)` | no | — |  |
| `slot_number` | `smallint unsigned` | no | — | Identifier within the domain (≥ 1); not a ranking. |
| `description` | `text` | yes | — |  |
| `is_enabled` | `tinyint(1)` | no | `1` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_award_slots_code` (`cycle_domain_id`, `code`)
- **Unique** `uq_award_slots_id_domain` (`id`, `cycle_domain_id`)
- **Unique** `uq_award_slots_number` (`cycle_domain_id`, `slot_number`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_award_slots_cycle_domain` | (cycle_domain_id) | `cycle_domains` (id) | RESTRICT |

**CHECK constraints**

- `chk_award_slots_number`: `(slot_number >= 1)`

---

## C. Applicants and applications

### `applicants`

The applying entity: an individual, team, initiative, official institution or organization. **An applicant is not a login account.** The representative account manages it, and members are listed separately. Registration fields are nullable because they do not apply to every type.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `type` | `varchar(32)` | no | — | `individual`, `team`, `initiative`, `official_institution`, `organization`. |
| `name_ar` | `varchar(255)` | no | — |  |
| `representative_user_id` | `bigint unsigned` | no | — | The accountable account that manages the applicant. |
| `contact_email` | `varchar(255)` | yes | — |  |
| `contact_phone` | `varchar(32)` | yes | — |  |
| `headquarters_country_code` | `char(2)` | yes | — | ISO 3166-1 alpha-2, uppercase. |
| `headquarters_governorate` | `varchar(100)` | yes | — |  |
| `headquarters_address` | `varchar(500)` | yes | — |  |
| `registration_number` | `varchar(100)` | yes | — |  |
| `registration_authority` | `varchar(255)` | yes | — |  |
| `description` | `text` | yes | — |  |
| `status` | `varchar(16)` | no | `active` | `active`, `suspended`, `archived`. |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- Index `fk_applicants_representative` (`representative_user_id`)
- Index `idx_applicants_type_status` (`type`, `status`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_applicants_representative` | (representative_user_id) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_applicants_country`: `((headquarters_country_code is null) or ((char_length(headquarters_country_code) = 2) and (ascii(substr(headquarters_country_code,1,1)) between 65 and 90) and (ascii(substr(headquarters_country_code,2,1)) between 65 and 90)))`
- `chk_applicants_status`: `(status in ('active','suspended','archived'))`
- `chk_applicants_type`: `(type in ('individual','team','initiative','official_institution','organization'))`

### `applicant_members`

People who belong to an applicant. `user_id` is optional (members without accounts). **Membership grants no editing rights**; those are an application-layer permission. At most one primary contact per applicant (`primary_flag`).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `applicant_id` | `bigint unsigned` | no | — |  |
| `user_id` | `bigint unsigned` | yes | — | Optional linked account; set to null if that account is deleted. |
| `full_name` | `varchar(255)` | no | — |  |
| `role_title` | `varchar(150)` | yes | — |  |
| `email` | `varchar(255)` | yes | — |  |
| `phone` | `varchar(32)` | yes | — |  |
| `is_primary_contact` | `tinyint(1)` | no | `0` |  |
| `primary_flag` | `tinyint unsigned` | yes | generated (virtual): `(case when is_primary_contact then 1 end)` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_applicant_members_primary` (`applicant_id`, `primary_flag`)
- **Unique** `uq_applicant_members_user` (`applicant_id`, `user_id`)
- Index `idx_applicant_members_user` (`user_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_applicant_members_applicant` | (applicant_id) | `applicants` (id) | CASCADE |
| `fk_applicant_members_user` | (user_id) | `users` (id) | SET NULL |

### `applications`

An application to one cycle domain. Drafts may be incomplete, so content columns are nullable. There is **no** one-application-per-applicant rule, because that policy is unresolved. `cycle_id` is a refinement: it is kept consistent with the domain by `fk_applications_cycle_domain`, so appeals, assignments and results can prove they belong to the same cycle and domain.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `cycle_domain_id` | `bigint unsigned` | no | — |  |
| `applicant_id` | `bigint unsigned` | no | — |  |
| `created_by` | `bigint unsigned` | no | — |  |
| `reference_number` | `varchar(32)` | yes | — | Unique public reference; null until assigned (drafts). |
| `title` | `varchar(255)` | yes | — |  |
| `summary` | `text` | yes | — |  |
| `description` | `mediumtext` | yes | — |  |
| `problem_statement` | `text` | yes | — |  |
| `implementation_started_on` | `date` | yes | — |  |
| `implementation_ended_on` | `date` | yes | — |  |
| `implementation_status` | `varchar(16)` | yes | — | `ongoing`, `completed`, `suspended`. |
| `target_groups` | `text` | yes | — |  |
| `documented_impact` | `mediumtext` | yes | — |  |
| `sustainability_plan` | `text` | yes | — |  |
| `governance_description` | `text` | yes | — |  |
| `supplementary_answers` | `json` | yes | — | Answers to the cycle domain's questionnaire (JSON). |
| `status` | `varchar(32)` | no | `draft` | `draft`, `submitted`, `completion_requested`, `eligible`, `ineligible`, `in_judging`, `withdrawn`, `closed`. Results live in `award_results`, not here. |
| `current_version_id` | `bigint unsigned` | yes | — | Latest submitted version; must belong to this application (`fk_applications_current_version`, added in the deferred-key migration). |
| `first_submitted_at` | `datetime` | yes | — | Required, with `current_version_id`, for every non-draft status. |
| `withdrawn_at` | `datetime` | yes | — |  |
| `withdrawal_reason` | `varchar(1000)` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_applications_id_cycle` (`id`, `cycle_id`)
- **Unique** `uq_applications_id_domain` (`id`, `cycle_domain_id`)
- **Unique** `uq_applications_reference` (`reference_number`)
- Index `fk_applications_created_by` (`created_by`)
- Index `fk_applications_cycle` (`cycle_id`)
- Index `fk_applications_cycle_domain` (`cycle_domain_id`, `cycle_id`)
- Index `idx_applications_applicant_cycle` (`applicant_id`, `cycle_id`)
- Index `idx_applications_current_version` (`current_version_id`, `id`)
- Index `idx_applications_domain_status` (`cycle_domain_id`, `status`, `first_submitted_at`)
- Index `idx_applications_status_submitted` (`status`, `first_submitted_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_applications_applicant` | (applicant_id) | `applicants` (id) | RESTRICT |
| `fk_applications_created_by` | (created_by) | `users` (id) | RESTRICT |
| `fk_applications_current_version` | (current_version_id, id) | `application_versions` (id, application_id) | RESTRICT |
| `fk_applications_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_applications_cycle_domain` | (cycle_domain_id, cycle_id) | `cycle_domains` (id, cycle_id) | RESTRICT |

**CHECK constraints**

- `chk_applications_impl_dates`: `((implementation_started_on is null) or (implementation_ended_on is null) or (implementation_ended_on >= implementation_started_on))`
- `chk_applications_impl_status`: `((implementation_status is null) or (implementation_status in ('ongoing','completed','suspended')))`
- `chk_applications_status`: `(status in ('draft','submitted','completion_requested','eligible','ineligible','in_judging','withdrawn','closed'))`
- `chk_applications_submitted`: `((status = 'draft') or ((first_submitted_at is not null) and (current_version_id is not null)))`
- `chk_applications_withdrawn`: `((status = 'withdrawn') = (withdrawn_at is not null))`

### `application_locations`

Implementation locations. An application may have several, at most one primary. Any country is storable; the current cycle's geographic scope (Syria) is enforced later by eligibility rules, not by the schema. Coordinates are optional but must come as a valid pair.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_id` | `bigint unsigned` | no | — |  |
| `country_code` | `char(2)` | no | — | ISO 3166-1 alpha-2, uppercase. |
| `governorate` | `varchar(100)` | yes | — |  |
| `district` | `varchar(100)` | yes | — |  |
| `locality` | `varchar(150)` | yes | — |  |
| `address` | `varchar(500)` | yes | — |  |
| `is_primary` | `tinyint(1)` | no | `0` |  |
| `latitude` | `decimal(9,6)` | yes | — |  |
| `longitude` | `decimal(9,6)` | yes | — |  |
| `primary_flag` | `tinyint unsigned` | yes | generated (virtual): `(case when is_primary then 1 end)` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_application_locations_primary` (`application_id`, `primary_flag`)
- Index `idx_application_locations_area` (`country_code`, `governorate`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_application_locations_application` | (application_id) | `applications` (id) | CASCADE |

**CHECK constraints**

- `chk_application_locations_coords`: `((latitude is null) = (longitude is null))`
- `chk_application_locations_country`: `((char_length(country_code) = 2) and (ascii(substr(country_code,1,1)) between 65 and 90) and (ascii(substr(country_code,2,1)) between 65 and 90))`
- `chk_application_locations_lat`: `((latitude is null) or (latitude between -(90) and 90))`
- `chk_application_locations_lng`: `((longitude is null) or (longitude between -(180) and 180))`

### `application_versions`

Immutable submitted versions. Snapshots preserve the applicant, members, locations, answers and the form definition exactly as submitted. Judging, eligibility, consent, results and stories always reference a version, never the mutable draft. Immutability is an application-layer and permissions rule (see database-rules.md); there is no `updated_at`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_id` | `bigint unsigned` | no | — |  |
| `version_number` | `int unsigned` | no | — |  |
| `submission_type` | `varchar(32)` | no | — | `initial` (version 1) or `completion_response`. |
| `completion_request_id` | `bigint unsigned` | yes | — | The completion request this version answers, required for `completion_response`. It must belong to the same application (deferred composite key). |
| `submitted_by` | `bigint unsigned` | no | — |  |
| `submitted_at` | `datetime` | no | — |  |
| `data_snapshot` | `json` | no | — | Submitted applicant, members, locations, answers and file references (JSON). |
| `form_snapshot` | `json` | no | — | Form/questionnaire definition shown to the applicant (JSON). |
| `content_hash` | `char(64)` | no | — | SHA-256 (lowercase hex) of the canonical snapshot. |
| `created_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_application_versions_id_application` (`id`, `application_id`)
- **Unique** `uq_application_versions_number` (`application_id`, `version_number`)
- Index `fk_application_versions_submitted_by` (`submitted_by`)
- Index `idx_application_versions_completion` (`completion_request_id`, `application_id`)
- Index `idx_application_versions_submitted` (`submitted_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_application_versions_application` | (application_id) | `applications` (id) | RESTRICT |
| `fk_application_versions_completion` | (completion_request_id, application_id) | `completion_requests` (id, application_id) | RESTRICT |
| `fk_application_versions_submitted_by` | (submitted_by) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_application_versions_completion`: `((submission_type = 'completion_response') = (completion_request_id is not null))`
- `chk_application_versions_first`: `((version_number <> 1) or (submission_type = 'initial'))`
- `chk_application_versions_hash`: `((char_length(content_hash) = 64) and (cast(content_hash as char charset binary) = cast(lower(content_hash) as char charset binary)) and (unhex(content_hash) is not null))`
- `chk_application_versions_number`: `(version_number >= 1)`
- `chk_application_versions_type`: `(submission_type in ('initial','completion_response'))`

---

## D. Files and consent

### `files`

Stored files. **Private by default.** The storage location `(storage_disk, storage_path)` is unique; the SHA-256 is indexed but **not** unique, so identical uploads keep separate ownership and lifecycle. A public derivative points at its private source through `source_file_id`. **Never store signed or temporary URLs**: a CHECK rejects paths containing `://` or `?`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `uploaded_by` | `bigint unsigned` | yes | — | Null for system-generated files (for example derivatives). |
| `storage_disk` | `varchar(32)` | no | — |  |
| `storage_path` | `varchar(500)` | no | — |  |
| `original_name` | `varchar(255)` | no | — |  |
| `detected_mime_type` | `varchar(127)` | no | — | Type detected from content, not the client-supplied type. |
| `size_bytes` | `bigint unsigned` | no | — |  |
| `sha256` | `char(64)` | no | — |  |
| `scan_status` | `varchar(16)` | no | `pending` | `pending`, `clean`, `infected`, `error`; `scanned_at` is set if and only if the status is not pending. |
| `scanned_at` | `datetime` | yes | — |  |
| `access_level` | `varchar(16)` | no | `private` | `private` or `public`. |
| `source_file_id` | `bigint unsigned` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_files_location` (`storage_disk`, `storage_path`)
- Index `fk_files_source` (`source_file_id`)
- Index `idx_files_owner` (`uploaded_by`, `created_at`)
- Index `idx_files_scan` (`scan_status`, `created_at`)
- Index `idx_files_sha256` (`sha256`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_files_source` | (source_file_id) | `files` (id) | RESTRICT |
| `fk_files_uploaded_by` | (uploaded_by) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_files_access_level`: `(access_level in ('private','public'))`
- `chk_files_path`: `((not((storage_path like '%://%'))) and (not((storage_path like '%?%'))))`
- `chk_files_scan_status`: `(scan_status in ('pending','clean','infected','error'))`
- `chk_files_scanned_at`: `((scan_status = 'pending') = (scanned_at is null))`
- `chk_files_sha256`: `((char_length(sha256) = 64) and (cast(sha256 as char charset binary) = cast(lower(sha256) as char charset binary)) and (unhex(sha256) is not null))`

### `file_links`

Attaches a file to **exactly one** target: a draft application, a submitted version, an appeal or a published story. Each target is a real nullable foreign key, and `chk_file_links_one_target` requires exactly one. A file cannot be linked twice to the same target (one unique key per target). There are no voting or field-visit targets.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `file_id` | `bigint unsigned` | no | — |  |
| `application_id` | `bigint unsigned` | yes | — |  |
| `application_version_id` | `bigint unsigned` | yes | — |  |
| `appeal_id` | `bigint unsigned` | yes | — | Foreign key added in the deferred-key migration. |
| `published_story_id` | `bigint unsigned` | yes | — | Foreign key added in the deferred-key migration; public media only. |
| `purpose` | `varchar(64)` | no | — | Free code such as `evidence`, `report`, `photo`, `video`, `public_media`. |
| `caption` | `varchar(500)` | yes | — |  |
| `display_order` | `smallint unsigned` | no | `0` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_file_links_appeal` (`file_id`, `appeal_id`)
- **Unique** `uq_file_links_application` (`file_id`, `application_id`)
- **Unique** `uq_file_links_story` (`file_id`, `published_story_id`)
- **Unique** `uq_file_links_version` (`file_id`, `application_version_id`)
- Index `idx_file_links_appeal` (`appeal_id`)
- Index `idx_file_links_application` (`application_id`, `purpose`)
- Index `idx_file_links_story` (`published_story_id`)
- Index `idx_file_links_version` (`application_version_id`, `purpose`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_file_links_appeal` | (appeal_id) | `appeals` (id) | RESTRICT |
| `fk_file_links_application` | (application_id) | `applications` (id) | RESTRICT |
| `fk_file_links_file` | (file_id) | `files` (id) | RESTRICT |
| `fk_file_links_story` | (published_story_id) | `published_stories` (id) | RESTRICT |
| `fk_file_links_version` | (application_version_id) | `application_versions` (id) | RESTRICT |

**CHECK constraints**

- `chk_file_links_one_target`: `(((((application_id is not null) + (application_version_id is not null)) + (appeal_id is not null)) + (published_story_id is not null)) = 1)`

### `policy_versions`

Exact, versioned text of each consent policy for a cycle, preserving what applicants accepted. Expected codes: `truthful_data`, `representation`, `media_use`, `publication` (there is no field-visit consent). Published text must not be edited; publish a new version instead.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `code` | `varchar(64)` | no | — |  |
| `version_number` | `int unsigned` | no | — |  |
| `title_ar` | `varchar(255)` | no | — |  |
| `body_ar` | `mediumtext` | no | — |  |
| `status` | `varchar(16)` | no | `draft` | `draft`, `published`, `retired`. |
| `published_at` | `datetime` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_policy_versions_version` (`cycle_id`, `code`, `version_number`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_policy_versions_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |

**CHECK constraints**

- `chk_policy_versions_number`: `(version_number >= 1)`
- `chk_policy_versions_published`: `((status = 'draft') or (published_at is not null))`
- `chk_policy_versions_status`: `(status in ('draft','published','retired'))`

### `application_consents`

One acceptance of one policy version for one submitted application version: an event record with no update timestamps. Duplicate acceptances are rejected. `(id, application_version_id)` lets a published story prove that its publication consent belongs to the same version.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_version_id` | `bigint unsigned` | no | — |  |
| `policy_version_id` | `bigint unsigned` | no | — |  |
| `accepted_by` | `bigint unsigned` | no | — |  |
| `accepted_at` | `datetime` | no | — |  |
| `representative_capacity` | `varchar(150)` | yes | — | Capacity in which the representative accepted, e.g. on behalf of an organization. |

**Indexes** (besides the primary key)

- **Unique** `uq_application_consents_id_version` (`id`, `application_version_id`)
- **Unique** `uq_application_consents_policy` (`application_version_id`, `policy_version_id`)
- Index `fk_application_consents_accepted_by` (`accepted_by`)
- Index `fk_application_consents_policy` (`policy_version_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_application_consents_accepted_by` | (accepted_by) | `users` (id) | RESTRICT |
| `fk_application_consents_policy` | (policy_version_id) | `policy_versions` (id) | RESTRICT |
| `fk_application_consents_version` | (application_version_id) | `application_versions` (id) | RESTRICT |

---

## E. Eligibility and completion

### `eligibility_reviews`

Eligibility checks of a submitted version against a checklist version. Reviews are **repeatable**: each review is a new row and earlier decisions are never overwritten. An unfinished review has no decision and no `reviewed_at`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_version_id` | `bigint unsigned` | no | — |  |
| `reviewer_user_id` | `bigint unsigned` | no | — |  |
| `checklist_version` | `varchar(32)` | no | — |  |
| `checklist_answers` | `json` | yes | — | Structured checklist answers (JSON). |
| `decision` | `varchar(16)` | yes | — | `eligible`, `ineligible`, `incomplete`; a reason is required unless eligible. |
| `reason` | `text` | yes | — |  |
| `reviewed_at` | `datetime` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- Index `idx_eligibility_reviews_reviewer` (`reviewer_user_id`, `reviewed_at`)
- Index `idx_eligibility_reviews_version` (`application_version_id`, `reviewed_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_eligibility_reviews_reviewer` | (reviewer_user_id) | `users` (id) | RESTRICT |
| `fk_eligibility_reviews_version` | (application_version_id) | `application_versions` (id) | RESTRICT |

**CHECK constraints**

- `chk_eligibility_reviews_decision`: `((decision is null) or (decision in ('eligible','ineligible','incomplete')))`
- `chk_eligibility_reviews_reason`: `((decision is null) or (decision = 'eligible') or (reason is not null))`
- `chk_eligibility_reviews_reviewed`: `((decision is null) = (reviewed_at is null))`

### `completion_requests`

Requests for missing items, based on one submitted version and answered by a later version. Both versions must belong to the request's application (composite keys). The response is absent while the request is open.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_id` | `bigint unsigned` | no | — |  |
| `based_on_version_id` | `bigint unsigned` | no | — |  |
| `requested_by` | `bigint unsigned` | no | — |  |
| `message` | `text` | no | — |  |
| `requested_items` | `json` | yes | — | Structured list of requested items (JSON). |
| `due_at` | `datetime` | yes | — |  |
| `status` | `varchar(16)` | no | `open` | `open`, `answered`, `expired`, `cancelled`. `closed_at` is set for every status except open; `response_version_id` is set if and only if answered. |
| `response_version_id` | `bigint unsigned` | yes | — |  |
| `closed_at` | `datetime` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_completion_requests_id_application` (`id`, `application_id`)
- Index `fk_completion_requests_based_on` (`based_on_version_id`, `application_id`)
- Index `fk_completion_requests_requested_by` (`requested_by`)
- Index `fk_completion_requests_response` (`response_version_id`, `application_id`)
- Index `idx_completion_requests_application` (`application_id`, `status`)
- Index `idx_completion_requests_due` (`status`, `due_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_completion_requests_application` | (application_id) | `applications` (id) | RESTRICT |
| `fk_completion_requests_based_on` | (based_on_version_id, application_id) | `application_versions` (id, application_id) | RESTRICT |
| `fk_completion_requests_requested_by` | (requested_by) | `users` (id) | RESTRICT |
| `fk_completion_requests_response` | (response_version_id, application_id) | `application_versions` (id, application_id) | RESTRICT |

**CHECK constraints**

- `chk_completion_requests_closed`: `((status = 'open') = (closed_at is null))`
- `chk_completion_requests_distinct`: `((response_version_id is null) or (response_version_id <> based_on_version_id))`
- `chk_completion_requests_response`: `((status = 'answered') = (response_version_id is not null))`
- `chk_completion_requests_status`: `(status in ('open','answered','expired','cancelled'))`

---

## F. Committees and judging

### `committees`

Committees of a cycle:

- **judging:** one per cycle domain, appointed separately for each domain.
- **integrity/appeals:** one per cycle, with no domain.
- **trustees:** one per cycle, with no domain.

The domain must be a domain of the same cycle (`fk_committees_cycle_domain`). One current (not dissolved) committee per cycle, type and domain is enforced through `scope_key` and `is_current`. The **3-or-5 active judges** rule applies when the committee is activated; it is an application-layer rule, because a row-level CHECK cannot count other rows.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `cycle_domain_id` | `bigint unsigned` | yes | — |  |
| `type` | `varchar(16)` | no | — | `judging`, `integrity`, `trustees`. A domain is required for judging and forbidden otherwise. |
| `name_ar` | `varchar(255)` | no | — |  |
| `status` | `varchar(16)` | no | `draft` | `draft`, `approved`, `dissolved`. |
| `approved_by` | `bigint unsigned` | yes | — |  |
| `approved_at` | `datetime` | yes | — |  |
| `scope_key` | `bigint unsigned` | yes | generated (virtual): `coalesce(cycle_domain_id,0)` | `cycle_domain_id`, or 0 for cycle-wide committees; used by the uniqueness key. |
| `is_current` | `tinyint unsigned` | yes | generated (virtual): `(case when (status <> 'dissolved') then 1 end)` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_committees_current` (`cycle_id`, `type`, `scope_key`, `is_current`)
- **Unique** `uq_committees_id_cycle_type` (`id`, `cycle_id`, `type`)
- **Unique** `uq_committees_id_domain` (`id`, `cycle_domain_id`)
- Index `fk_committees_approved_by` (`approved_by`)
- Index `fk_committees_cycle_domain` (`cycle_domain_id`, `cycle_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_committees_approved_by` | (approved_by) | `users` (id) | RESTRICT |
| `fk_committees_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_committees_cycle_domain` | (cycle_domain_id, cycle_id) | `cycle_domains` (id, cycle_id) | RESTRICT |

**CHECK constraints**

- `chk_committees_approved`: `((status <> 'approved') or ((approved_by is not null) and (approved_at is not null)))`
- `chk_committees_domain`: `((type = 'judging') = (cycle_domain_id is not null))`
- `chk_committees_status`: `(status in ('draft','approved','dissolved'))`
- `chk_committees_type`: `(type in ('judging','integrity','trustees'))`

### `committee_members`

Known, appointed members with their history. Leaving sets `left_at`, and the row is kept. Duplicate *effective* membership of the same user in the same committee is rejected (`is_current`).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `committee_id` | `bigint unsigned` | no | — |  |
| `user_id` | `bigint unsigned` | no | — |  |
| `position` | `varchar(16)` | no | `member` | `chair`, `member`, `secretary`. |
| `specialty` | `varchar(150)` | yes | — |  |
| `joined_at` | `datetime` | no | — |  |
| `left_at` | `datetime` | yes | — |  |
| `appointment_reference` | `varchar(150)` | yes | — | Reference of the appointment decision. |
| `is_current` | `tinyint unsigned` | yes | generated (virtual): `(case when (left_at is null) then 1 end)` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_committee_members_current` (`committee_id`, `user_id`, `is_current`)
- **Unique** `uq_committee_members_id_committee` (`id`, `committee_id`)
- Index `idx_committee_members_user` (`user_id`, `left_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_committee_members_committee` | (committee_id) | `committees` (id) | RESTRICT |
| `fk_committee_members_user` | (user_id) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_committee_members_period`: `((left_at is null) or (left_at >= joined_at))`
- `chk_committee_members_position`: `(position in ('chair','member','secretary'))`

### `evaluation_rubrics`

Versioned evaluation matrices of a cycle domain. **`aggregation_method` stays null until a method is approved; no average or other method is assumed.** An approved rubric must name its method.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_domain_id` | `bigint unsigned` | no | — |  |
| `version_number` | `int unsigned` | no | — |  |
| `name_ar` | `varchar(255)` | no | — |  |
| `aggregation_method` | `varchar(64)` | yes | — | Free code chosen by the award owner (for example a weighted mean). Null until approved. |
| `status` | `varchar(16)` | no | `draft` | `draft`, `approved`, `retired`. |
| `approved_by` | `bigint unsigned` | yes | — |  |
| `approved_at` | `datetime` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_evaluation_rubrics_id_domain` (`id`, `cycle_domain_id`)
- **Unique** `uq_evaluation_rubrics_version` (`cycle_domain_id`, `version_number`)
- Index `fk_evaluation_rubrics_approved_by` (`approved_by`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_evaluation_rubrics_approved_by` | (approved_by) | `users` (id) | RESTRICT |
| `fk_evaluation_rubrics_cycle_domain` | (cycle_domain_id) | `cycle_domains` (id) | RESTRICT |

**CHECK constraints**

- `chk_evaluation_rubrics_approved`: `((status <> 'approved') or ((aggregation_method is not null) and (approved_by is not null) and (approved_at is not null)))`
- `chk_evaluation_rubrics_number`: `(version_number >= 1)`
- `chk_evaluation_rubrics_status`: `(status in ('draft','approved','retired'))`

### `evaluation_criteria`

Criteria of a rubric. **Weight convention:** a weight is the criterion's **percentage share** of its rubric (0–100, four decimals). It may be null while drafting, and the application requires the weights of an approved rubric to total 100. Negative weights and non-positive maxima are rejected. `(id, rubric_id, max_score)` is the parent key that pins each score to its criterion's maximum.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `rubric_id` | `bigint unsigned` | no | — |  |
| `code` | `varchar(64)` | no | — |  |
| `name_ar` | `varchar(255)` | no | — |  |
| `description` | `text` | yes | — |  |
| `weight` | `decimal(7,4)` | yes | — | Percentage share, 0–100, or null while drafting. |
| `max_score` | `decimal(6,2)` | no | — | Maximum score, > 0. |
| `is_required` | `tinyint(1)` | no | `1` |  |
| `display_order` | `smallint unsigned` | no | `0` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_evaluation_criteria_code` (`rubric_id`, `code`)
- **Unique** `uq_evaluation_criteria_id_rubric_max` (`id`, `rubric_id`, `max_score`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_evaluation_criteria_rubric` | (rubric_id) | `evaluation_rubrics` (id) | CASCADE |

**CHECK constraints**

- `chk_evaluation_criteria_max`: `(max_score > 0)`
- `chk_evaluation_criteria_weight`: `((weight is null) or ((weight >= 0) and (weight <= 100)))`

### `judging_assignments`

Assigns one fixed submitted version to one committee member for one round, under one rubric. **Refinement:** `application_id`, `cycle_domain_id` and `committee_id` are carried so composite keys can prove that the version, application, committee, member and rubric all belong together and to the same cycle domain. Whether every judge evaluates every application is undecided, so nothing forces it.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_version_id` | `bigint unsigned` | no | — |  |
| `application_id` | `bigint unsigned` | no | — |  |
| `cycle_domain_id` | `bigint unsigned` | no | — |  |
| `committee_id` | `bigint unsigned` | no | — |  |
| `committee_member_id` | `bigint unsigned` | no | — |  |
| `rubric_id` | `bigint unsigned` | no | — |  |
| `round_number` | `smallint unsigned` | no | `1` |  |
| `assigned_by` | `bigint unsigned` | no | — |  |
| `assigned_at` | `datetime` | no | — |  |
| `due_at` | `datetime` | yes | — |  |
| `status` | `varchar(16)` | no | `assigned` | `assigned`, `completed`, `recused`, `cancelled`; a reason is required when cancelled. |
| `cancellation_reason` | `varchar(1000)` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_judging_assignments_id_application` (`id`, `application_id`)
- **Unique** `uq_judging_assignments_id_rubric` (`id`, `rubric_id`)
- **Unique** `uq_judging_assignments_round` (`application_version_id`, `committee_member_id`, `round_number`)
- Index `fk_judging_assignments_application` (`application_id`, `cycle_domain_id`)
- Index `fk_judging_assignments_assigned_by` (`assigned_by`)
- Index `fk_judging_assignments_committee` (`committee_id`, `cycle_domain_id`)
- Index `fk_judging_assignments_member` (`committee_member_id`, `committee_id`)
- Index `fk_judging_assignments_rubric` (`rubric_id`, `cycle_domain_id`)
- Index `fk_judging_assignments_version` (`application_version_id`, `application_id`)
- Index `idx_judging_assignments_due` (`status`, `due_at`)
- Index `idx_judging_assignments_member` (`committee_member_id`, `status`, `due_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_judging_assignments_application` | (application_id, cycle_domain_id) | `applications` (id, cycle_domain_id) | RESTRICT |
| `fk_judging_assignments_assigned_by` | (assigned_by) | `users` (id) | RESTRICT |
| `fk_judging_assignments_committee` | (committee_id, cycle_domain_id) | `committees` (id, cycle_domain_id) | RESTRICT |
| `fk_judging_assignments_member` | (committee_member_id, committee_id) | `committee_members` (id, committee_id) | RESTRICT |
| `fk_judging_assignments_rubric` | (rubric_id, cycle_domain_id) | `evaluation_rubrics` (id, cycle_domain_id) | RESTRICT |
| `fk_judging_assignments_version` | (application_version_id, application_id) | `application_versions` (id, application_id) | RESTRICT |

**CHECK constraints**

- `chk_judging_assignments_cancelled`: `((status <> 'cancelled') or (cancellation_reason is not null))`
- `chk_judging_assignments_due`: `((due_at is null) or (due_at >= assigned_at))`
- `chk_judging_assignments_round`: `(round_number >= 1)`
- `chk_judging_assignments_status`: `(status in ('assigned','completed','recused','cancelled'))`

### `conflict_declarations`

Conflict-of-interest declarations about an application, optionally tied to an assignment of the same application. **Decision history:** declarations are append-only. A changed declaration is a new row, and the latest by `declared_at` applies. The resolution is recorded once on its row.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_id` | `bigint unsigned` | no | — |  |
| `user_id` | `bigint unsigned` | no | — |  |
| `judging_assignment_id` | `bigint unsigned` | yes | — |  |
| `declaration` | `varchar(16)` | no | — | `no_conflict` or `conflict` (a conflict needs a relationship description). |
| `relationship_description` | `text` | yes | — |  |
| `declared_at` | `datetime` | no | — |  |
| `resolution` | `varchar(16)` | yes | — | `recused`, `permitted`, `reassigned`, set together with `resolved_by`, `resolved_at` and `resolution_reason`. |
| `resolved_by` | `bigint unsigned` | yes | — |  |
| `resolved_at` | `datetime` | yes | — |  |
| `resolution_reason` | `text` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- Index `fk_conflict_declarations_assignment` (`judging_assignment_id`, `application_id`)
- Index `fk_conflict_declarations_resolved_by` (`resolved_by`)
- Index `idx_conflict_declarations_lookup` (`application_id`, `user_id`, `declared_at`)
- Index `idx_conflict_declarations_user` (`user_id`, `declared_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_conflict_declarations_application` | (application_id) | `applications` (id) | RESTRICT |
| `fk_conflict_declarations_assignment` | (judging_assignment_id, application_id) | `judging_assignments` (id, application_id) | RESTRICT |
| `fk_conflict_declarations_resolved_by` | (resolved_by) | `users` (id) | RESTRICT |
| `fk_conflict_declarations_user` | (user_id) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_conflict_declarations_detail`: `((declaration <> 'conflict') or (relationship_description is not null))`
- `chk_conflict_declarations_order`: `((resolved_at is null) or (resolved_at >= declared_at))`
- `chk_conflict_declarations_resolution`: `((resolution is null) or (resolution in ('recused','permitted','reassigned')))`
- `chk_conflict_declarations_resolved`: `(((resolution is null) = (resolved_at is null)) and ((resolution is null) = (resolved_by is null)) and ((resolution is null) = (resolution_reason is null)))`
- `chk_conflict_declarations_value`: `(declaration in ('no_conflict','conflict'))`

### `evaluations`

One evaluation per assignment. **A judge's recommendation is not the award result**; results are decided in `award_results`. Reopening returns the evaluation to `draft` and records the latest reopen. Every reopen is also written to `audit_logs`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `judging_assignment_id` | `bigint unsigned` | no | — |  |
| `rubric_id` | `bigint unsigned` | no | — | Refinement: copied from the assignment (composite key) so scores can be tied to this rubric. |
| `status` | `varchar(16)` | no | `draft` | `draft` or `finalized` (`finalized_at` set if and only if finalized). |
| `overall_comment` | `text` | yes | — |  |
| `recommendation` | `varchar(16)` | yes | — | `recommend`, `not_recommend`, `abstain`. |
| `finalized_at` | `datetime` | yes | — |  |
| `reopened_by` | `bigint unsigned` | yes | — |  |
| `reopened_at` | `datetime` | yes | — |  |
| `reopen_reason` | `text` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_evaluations_assignment` (`judging_assignment_id`)
- **Unique** `uq_evaluations_id_rubric` (`id`, `rubric_id`)
- Index `fk_evaluations_assignment` (`judging_assignment_id`, `rubric_id`)
- Index `fk_evaluations_reopened_by` (`reopened_by`)
- Index `idx_evaluations_status` (`status`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_evaluations_assignment` | (judging_assignment_id, rubric_id) | `judging_assignments` (id, rubric_id) | RESTRICT |
| `fk_evaluations_reopened_by` | (reopened_by) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_evaluations_finalized`: `((status = 'finalized') = (finalized_at is not null))`
- `chk_evaluations_recommendation`: `((recommendation is null) or (recommendation in ('recommend','not_recommend','abstain')))`
- `chk_evaluations_reopened`: `(((reopened_at is null) = (reopened_by is null)) and ((reopened_at is null) = (reopen_reason is null)))`
- `chk_evaluations_status`: `(status in ('draft','finalized'))`

### `evaluation_scores`

One score per criterion and evaluation. The criterion must belong to the evaluation's rubric, and the score must lie between 0 and that criterion's maximum. **Both are enforced by the database:** the composite key `(criterion_id, rubric_id, criterion_max_score)` → `evaluation_criteria (id, rubric_id, max_score)` pins the copied maximum, and a CHECK compares the score with it. A score may be null while drafting or when the criterion is marked not applicable (a reason is then required).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `evaluation_id` | `bigint unsigned` | no | — |  |
| `rubric_id` | `bigint unsigned` | no | — |  |
| `criterion_id` | `bigint unsigned` | no | — |  |
| `criterion_max_score` | `decimal(6,2)` | no | — | Copy of the criterion's maximum, pinned by the foreign key. Changing a criterion maximum once scored is blocked. |
| `score` | `decimal(6,2)` | yes | — | Null in drafts or when not applicable. |
| `comment` | `text` | yes | — |  |
| `is_not_applicable` | `tinyint(1)` | no | `0` |  |
| `not_applicable_reason` | `text` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_evaluation_scores_criterion` (`evaluation_id`, `criterion_id`)
- Index `fk_evaluation_scores_criterion` (`criterion_id`, `rubric_id`, `criterion_max_score`)
- Index `fk_evaluation_scores_evaluation` (`evaluation_id`, `rubric_id`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_evaluation_scores_criterion` | (criterion_id, rubric_id, criterion_max_score) | `evaluation_criteria` (id, rubric_id, max_score) | RESTRICT |
| `fk_evaluation_scores_evaluation` | (evaluation_id, rubric_id) | `evaluations` (id, rubric_id) | RESTRICT |

**CHECK constraints**

- `chk_evaluation_scores_na`: `(((is_not_applicable = 0) and (not_applicable_reason is null)) or ((is_not_applicable = 1) and (score is null) and (not_applicable_reason is not null)))`
- `chk_evaluation_scores_range`: `((score is null) or ((score >= 0) and (score <= criterion_max_score)))`

---

## G. Appeals

### `appeals`

Appeals and procedural complaints, handled by the cycle's integrity/appeals committee. The application is optional, for a general complaint. The optional application and committee must belong to the appeal's cycle, and the committee must be of type integrity. **Challenged record:** `challenged_record_type` is a closed list of short codes, never a class name. `challenged_record_id` has **no foreign key**, so the application layer must check that it exists in the table matching the type, and that it belongs to the same cycle.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `application_id` | `bigint unsigned` | yes | — |  |
| `submitted_by` | `bigint unsigned` | no | — |  |
| `committee_id` | `bigint unsigned` | yes | — |  |
| `committee_type` | `varchar(16)` | yes | generated (stored): `'integrity'` |  |
| `type` | `varchar(32)` | no | — | `eligibility_appeal`, `result_appeal`, `procedural_complaint`, `conflict_of_interest_report`. |
| `subject` | `varchar(255)` | no | — |  |
| `description` | `text` | no | — |  |
| `challenged_record_type` | `varchar(32)` | yes | — | `application`, `eligibility_review`, `completion_request`, `conflict_declaration`, `evaluation`, `award_result`, `result_ratification`. |
| `challenged_record_id` | `bigint unsigned` | yes | — |  |
| `status` | `varchar(16)` | no | `submitted` | `submitted`, `under_review`, `decided`, `withdrawn`. |
| `submitted_at` | `datetime` | no | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- Index `fk_appeals_application` (`application_id`, `cycle_id`)
- Index `fk_appeals_committee` (`committee_id`, `cycle_id`, `committee_type`)
- Index `fk_appeals_submitted_by` (`submitted_by`)
- Index `idx_appeals_challenged` (`challenged_record_type`, `challenged_record_id`)
- Index `idx_appeals_cycle_status` (`cycle_id`, `status`, `submitted_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_appeals_application` | (application_id, cycle_id) | `applications` (id, cycle_id) | RESTRICT |
| `fk_appeals_committee` | (committee_id, cycle_id, committee_type) | `committees` (id, cycle_id, type) | RESTRICT |
| `fk_appeals_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_appeals_submitted_by` | (submitted_by) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_appeals_challenged_pair`: `((challenged_record_type is null) = (challenged_record_id is null))`
- `chk_appeals_challenged_type`: `((challenged_record_type is null) or (challenged_record_type in ('application','eligibility_review','completion_request','conflict_declaration','evaluation','award_result','result_ratification')))`
- `chk_appeals_status`: `(status in ('submitted','under_review','decided','withdrawn'))`
- `chk_appeals_type`: `(type in ('eligibility_appeal','result_appeal','procedural_complaint','conflict_of_interest_report'))`

### `appeal_decisions`

Decisions on an appeal, kept as history. A reconsideration is a new row.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `appeal_id` | `bigint unsigned` | no | — |  |
| `decision` | `varchar(32)` | no | — | `upheld`, `partially_upheld`, `rejected`, `inadmissible`. |
| `reason` | `text` | no | — |  |
| `required_action` | `text` | yes | — |  |
| `issued_by` | `bigint unsigned` | no | — |  |
| `issued_at` | `datetime` | no | — |  |
| `notified_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- Index `fk_appeal_decisions_issued_by` (`issued_by`)
- Index `idx_appeal_decisions_appeal` (`appeal_id`, `issued_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_appeal_decisions_appeal` | (appeal_id) | `appeals` (id) | RESTRICT |
| `fk_appeal_decisions_issued_by` | (issued_by) | `users` (id) | RESTRICT |

**CHECK constraints**

- `chk_appeal_decisions_notified`: `((notified_at is null) or (notified_at >= issued_at))`
- `chk_appeal_decisions_value`: `(decision in ('upheld','partially_upheld','rejected','inadmissible'))`

---

## H. Results and ratification

### `award_results`

Versioned decision for one award slot, recommended by the domain's judging committee. A **grant** names an application version of that domain; a **withholding** names none. **One current result per slot:** `is_current` is 1 unless the row is `superseded` or `withdrawn`, and `(award_slot_id, is_current)` is unique. The application therefore marks the old row superseded and inserts the new version in one transaction. Superseded rows are kept. A later version must supersede an earlier result of the same slot, and each result can be superseded only once.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `cycle_id` | `bigint unsigned` | no | — | Refinement: lets ratifications prove that the trustees committee belongs to the same cycle. |
| `cycle_domain_id` | `bigint unsigned` | no | — | Refinement: ties the slot, application and recommending committee to one domain. |
| `award_slot_id` | `bigint unsigned` | no | — |  |
| `application_id` | `bigint unsigned` | yes | — |  |
| `application_version_id` | `bigint unsigned` | yes | — |  |
| `decision_version` | `int unsigned` | no | — |  |
| `decision_type` | `varchar(16)` | no | — | `grant` or `withhold`. |
| `recommending_committee_id` | `bigint unsigned` | no | — |  |
| `technical_reason` | `text` | no | — |  |
| `score_summary` | `json` | yes | — | Snapshot of the aggregated technical scores (JSON). |
| `status` | `varchar(16)` | no | `recommended` | `recommended`, `ratified`, `returned`, `published`, `superseded`, `withdrawn`. |
| `supersedes_result_id` | `bigint unsigned` | yes | — |  |
| `recommended_at` | `datetime` | no | — |  |
| `published_at` | `datetime` | yes | — |  |
| `is_current` | `tinyint unsigned` | yes | generated (virtual): `(case when (status not in ('superseded','withdrawn')) then 1 end)` |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_award_results_current` (`award_slot_id`, `is_current`)
- **Unique** `uq_award_results_id_cycle` (`id`, `cycle_id`)
- **Unique** `uq_award_results_id_slot` (`id`, `award_slot_id`)
- **Unique** `uq_award_results_supersedes` (`supersedes_result_id`)
- **Unique** `uq_award_results_version` (`award_slot_id`, `decision_version`)
- Index `fk_award_results_application` (`application_id`, `cycle_domain_id`)
- Index `fk_award_results_committee` (`recommending_committee_id`, `cycle_domain_id`)
- Index `fk_award_results_cycle` (`cycle_id`)
- Index `fk_award_results_cycle_domain` (`cycle_domain_id`, `cycle_id`)
- Index `fk_award_results_slot` (`award_slot_id`, `cycle_domain_id`)
- Index `fk_award_results_supersedes` (`supersedes_result_id`, `award_slot_id`)
- Index `fk_award_results_version` (`application_version_id`, `application_id`)
- Index `idx_award_results_published` (`status`, `published_at`)
- Index `idx_award_results_slot_status` (`award_slot_id`, `status`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_award_results_application` | (application_id, cycle_domain_id) | `applications` (id, cycle_domain_id) | RESTRICT |
| `fk_award_results_committee` | (recommending_committee_id, cycle_domain_id) | `committees` (id, cycle_domain_id) | RESTRICT |
| `fk_award_results_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_award_results_cycle_domain` | (cycle_domain_id, cycle_id) | `cycle_domains` (id, cycle_id) | RESTRICT |
| `fk_award_results_slot` | (award_slot_id, cycle_domain_id) | `award_slots` (id, cycle_domain_id) | RESTRICT |
| `fk_award_results_supersedes` | (supersedes_result_id, award_slot_id) | `award_results` (id, award_slot_id) | RESTRICT |
| `fk_award_results_version` | (application_version_id, application_id) | `application_versions` (id, application_id) | RESTRICT |

**CHECK constraints**

- `chk_award_results_published`: `((status <> 'published') or (published_at is not null))`
- `chk_award_results_status`: `(status in ('recommended','ratified','returned','published','superseded','withdrawn'))`
- `chk_award_results_supersedes`: `((decision_version = 1) = (supersedes_result_id is null))`
- `chk_award_results_target`: `(((decision_type = 'grant') and (application_id is not null) and (application_version_id is not null)) or ((decision_type = 'withhold') and (application_id is null) and (application_version_id is null)))`
- `chk_award_results_type`: `(decision_type in ('grant','withhold'))`
- `chk_award_results_version`: `(decision_version >= 1)`

### `result_ratifications`

Procedural ratification by the board of trustees: approval, or return for correction. The record holds only the decision, a checklist, reasons and the meeting reference. **It never stores or replaces judges' scores or technical reasoning.** The committee must be the trustees committee of the result's cycle.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `result_id` | `bigint unsigned` | no | — |  |
| `cycle_id` | `bigint unsigned` | no | — |  |
| `trustees_committee_id` | `bigint unsigned` | no | — |  |
| `committee_type` | `varchar(16)` | yes | generated (stored): `'trustees'` |  |
| `recorded_by` | `bigint unsigned` | no | — |  |
| `decision` | `varchar(32)` | no | — | `ratified` or `returned_for_correction` (a reason is required). |
| `procedural_checklist` | `json` | yes | — |  |
| `reason` | `text` | yes | — |  |
| `meeting_reference` | `varchar(150)` | yes | — |  |
| `decided_at` | `datetime` | no | — |  |

**Indexes** (besides the primary key)

- Index `fk_result_ratifications_committee` (`trustees_committee_id`, `cycle_id`, `committee_type`)
- Index `fk_result_ratifications_cycle` (`cycle_id`)
- Index `fk_result_ratifications_recorded_by` (`recorded_by`)
- Index `fk_result_ratifications_result` (`result_id`, `cycle_id`)
- Index `idx_result_ratifications_result` (`result_id`, `decided_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_result_ratifications_committee` | (trustees_committee_id, cycle_id, committee_type) | `committees` (id, cycle_id, type) | RESTRICT |
| `fk_result_ratifications_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |
| `fk_result_ratifications_recorded_by` | (recorded_by) | `users` (id) | RESTRICT |
| `fk_result_ratifications_result` | (result_id, cycle_id) | `award_results` (id, cycle_id) | RESTRICT |

**CHECK constraints**

- `chk_result_ratifications_decision`: `(decision in ('ratified','returned_for_correction'))`
- `chk_result_ratifications_reason`: `((decision <> 'returned_for_correction') or (reason is not null))`

---

## I. Public stories and auditing

### `published_stories`

Public impact stories. These are separate public fields, written for publication, and they **never expose private application snapshots**. The publication consent must belong to the same application version (composite key). Public media is attached through `file_links`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `application_version_id` | `bigint unsigned` | no | — |  |
| `publication_consent_id` | `bigint unsigned` | no | — |  |
| `public_slug` | `varchar(160)` | no | — | Unique URL slug. |
| `public_title` | `varchar(255)` | no | — |  |
| `public_applicant_name` | `varchar(255)` | no | — |  |
| `public_summary` | `text` | yes | — |  |
| `public_story` | `mediumtext` | yes | — |  |
| `status` | `varchar(16)` | no | `draft` | `draft`, `approved`, `published`, `unpublished`. |
| `approved_by` | `bigint unsigned` | yes | — |  |
| `published_at` | `datetime` | yes | — |  |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_published_stories_slug` (`public_slug`)
- Index `fk_published_stories_approved_by` (`approved_by`)
- Index `fk_published_stories_consent` (`publication_consent_id`, `application_version_id`)
- Index `fk_published_stories_version` (`application_version_id`)
- Index `idx_published_stories_status` (`status`, `published_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_published_stories_approved_by` | (approved_by) | `users` (id) | RESTRICT |
| `fk_published_stories_consent` | (publication_consent_id, application_version_id) | `application_consents` (id, application_version_id) | RESTRICT |
| `fk_published_stories_version` | (application_version_id) | `application_versions` (id) | RESTRICT |

**CHECK constraints**

- `chk_published_stories_approved`: `((status not in ('approved','published')) or (approved_by is not null))`
- `chk_published_stories_published`: `((status <> 'published') or ((published_at is not null) and (public_summary is not null) and (public_story is not null)))`
- `chk_published_stories_status`: `(status in ('draft','approved','published','unpublished'))`

### `audit_logs`

Append-only audit history. System actions have no actor. The subject is referenced by type code and id **without a foreign key**, so deleting a subject never removes its history. Do not store passwords, tokens, file contents or unnecessary personal data in `before_data`/`after_data`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment.  |
| `actor_user_id` | `bigint unsigned` | yes | — |  |
| `cycle_id` | `bigint unsigned` | yes | — |  |
| `action` | `varchar(100)` | no | — |  |
| `subject_type` | `varchar(64)` | yes | — | Short table/type code (same vocabulary as other type codes); never a class name. |
| `subject_id` | `bigint unsigned` | yes | — |  |
| `before_data` | `json` | yes | — |  |
| `after_data` | `json` | yes | — |  |
| `reason` | `text` | yes | — |  |
| `request_id` | `varchar(64)` | yes | — | Correlates entries written by one request. |
| `occurred_at` | `datetime(6)` | no | — | UTC with microseconds. |

**Indexes** (besides the primary key)

- Index `idx_audit_logs_actor` (`actor_user_id`, `occurred_at`)
- Index `idx_audit_logs_cycle` (`cycle_id`, `occurred_at`)
- Index `idx_audit_logs_occurred` (`occurred_at`)
- Index `idx_audit_logs_request` (`request_id`)
- Index `idx_audit_logs_subject` (`subject_type`, `subject_id`, `occurred_at`)

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_audit_logs_actor` | (actor_user_id) | `users` (id) | RESTRICT |
| `fk_audit_logs_cycle` | (cycle_id) | `award_cycles` (id) | RESTRICT |

**CHECK constraints**

- `chk_audit_logs_subject`: `((subject_type is null) = (subject_id is null))`

---

## J. External sign-in (account migrations)

Two additive migrations follow the platform foundation, for the public authentication described in [authentication.md](authentication.md):

- `2026_10_27_200001_allow_passwordless_users` makes `users.password` nullable.
- **Rollback:** while any account has no password, rollback is refused before anything changes. `200002` runs first and makes the same check, so `oauth_identities`, its rows and both migration-history rows are kept.
- `2026_10_27_200002_create_oauth_identities_table` creates the table below.

### `oauth_identities`

Google identities linked to accounts. The key is Google's stable subject identifier (`sub`), never the email address. No provider access or refresh tokens are stored.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment. |
| `user_id` | `bigint unsigned` | no | — | The account this identity signs in to. |
| `provider` | `varchar(32)` | no | — | `google` only. |
| `provider_subject` | `varchar(255)` | no | — | Google `sub`. `ascii` / `ascii_bin`, so it compares exactly (case-sensitive). |
| `linked_at` | `datetime` | no | — | When the identity was created or linked. |
| `last_login_at` | `datetime` | yes | — | Last Google sign-in with this identity. |
| `created_at` | `datetime` | yes | — |  |
| `updated_at` | `datetime` | yes | — |  |

**Indexes** (besides the primary key)

- **Unique** `uq_oauth_identities_subject` (`provider`, `provider_subject`): one account per Google identity.
- **Unique** `uq_oauth_identities_user_provider` (`user_id`, `provider`): at most one Google identity per account.

**Foreign keys**

| Name | Columns | References | On delete |
| --- | --- | --- | --- |
| `fk_oauth_identities_user` | (user_id) | `users` (id) | CASCADE |

**CHECK constraints**

- `chk_oauth_identities_provider`: `(provider in ('google'))`
- `chk_oauth_identities_subject`: `(provider_subject <> '')`

---

## K. Candidate onboarding (stage 1)

Three additive migrations, described for operators in [candidate-onboarding.md](candidate-onboarding.md):

- `2026_10_27_300001_provision_candidate_role` inserts the platform role `candidate` («مترشح») into `roles`, unless it exists. It creates no table.
- `2026_10_27_300002_create_account_policy_tables` creates the account policy tables.
- `2026_10_27_300003_create_candidate_profile_tables` creates the profile and applicant detail tables.

**Rollback.** `300003` rolls back first and refuses, before anything changes, while any confirmed profile, policy acceptance or candidate role grant exists. `300002` also refuses while acceptances exist, and `300001` while the role has grants.

`300001` then deletes the role only if it created it (recorded as `role.provisioned` in `audit_logs`) and no `role_permissions` row refers to it. A `candidate` role that existed beforehand is preserved unchanged, with its permissions.

**Mapping.** Shared applying-entity fields stay in `applicants`, and an institution's representative in `applicant_members`. Login accounts are unchanged.

### `account_policy_versions`

Versioned account-level policies, independent of award cycles (`policy_versions` remains for cycle consents tied to submitted application versions). Published text is not edited; a new version is published instead.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment. |
| `code` | `varchar(64)` | no | — | `privacy_policy` or `terms_of_use`. |
| `version_number` | `int unsigned` | no | — | ≥ 1. |
| `title_ar` | `varchar(255)` | no | — |  |
| `body_ar` | `mediumtext` | no | — | The exact text. |
| `status` | `varchar(16)` | no | `draft` | `draft`, `published`, `retired`. |
| `published_at` | `datetime` | yes | — | Required unless draft. |
| `published_flag` | `tinyint unsigned` | yes | generated (virtual): `(case when status = 'published' then 1 end)` | Helper for one published version per code. |
| `created_at`, `updated_at` | `datetime` | yes | — |  |

- **Unique** `uq_account_policy_versions_version` (`code`, `version_number`); **unique** `uq_account_policy_versions_published` (`code`, `published_flag`).
- **CHECKs:** `chk_account_policy_versions_code`, `chk_account_policy_versions_status`, `chk_account_policy_versions_number` (`version_number >= 1`), `chk_account_policy_versions_published` (`status = 'draft' or published_at is not null`).

### `account_policy_acceptances`

One acceptance of one exact policy version by one account: an event record with no update timestamps.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment. |
| `user_id` | `bigint unsigned` | no | — | The accepting account. FK `fk_account_policy_acceptances_user` → `users`, RESTRICT. |
| `policy_version_id` | `bigint unsigned` | no | — | FK `fk_account_policy_acceptances_version` → `account_policy_versions`, RESTRICT. |
| `context` | `varchar(32)` | no | — | `basic_profile`. |
| `accepted_at` | `datetime` | no | — | Server time (UTC). |

- **Unique** `uq_account_policy_acceptances_user_version` (`user_id`, `policy_version_id`).
- **CHECK** `chk_account_policy_acceptances_context`.

### `candidate_profiles`

Stage 1 completion: one confirmed basic profile per account. The row exists only once the information is confirmed, and it is read-only for the candidate. One profile per account limits this onboarding journey only; it sets no limit on award applications.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment. |
| `user_id` | `bigint unsigned` | no | — | FK `fk_candidate_profiles_user` → `users`, RESTRICT. |
| `applicant_id` | `bigint unsigned` | no | — | FK `fk_candidate_profiles_applicant` → `applicants`, RESTRICT. |
| `profile_type` | `varchar(16)` | no | — | `individual` or `institution` (institutions map to `applicants.type = 'organization'`). |
| `data_accuracy_declared_at` | `datetime` | no | — | Declaration of accurate data. |
| `representation_declared_at` | `datetime` | yes | — | Set if and only if the type is `institution`. |
| `completed_at` | `datetime` | no | — | Confirmation time (UTC). |
| `created_at` | `datetime` | yes | — | No `updated_at`: the row is not edited. |

- **Unique** `uq_candidate_profiles_user` (`user_id`): the final guard against a second confirmation; **unique** `uq_candidate_profiles_applicant` (`applicant_id`).
- **CHECKs:** `chk_candidate_profiles_type`, `chk_candidate_profiles_representation` (`(profile_type = 'institution') = (representation_declared_at is not null)`).

### `applicant_individual_details`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | PK. |
| `applicant_id` | `bigint unsigned` | no | Unique `uq_applicant_individual_details_applicant`; FK → `applicants`, RESTRICT. |
| `first_name`, `last_name` | `varchar(100)` | no | Any script (the form allows 80). |
| `middle_name` | `varchar(100)` | yes |  |
| `birth_date` | `date` | no | Gregorian calendar date. |
| `nationality_country_code` | `char(2)` | no | Uppercase ISO alpha-2 (`chk_applicant_individual_details_nationality`, checked like `chk_applicants_country`). |
| `gender` | `varchar(16)` | no | `male` or `female` (`chk_applicant_individual_details_gender`). |
| `national_id_number` | `text` | no | **Ciphertext only** (Laravel `encrypted` cast with `APP_KEY`). Not indexed or searchable. |
| `secondary_phone` | `varchar(32)` | yes | E.164; never marked verified. |
| `created_at`, `updated_at` | `datetime` | yes |  |

### `applicant_institution_details`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | PK. |
| `applicant_id` | `bigint unsigned` | no | Unique `uq_applicant_institution_details_applicant`; FK → `applicants`, RESTRICT. |
| `activity_type` | `varchar(150)` | no | Free text (no approved catalogue exists). |
| `website_url` | `varchar(500)` | yes | `http(s)://` only (`chk_applicant_institution_details_website`). |
| `created_at`, `updated_at` | `datetime` | yes |  |

The licence number is `applicants.registration_number`. The representative is an `applicant_members` row with the account as `user_id`, the name, `role_title` and `is_primary_contact = 1`.

### `applicant_social_links`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | PK. |
| `applicant_id` | `bigint unsigned` | no | FK `fk_applicant_social_links_applicant` → `applicants`, CASCADE (live rows of the applicant, like `applicant_members`). |
| `position` | `tinyint unsigned` | no | 1–5 (`chk_applicant_social_links_position`); unique with `applicant_id` (`uq_applicant_social_links_position`). |
| `platform` | `varchar(50)` | no |  |
| `url` | `varchar(500)` | no | `http(s)://` only (`chk_applicant_social_links_url`). Stored as text and never fetched. |
| `created_at`, `updated_at` | `datetime` | yes |  |

---

## L. Candidate Stage 2 (nomination details)

Three additive migrations, described for operators in [candidate-nomination-details.md](candidate-nomination-details.md):

- `2026_10_27_400001_create_cycle_domain_subcategories_table`;
- `2026_10_27_400002_add_nomination_details_to_applications`: new `applications` columns and `application_partnerships`;
- `2026_10_27_400003_create_candidate_nominations_table`.

**Rollback.** `400003` rolls back first and refuses, before anything changes, while any Stage 2 state exists that it or a later step of the same rollback would refuse: confirmed journeys, partnership rows, applications holding a value in any of the seven new columns, or configured subcategories. So a batch rollback never stops half-way. `400002` repeats that whole check (it can run alone after `400003` was rolled back), and `400001` refuses while subcategories are configured.

**Existing columns reused.** `applications.title`, `documented_impact` (the work's impact), `implementation_started_on`, `implementation_ended_on` and `implementation_status` (`ongoing`, or `completed` with an end date), and `application_locations` for the places of implementation. For Syrian cycles, `application_locations.governorate` holds the ISO 3166-2:SY code (for example `SY-DI`), `locality` the city, town or village, `address` the optional details, and `country_code` the cycle's country.

### `cycle_domain_subcategories`

The confirmed subcategories of a cycle domain. There are no award slots, rankings, committees or rubrics per subcategory.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | — | PK, auto-increment. |
| `cycle_id` | `bigint unsigned` | no | — | Must be the domain's cycle (composite key). |
| `cycle_domain_id` | `bigint unsigned` | no | — |  |
| `code` | `varchar(64)` | no | — | Stable code, e.g. `quality`; never inferred from the label. |
| `name_ar` | `varchar(150)` | no | — |  |
| `display_order` | `smallint unsigned` | no | `0` |  |
| `is_active` | `tinyint(1)` | no | `1` | Inactive subcategories are not offered or accepted. |
| `created_at`, `updated_at` | `datetime` | yes | — |  |

- **Unique** `uq_cycle_domain_subcategories_code` (`cycle_domain_id`, `code`), `uq_cycle_domain_subcategories_cycle_code` (`cycle_id`, `code`), `uq_cycle_domain_subcategories_id_domain` (`id`, `cycle_domain_id`): the parent key for applications.
- **FK** `fk_cycle_domain_subcategories_domain` (`cycle_domain_id`, `cycle_id`) → `cycle_domains` (`id`, `cycle_id`), RESTRICT.
- **CHECK** `chk_cycle_domain_subcategories_code`: `code <> ''`.

### `applications`: Stage 2 columns

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `subcategory_id` | `bigint unsigned` | yes | `fk_applications_subcategory` (`subcategory_id`, `cycle_domain_id`) → `cycle_domain_subcategories` (`id`, `cycle_domain_id`): the subcategory belongs to the application's own domain, and through `fk_applications_cycle_domain` to its cycle. Index `idx_applications_subcategory`. |
| `purpose_and_mission` | `text` | yes | Plain text. |
| `objectives` | `text` | yes | Plain text, one objective per line. |
| `funding_type` | `varchar(32)` | yes | `fully_funded`, `partially_funded`, `self_funded`, `not_needed`. |
| `funder_name` | `varchar(255)` | yes | Only for fully or partially funded. |
| `funding_amount_usd` | `decimal(14,2)` | yes | Exact US dollars, > 0; the funding reported for the named funder, or the self-funded amount; null for `not_needed`. |
| `has_partnerships` | `tinyint(1)` | yes | The yes/no answer; partners are rows of `application_partnerships`. |

**CHECK constraints**

- `chk_applications_funding_type`: the four codes, or NULL.
- `chk_applications_funding`: exactly the fields each type needs. Null type → no funder and no amount; funded → funder + amount; self → amount only; not needed → neither. Written with `COALESCE`, so a NULL type can never make the check UNKNOWN.
- `chk_applications_funding_amount`: `funding_amount_usd IS NULL OR funding_amount_usd > 0`.
- `chk_applications_funder_name`: not empty when present.

### `application_partnerships`

Partners named for the work: live rows of a draft, like `application_locations`. They are not accounts or applicants, and no contact or financial data is kept.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | PK. |
| `application_id` | `bigint unsigned` | no | `fk_application_partnerships_application` → `applications`, CASCADE. |
| `position` | `tinyint unsigned` | no | 1–10 (`chk_application_partnerships_position`); unique with `application_id` (`uq_application_partnerships_position`). |
| `partner_name` | `varchar(255)` | no | Not empty (`chk_application_partnerships_name`). |
| `contribution` | `varchar(1000)` | yes | The nature of the partnership. |
| `created_at`, `updated_at` | `datetime` | yes |  |

### `candidate_nominations`

The candidate dashboard's journey in one cycle: which draft application it created, and when Stage 2 was confirmed. Written in the same transaction as the application, and never updated by the candidate.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | PK. |
| `user_id` | `bigint unsigned` | no | FK → `users`, RESTRICT. |
| `candidate_profile_id` | `bigint unsigned` | no | FK → `candidate_profiles`, RESTRICT. |
| `applicant_id` | `bigint unsigned` | no | FK → `applicants`, RESTRICT (the Stage 1 applicant). |
| `cycle_id` | `bigint unsigned` | no | Index `idx_candidate_nominations_cycle`. |
| `application_id` | `bigint unsigned` | no | `fk_candidate_nominations_application` (`application_id`, `cycle_id`) → `applications` (`id`, `cycle_id`), RESTRICT: the journey's cycle is the application's. |
| `details_confirmed_at` | `datetime` | no | When Stage 2 was confirmed (UTC). |
| `created_at` | `datetime` | yes |  |

- **Unique** `uq_candidate_nominations_journey` (`candidate_profile_id`, `cycle_id`): one journey per candidate profile and cycle; the final guard of an idempotent confirmation. It limits this dashboard journey only; `applications` has no new unique key.
- **Unique** `uq_candidate_nominations_application` (`application_id`).

## M. Candidate Stage 3 and final submission

Migration `2026_10_27_500001_add_candidate_documentation` (additive). Stage 3 attachments are rows of the existing `files`, attached to the draft through `file_links` (`application_id` target). The submission writes the existing `application_versions` and links the same files to the version (`application_version_id` target), one target per row as before. It also fills the existing `applications` columns `status` (`submitted`), `reference_number`, `first_submitted_at` and `current_version_id`. Purposes in `file_links.purpose`: `explanatory_document`, `supporting_document`, `documentation_video`. Operation: [candidate-documentation-submission.md](candidate-documentation-submission.md).

### `files` upload operation

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `upload_operation_id` | `char(36)` | yes | The browser's identifier for one upload (UUID). Unique with `uploaded_by` (`uq_files_upload_operation`), so a retried upload finds its file instead of adding another. |

### `application_documentation_links`

External documentation links of a draft application: title and HTTPS address, kept exactly as validated; their content is never fetched. The submission copies them into the version's `data_snapshot`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | PK. |
| `application_id` | `bigint unsigned` | no | `fk_application_documentation_links_application` → `applications`, RESTRICT. |
| `position` | `tinyint unsigned` | no | 1–20 (`chk_application_documentation_links_position`); unique with `application_id` (`uq_application_documentation_links_position`). |
| `title` | `varchar(150)` | no | Not empty (`chk_application_documentation_links_title`). |
| `url` | `varchar(2048)` | no | Starts with `https://` (`chk_application_documentation_links_url`); the request also refuses credentials and any other scheme. |
| `created_at`, `updated_at` | `datetime` | yes |  |

### `candidate_nominations` documentation columns

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `documentation_revision` | `int unsigned` | no | Default 0; raised by every change to the attachments or links. |
| `documentation_confirmed_revision` | `int unsigned` | yes | The revision for which Stage 3 was confirmed; Stage 3 counts as complete only while it equals `documentation_revision`. |
| `documentation_confirmed_at` | `datetime` | yes | Set together with the revision (`chk_candidate_nominations_documentation`). |

**Rollback** is refused before anything changes while any Stage 3 state exists: links, uploads with an operation identifier, or a journey with documentation. When `400003` is in the same batch (a fresh install), the Stage 2 state is counted too, so a batch rollback never stops half-way.
