# Platform database: enforced rules, application rules and open decisions

This document separates three things:

1. what the **database itself guarantees**;
2. what the **application layer must enforce later**, because a row-level constraint cannot express it or it depends on workflow;
3. which **business decisions are still open**, and where they will be stored once made.

The schema is described in [database-schema.md](database-schema.md), and the diagrams are in [database-erd.md](database-erd.md).

## 1. Confirmed decisions reflected in the schema

These decisions override older documents in `docs/`.

| Decision | How the schema reflects it |
| --- | --- |
| Award name: جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع | Stored per cycle in `award_cycles.award_name_ar` (data, not seeded) |
| Three domains: الثقافة والفنون، التراث المادي واللامادي، الإبداع | Rows in `award_domains` / `cycle_domains`, entered as data. Nothing is hardcoded or seeded. |
| Each domain has its own judging committee of 3 or 5 known judges | `committees` of type `judging`, one current per cycle domain (DB). The 3-or-5 count is checked when a committee is activated (application layer, §3). |
| Public voting and the audience award are cancelled | No voting, voter or audience-award table, column, status or permission |
| Field verification and field visits are cancelled | No field-visit or verifier table, file-link target, consent or permission |
| Documentary evidence stays part of applications | `files` + `file_links` targeting draft applications and submitted versions |
| Integrity/appeals stay in scope | `committees` of type `integrity`, `appeals`, `appeal_decisions` |
| Procedural ratification by the trustees stays in scope, separate from technical judging | `result_ratifications`, which hold only procedural fields and never scores or technical reasons |
| Public impact stories are separate from private application data | `published_stories` with its own public fields; no snapshot is exposed |
| Planned jury awards: 2 + 2 + 3, not ranked places | `award_slots` rows per cycle domain. `slot_number` is an identifier, not a rank, and no count is hardcoded. |
| Committee members, trustees, integrity members and award-management employees may not apply | Application-layer eligibility rule (§3); it spans tables |

## 2. Enforced by the database (MySQL 8.4)

Every rule below is tested on MySQL 8.4.7 by `backend/tests/Feature/PlatformDatabaseConstraintTest.php`.

### Uniqueness

- **Catalogue codes:** roles, permissions, cycles, domains.
- **Within-parent codes:** `(cycle_id, code)` for stages, `(cycle_domain_id, code)` and `(cycle_domain_id, slot_number)` for slots.
- **Pairs:** `(cycle_id, domain_id)` for cycle domains; a role-permission pair.
- **Version numbers:** application versions, rubrics, policy versions and award results (`(award_slot_id, decision_version)`).
- **Other single values:** an application's `reference_number`; a story's `public_slug`; a file's storage location `(storage_disk, storage_path)`.
- **No duplicate judging work:**
  - one assignment per version, member and round;
  - one evaluation per assignment;
  - one score per evaluation and criterion.
- **No duplicate consent:** one acceptance per version and policy version.
- **No duplicate file links:** a file cannot be linked twice to the same target.
- **"One current" rows,** each through a generated helper column that is `1` for the current row and `NULL` otherwise:
  - one unrevoked platform grant per user and role;
  - one unrevoked cycle grant per cycle, user and role;
  - one effective membership per committee and user;
  - one current committee per cycle, type and domain;
  - one primary member per applicant;
  - one primary location per application;
  - **one current result per award slot.**

### Referential integrity, including consistency across tables

All foreign keys are real InnoDB constraints. A single-column foreign key only proves that the parent exists. Wherever a relationship must also stay inside one cycle, domain, application or rubric, a **composite foreign key** references a matching `(id, scope)` unique key on the parent:

| Rule | Composite key |
| --- | --- |
| An application's domain belongs to the application's cycle | `applications (cycle_domain_id, cycle_id)` → `cycle_domains (id, cycle_id)` |
| An application's current version is its own | `applications (current_version_id, id)` → `application_versions (id, application_id)` |
| A completion request's source and response versions belong to its application | `(based_on_version_id, application_id)` and `(response_version_id, application_id)` → `application_versions (id, application_id)` |
| A completion-response version answers a request of its own application | `application_versions (completion_request_id, application_id)` → `completion_requests (id, application_id)` |
| A judging committee's domain belongs to its cycle | `committees (cycle_domain_id, cycle_id)` → `cycle_domains` |
| An assignment's version, application, committee, member and rubric all agree on one cycle domain | five composite keys on `judging_assignments` |
| A declaration's assignment concerns the same application | `conflict_declarations (judging_assignment_id, application_id)` |
| An evaluation uses its assignment's rubric | `evaluations (judging_assignment_id, rubric_id)` |
| A score's criterion belongs to that rubric, and its maximum is pinned | `evaluation_scores (criterion_id, rubric_id, criterion_max_score)` → `evaluation_criteria (id, rubric_id, max_score)` |
| An appeal's application and committee belong to its cycle, and the committee is the integrity committee | `appeals (application_id, cycle_id)`, `(committee_id, cycle_id, committee_type = 'integrity')` |
| A result's slot, granted application and recommending committee belong to its cycle domain; the version belongs to the application | composite keys on `award_results` |
| A superseding result is of the same slot, and each result is superseded at most once | `(supersedes_result_id, award_slot_id)` → `award_results (id, award_slot_id)` + unique `supersedes_result_id` |
| A ratification's committee is the trustees committee of the result's cycle | `result_ratifications (trustees_committee_id, cycle_id, committee_type = 'trustees')` |
| A story's publication consent was given for the same version | `published_stories (publication_consent_id, application_version_id)` → `application_consents (id, application_version_id)` |
| Platform grants use platform roles; cycle grants use cycle roles | `(role_id, role_scope)` → `roles (id, scope)` |

### CHECK constraints

- **Closed vocabularies:** every status, type, decision, scope, position, access level and scan status is limited to its listed codes. `appeals.challenged_record_type` is a list of short codes, so class names are rejected.
- **Exactly one file-link target:** draft application, submitted version, appeal or published story.
- **Grant versus withholding:** a grant names an application and version; a withholding names neither.
- **Numeric ranges:**
  - scores between 0 and the pinned criterion maximum;
  - weights between 0 and 100 (or null while drafting);
  - criterion maximum above 0;
  - version, round and slot numbers ≥ 1;
  - latitude and longitude in range, given as a pair.
- **State consistency:**
  - **Users:** a disabled user has `disabled_at`.
  - **Applications:**
    - a non-draft application has `first_submitted_at` and `current_version_id`;
    - a withdrawn application has `withdrawn_at`.
  - **Application versions:**
    - version 1 is `initial`;
    - a completion response names its request.
  - **Completion requests:**
    - an answered request has a response;
    - only an open request has no `closed_at`.
  - **Eligibility reviews:** a decided review has `reviewed_at`, and a non-eligible one has a reason.
  - **Approvals:** an approved committee or rubric has its approver and time, and an approved rubric names its aggregation method.
  - **Results:** decision version 1 supersedes nothing, and every later version supersedes something.
  - **Ratifications:** a return for correction has a reason.
  - **Evaluations and scores:**
    - a finalized evaluation has `finalized_at`;
    - a not-applicable score has a reason and no value.
  - **Stories:** a published story has its text and publication time.
- **Formats:**
  - ISO country codes are uppercase alpha-2;
  - SHA-256 and content hashes are 64 lowercase hex characters;
  - storage paths cannot be URLs or carry query strings (so no signed URLs).
- **Time order:**
  - stage windows close after they open;
  - due, resolved, revoked, left and notified times do not precede their start.

### Deletion behaviour

- **Restrict** is the default everywhere. In particular, submitted versions, evaluations, scores, decisions, ratifications, consents, policy texts and users referenced by history cannot be deleted while referenced.
- **Cascade** only for disposable association or live-draft rows:
  - `role_permissions`;
  - `applicant_members`;
  - `application_locations`;
  - `evaluation_criteria` with an unused rubric.
- **Set null** only for `applicant_members.user_id`.
- **Audit history:** `audit_logs` refers to its subject without a foreign key, so audit history is never deleted along with its subject.

## 3. Rules the application layer must enforce

These cannot be expressed as row-level constraints, or they depend on workflow. Each needs validation in the future services, plus tests.

| Rule | Why it is not a DB constraint | Notes for implementation |
| --- | --- | --- |
| A judging committee has **3 or 5** current members before it is approved or used | Counts other rows | Check inside the activation transaction, holding a lock on the committee row |
| The integrity committee composition (documented as 3: legal, governance, independent) and the trustees board size (documented as 7–11) | Counts and roles of other rows; not confirmed as hard limits | Make configurable; do not hardcode |
| **Conflict-of-interest bar:** committee members, trustees, integrity members and award-management employees may not apply, or influence files related to them | Spans users, committee memberships, cycle roles and applicants | Check at submission and on every assignment. Record declarations in `conflict_declarations`. |
| A judge is not assigned an application they have a declared, unresolved or recused conflict with | Spans declarations and assignments | Check before creating or keeping an assignment |
| A committee member is active (joined, not left) when assigned | Temporal, spans tables | Check `joined_at` / `left_at` at assignment time |
| **Versions, consents, decisions, ratifications and audit rows are immutable** once written | There are no triggers by design | Never update or delete them in code. In production, also deny UPDATE/DELETE on these tables to the application's database user. |
| A rubric is approved only when its criteria weights total **100** and every criterion has a weight | Sums other rows | Validate when approving |
| An evaluation is finalized only when every required criterion has a score or a justified not-applicable mark | Counts other rows | Validate when finalizing |
| Superseding a result: mark the old result `superseded`, then insert the next `decision_version`, in **one transaction** | The DB enforces the end state (one current result), not the steps | Lock the slot's current result row (`SELECT … FOR UPDATE`) |
| The publication consent of a story is for a **publication** policy, and its file links point only at **public**, scanned-clean files | Needs the policy code or file attributes of other rows | Check on approval and on linking |
| A policy version's cycle matches the application's cycle | Two hops away (version → application → cycle) | Check when recording consent |
| `appeals.challenged_record_id` exists in the table matching `challenged_record_type`, and belongs to the same cycle | Polymorphic reference without a foreign key | Map each type code to one table in code. **Never accept a class name or table name from a request.** |
| Stage windows gate actions (submission, completion, appeals) | Workflow and time | Read `cycle_stages` by code, in the cycle's timezone |
| Published policy text and approved rubrics are not edited; new versions are created instead | No triggers | Enforced by services |
| Application status transitions follow the approved workflow | Workflow | The CHECKs only limit statuses to the listed codes |
| Only authorised accounts edit an applicant or application; **membership alone grants nothing** | Authorisation | Policies, from the representative account and granted roles |
| Personal data in snapshots, audit `before_data`/`after_data` and stories is minimised; no passwords, tokens or file contents | Content | Redact in the audit writer |
| Phone numbers are normalised (E.164) and not used as identity | Content | Validation |

## 4. Open business decisions and where they will live

**No defaults have been chosen for any of these.** Each has a place to be stored, so the decision can be recorded later without changing the meaning of existing data.

| Open decision | Where it will be stored | Current state |
| --- | --- | --- |
| Application limits (per applicant, cycle or domain) | `award_cycles.submission_rules`, e.g. `{"max_applications_per_applicant": …}` | `submission_rules` is null; no unique key limits applications |
| Whether third-party nominations are allowed | `award_cycles.submission_rules`, e.g. `{"third_party_nominations": …}`. The schema already separates `applications.created_by` from the applicant's representative and members. | Null |
| Structure of the Creativity (الإبداع) sub-areas (social responsibility, entrepreneurship, quality, …) | Either the cycle domain's `supplementary_questions`/`eligibility_requirements`, or a future table if sub-areas need their own slots or rubrics | Not modelled |
| Scoring weights | `evaluation_criteria.weight` (a percentage) | Nullable until a rubric is approved |
| Aggregation method | `evaluation_rubrics.aggregation_method` | Null until approved; no average is assumed |
| Tie-breaking rules | A future nullable rubric-level field, added by a small migration once the rule's shape is known | Not stored |
| Appeal deadlines | A `cycle_stages` row for the appeal window (`opens_at`/`closes_at`), read by the appeal service | No stage or deadline is created |
| Whether every judge evaluates every application | Recorded with the rubric or cycle configuration once decided; `judging_assignments` supports both full and partial assignment | No constraint either way |
| Whether one application may receive more than one award slot | Application-layer rule once decided | No constraint |
| Geographic scope of later cycles (the first cycle is Syria) | `award_cycles.eligibility_rules` | Locations accept any ISO country |

## 5. Refinements to the proposed schema

Each refinement keeps the semantics of the original contract and closes a gap that a single-column key cannot cover.

| Refinement | Reason |
| --- | --- |
| `applications.cycle_id` (kept consistent with the domain by a composite key) | Lets appeals and results prove that an application belongs to the same cycle |
| `judging_assignments.application_id`, `.cycle_domain_id`, `.committee_id` | Composite keys prove that the version, application, committee, member and rubric all agree. A single `application_version_id` key could not. |
| `evaluations.rubric_id` and `evaluation_scores.rubric_id`, `.criterion_max_score` | The database guarantees that a score's criterion belongs to the assignment's rubric, and that the score does not exceed the criterion maximum. The contract allowed documenting these as cross-table checks; enforcing them was possible without triggers. |
| `award_results.cycle_id`, `.cycle_domain_id`, `.application_id` | Ties the slot, granted application, version and recommending committee to one domain, and lets ratifications check the trustees committee's cycle |
| `result_ratifications.cycle_id` | Same-cycle trustees committee |
| `user_roles.revoked_by`, `cycle_user_roles.revoked_by` | Who revoked a grant belongs in the audit trail next to when |
| Generated helper columns (`is_current`, `primary_flag`, `scope_key`, `role_scope`, `committee_type`) | The documented "one current row" and type/scope rules without partial indexes or triggers |
| `application_versions.created_at` only, with no `updated_at` | Versions are immutable |
| `DATETIME` instead of `TIMESTAMP` for new columns | UTC values are stored exactly, regardless of the MySQL session time zone, and there is no 2038 limit |
| One current committee per cycle, type and domain | Matches "a separately appointed committee" per domain, and a single integrity committee and single trustees board per cycle. Dissolved committees remain as history. |

## 6. Migration ordering, circular keys and rollback

- **Dependency order.** Migrations `…100001` to `…100011` create tables in dependency order. Columns that close a cycle are created as plain nullable columns:
  - `applications.current_version_id`;
  - `application_versions.completion_request_id`;
  - `file_links.appeal_id` and `file_links.published_story_id`.
- **Deferred keys.** Their foreign keys, with explicit supporting indexes, are added in the last migration, `…100012_add_deferred_platform_foreign_keys`.
- **Rollback order.** Because that migration runs last, a rollback runs its `down()` first: it drops those keys and indexes before any table they reference is dropped. Each earlier `down()` then drops its own tables in reverse order.
- **`users`.** The extension migration drops only the columns, indexes and CHECKs it added. Existing users and all baseline tables are untouched in both directions.
- **InnoDB precondition.** The `users` migration refuses to run if `users` is not InnoDB, because foreign keys from InnoDB tables to a MyISAM table would fail part-way through. The error message explains the conversion.
- **Never edited.** No existing migration was modified.

## 7. Running the database tests safely

The schema tests run only against an **explicitly named, disposable MySQL database whose name ends in `_schema_test`**. They are skipped otherwise, so `php artisan test` never touches a real database.

```sh
# Create the disposable database once (MySQL 8.0.16+; the project uses 8.4):
#   CREATE DATABASE al_sankri_award_schema_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
cd backend
MIGRATION_TEST_DB_DATABASE=al_sankri_award_schema_test php artisan test --compact \
  tests/Feature/PlatformDatabaseMigrationTest.php tests/Feature/PlatformDatabaseConstraintTest.php
```

**Connection.** Optional `MIGRATION_TEST_DB_HOST`, `_PORT`, `_USERNAME` and `_PASSWORD` override the `DB_*` values.

**Guards before anything destructive:**

- The name must end in `_schema_test` and must differ from the application's configured database.
- `SELECT DATABASE()` must return that name on the live connection.
- The server must be MySQL 8.0.16 or later (MariaDB and SQLite are skipped).

**What the lifecycle test does:**

1. Wipes **only** that database.
2. Applies the develop baseline and inserts a representative user and token.
3. Applies the 12 new migrations.
4. Rolls them back and checks that:
   - the baseline tables and `users` columns are exactly restored;
   - no CHECK constraint remains;
   - every original user field is unchanged;
   - the token row is still present.
5. Reapplies the migrations.

**Isolation.** Each constraint test runs inside a transaction that is rolled back afterwards.

**On SQLite** (for example `RefreshDatabase` in future feature tests), the migrations run, but the MySQL CHECK constraints and the InnoDB precondition are skipped. Constraint behaviour must therefore be verified on MySQL.
