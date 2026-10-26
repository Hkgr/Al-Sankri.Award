# Platform database: entity-relationship diagrams

These diagrams show the 36 tables of the platform database foundation, split by module.

- **Columns shown:** each diagram lists the keys and the columns that matter for relationships. Every field, type, index and constraint is in [database-schema.md](database-schema.md).
- **Rules:** the enforcement of each relationship is explained in [database-rules.md](database-rules.md).

**Notation**

- `PK` primary key, `FK` foreign key, `UK` part of a unique key.
- Relationship lines read parent → child: `||--o{` means "one parent, zero or more children".
- A label in brackets, such as `[same cycle]`, marks a **composite foreign key** that also enforces consistency. For example, `(cycle_domain_id, cycle_id) → cycle_domains (id, cycle_id)` makes the domain belong to the same cycle.

**Not modelled:** there is no voting, voter, audience-award, field-visit or field-verifier entity.

## Overview

How the modules connect. Lookup and audit relationships are left out for readability.

```mermaid
erDiagram
    award_cycles ||--o{ cycle_domains : "defines"
    award_domains ||--o{ cycle_domains : "instantiated as"
    cycle_domains ||--o{ award_slots : "has jury awards"
    cycle_domains ||--o{ applications : "receives"
    applicants ||--o{ applications : "submits"
    applications ||--o{ application_versions : "is submitted as"
    application_versions ||--o{ eligibility_reviews : "is checked by"
    application_versions ||--o{ application_consents : "carries"
    cycle_domains ||--o| committees : "judged by (judging)"
    award_cycles ||--o{ committees : "integrity / trustees"
    committees ||--o{ committee_members : "has"
    committee_members ||--o{ judging_assignments : "receives"
    application_versions ||--o{ judging_assignments : "is judged in"
    judging_assignments ||--o| evaluations : "produces"
    award_slots ||--o{ award_results : "decided by"
    award_results ||--o{ result_ratifications : "procedurally ratified"
    application_versions ||--o{ published_stories : "may become"
    award_cycles ||--o{ appeals : "receives"
```

## A. Accounts and permissions

```mermaid
erDiagram
    users {
        bigint id PK
        varchar email UK
        varchar phone "new, not unique"
        varchar status "new: active | disabled"
        datetime disabled_at "new"
        datetime last_login_at "new"
    }
    roles {
        bigint id PK
        varchar code UK
        varchar scope UK "platform | cycle; UK (id, scope)"
    }
    permissions {
        bigint id PK
        varchar code UK
        varchar module
    }
    role_permissions {
        bigint id PK
        bigint role_id FK "UK (role_id, permission_id)"
        bigint permission_id FK
    }
    user_roles {
        bigint id PK
        bigint user_id FK
        bigint role_id FK
        varchar role_scope FK "generated: platform"
        datetime revoked_at
        tinyint is_current UK "1 while unrevoked"
    }
    cycle_user_roles {
        bigint id PK
        bigint cycle_id FK
        bigint user_id FK
        bigint role_id FK
        varchar role_scope FK "generated: cycle"
        datetime starts_at
        datetime ends_at
        datetime revoked_at
        tinyint is_current UK "1 while unrevoked"
    }
    award_cycles {
        bigint id PK
    }

    roles ||--o{ role_permissions : "cascade"
    permissions ||--o{ role_permissions : "cascade"
    users ||--o{ user_roles : "holds"
    roles ||--o{ user_roles : "[scope = platform]"
    users ||--o{ cycle_user_roles : "holds"
    roles ||--o{ cycle_user_roles : "[scope = cycle]"
    award_cycles ||--o{ cycle_user_roles : "scopes"
```

## B. Award cycles and domains

```mermaid
erDiagram
    award_cycles {
        bigint id PK
        varchar code UK
        varchar award_name_ar
        smallint year
        varchar timezone "IANA, e.g. Asia/Damascus"
        varchar status "draft | published | closed | archived"
        json eligibility_rules "null = not configured"
        json submission_rules "null = undecided"
    }
    cycle_stages {
        bigint id PK
        bigint cycle_id FK "UK (cycle_id, code)"
        varchar code
        datetime opens_at "stages may overlap"
        datetime closes_at
        varchar status "planned | enabled | closed | cancelled"
    }
    award_domains {
        bigint id PK
        varchar code UK
        varchar name_ar
    }
    cycle_domains {
        bigint id PK
        bigint cycle_id FK "UK (cycle_id, domain_id); UK (id, cycle_id)"
        bigint domain_id FK
        json eligibility_requirements
        json supplementary_questions
        boolean is_open_for_applications
    }
    award_slots {
        bigint id PK
        bigint cycle_domain_id FK "UK (cycle_domain_id, code) and (cycle_domain_id, slot_number)"
        varchar code
        smallint slot_number "identifier, not a rank"
        boolean is_enabled
    }

    award_cycles ||--o{ cycle_stages : "has (overlapping)"
    award_cycles ||--o{ cycle_domains : "defines"
    award_domains ||--o{ cycle_domains : "instantiated as"
    cycle_domains ||--o{ award_slots : "has"
```

## C. Applicants and applications

The two dotted relationships are circular and are added by the last migration, `add_deferred_platform_foreign_keys`. A rollback removes them first.

```mermaid
erDiagram
    users {
        bigint id PK
    }
    applicants {
        bigint id PK
        varchar type "individual | team | initiative | official_institution | organization"
        bigint representative_user_id FK
        varchar registration_number "nullable where inapplicable"
    }
    applicant_members {
        bigint id PK
        bigint applicant_id FK
        bigint user_id FK "optional; set null on account deletion"
        boolean is_primary_contact "one primary per applicant"
    }
    cycle_domains {
        bigint id PK
        bigint cycle_id
    }
    applications {
        bigint id PK
        bigint cycle_id FK "UK (id, cycle_id)"
        bigint cycle_domain_id FK "UK (id, cycle_domain_id)"
        bigint applicant_id FK "no one-per-cycle rule"
        bigint created_by FK
        varchar reference_number UK "null for drafts"
        varchar status "draft ... withdrawn | closed"
        bigint current_version_id FK "deferred, same application"
    }
    application_locations {
        bigint id PK
        bigint application_id FK
        char country_code "any ISO country"
        decimal latitude "optional pair"
        decimal longitude
    }
    application_versions {
        bigint id PK
        bigint application_id FK "UK (application_id, version_number); UK (id, application_id)"
        int version_number
        varchar submission_type "initial | completion_response"
        bigint completion_request_id FK "deferred, same application"
        json data_snapshot
        json form_snapshot
        char content_hash
    }
    completion_requests {
        bigint id PK
        bigint application_id FK
    }

    users ||--o{ applicants : "represents"
    applicants ||--o{ applicant_members : "has (cascade)"
    users |o--o{ applicant_members : "optional account"
    cycle_domains ||--o{ applications : "[same cycle]"
    applicants ||--o{ applications : "submits"
    applications ||--o{ application_locations : "implemented at (cascade)"
    applications ||--o{ application_versions : "versions (restrict)"
    application_versions |o..o| applications : "current version [same application]"
    completion_requests |o..o{ application_versions : "answered by [same application]"
```

## D. Files and consent

```mermaid
erDiagram
    files {
        bigint id PK
        bigint uploaded_by FK "null for system files"
        varchar storage_disk UK "UK (storage_disk, storage_path)"
        varchar storage_path UK "never a URL"
        char sha256 "indexed, not unique"
        varchar scan_status "pending | clean | infected | error"
        varchar access_level "private (default) | public"
        bigint source_file_id FK "public derivative to private source"
    }
    file_links {
        bigint id PK
        bigint file_id FK
        bigint application_id FK "draft target"
        bigint application_version_id FK "submitted target"
        bigint appeal_id FK "deferred"
        bigint published_story_id FK "deferred"
        varchar purpose
    }
    applications {
        bigint id PK
    }
    application_versions {
        bigint id PK
    }
    appeals {
        bigint id PK
    }
    published_stories {
        bigint id PK
    }
    policy_versions {
        bigint id PK
        bigint cycle_id FK "UK (cycle_id, code, version_number)"
        varchar code "truthful_data | representation | media_use | publication"
        mediumtext body_ar "exact accepted text"
    }
    application_consents {
        bigint id PK
        bigint application_version_id FK "UK (version, policy); UK (id, version)"
        bigint policy_version_id FK
        bigint accepted_by FK
        datetime accepted_at
    }

    files ||--o{ files : "derivative of"
    files ||--o{ file_links : "linked by"
    applications |o--o{ file_links : "exactly one target (CHECK)"
    application_versions |o--o{ file_links : "exactly one target (CHECK)"
    appeals |o--o{ file_links : "exactly one target (CHECK)"
    published_stories |o--o{ file_links : "exactly one target (CHECK)"
    application_versions ||--o{ application_consents : "accepted with"
    policy_versions ||--o{ application_consents : "text accepted"
```

## E. Eligibility and completion

```mermaid
erDiagram
    application_versions {
        bigint id PK
        bigint application_id "UK (id, application_id)"
    }
    eligibility_reviews {
        bigint id PK
        bigint application_version_id FK "repeatable, never overwritten"
        bigint reviewer_user_id FK
        varchar checklist_version
        varchar decision "eligible | ineligible | incomplete; null while unfinished"
        datetime reviewed_at
    }
    completion_requests {
        bigint id PK
        bigint application_id FK "UK (id, application_id)"
        bigint based_on_version_id FK "[same application]"
        bigint response_version_id FK "[same application]; null while open"
        varchar status "open | answered | expired | cancelled"
        datetime due_at
    }

    application_versions ||--o{ eligibility_reviews : "reviewed in"
    application_versions ||--o{ completion_requests : "source version [same application]"
    application_versions |o--o| completion_requests : "response version [same application]"
```

## F. Committees and judging

Every judging relationship is a composite key, so an assignment cannot mix applications, committees, members or rubrics from different cycle domains.

```mermaid
erDiagram
    cycle_domains {
        bigint id PK
        bigint cycle_id
    }
    committees {
        bigint id PK
        bigint cycle_id FK "UK (id, cycle_id, type)"
        bigint cycle_domain_id FK "required only for judging; UK (id, cycle_domain_id)"
        varchar type "judging | integrity | trustees"
        varchar status "draft | approved | dissolved"
        tinyint is_current UK "one current per cycle, type and domain"
    }
    committee_members {
        bigint id PK
        bigint committee_id FK "UK (id, committee_id)"
        bigint user_id FK
        datetime joined_at
        datetime left_at "history kept"
        tinyint is_current UK "no duplicate effective membership"
    }
    evaluation_rubrics {
        bigint id PK
        bigint cycle_domain_id FK "UK (cycle_domain_id, version_number)"
        varchar aggregation_method "null until approved"
        varchar status "draft | approved | retired"
    }
    evaluation_criteria {
        bigint id PK
        bigint rubric_id FK "UK (rubric_id, code); UK (id, rubric_id, max_score)"
        decimal weight "percentage 0-100, nullable while drafting"
        decimal max_score "> 0"
    }
    application_versions {
        bigint id PK
        bigint application_id
    }
    judging_assignments {
        bigint id PK
        bigint application_version_id FK "[same application]"
        bigint application_id FK "[same cycle domain]"
        bigint cycle_domain_id
        bigint committee_id FK "[same cycle domain]"
        bigint committee_member_id FK "[same committee]"
        bigint rubric_id FK "[same cycle domain]"
        smallint round_number "UK (version, member, round)"
        varchar status "assigned | completed | recused | cancelled"
        datetime due_at
    }
    conflict_declarations {
        bigint id PK
        bigint application_id FK
        bigint user_id FK
        bigint judging_assignment_id FK "optional [same application]"
        varchar declaration "no_conflict | conflict; append-only history"
        varchar resolution "recused | permitted | reassigned"
    }
    evaluations {
        bigint id PK
        bigint judging_assignment_id FK "UK: one per assignment"
        bigint rubric_id FK "[same rubric as assignment]"
        varchar status "draft | finalized"
        varchar recommendation "not the award result"
    }
    evaluation_scores {
        bigint id PK
        bigint evaluation_id FK "UK (evaluation_id, criterion_id)"
        bigint rubric_id FK
        bigint criterion_id FK "[criterion of the same rubric]"
        decimal criterion_max_score FK "pinned copy of max_score"
        decimal score "0 to criterion_max_score (CHECK)"
    }

    cycle_domains |o--o| committees : "judging committee [same cycle]"
    committees ||--o{ committee_members : "has"
    cycle_domains ||--o{ evaluation_rubrics : "versions"
    evaluation_rubrics ||--o{ evaluation_criteria : "has (cascade while unused)"
    application_versions ||--o{ judging_assignments : "fixed version"
    committees ||--o{ judging_assignments : "[same cycle domain]"
    committee_members ||--o{ judging_assignments : "[same committee]"
    evaluation_rubrics ||--o{ judging_assignments : "[same cycle domain]"
    judging_assignments ||--o| evaluations : "one evaluation"
    judging_assignments |o--o{ conflict_declarations : "optional"
    evaluations ||--o{ evaluation_scores : "[same rubric]"
    evaluation_criteria ||--o{ evaluation_scores : "[same rubric, pinned max]"
```

## G. Appeals

```mermaid
erDiagram
    award_cycles {
        bigint id PK
    }
    applications {
        bigint id PK
        bigint cycle_id "UK (id, cycle_id)"
    }
    committees {
        bigint id PK
        bigint cycle_id
        varchar type
    }
    appeals {
        bigint id PK
        bigint cycle_id FK
        bigint application_id FK "optional [same cycle]"
        bigint committee_id FK "optional [same cycle, type = integrity]"
        varchar committee_type FK "generated: integrity"
        varchar type "eligibility_appeal | result_appeal | procedural_complaint | conflict_of_interest_report"
        varchar challenged_record_type "closed list of codes, never a class name"
        bigint challenged_record_id "no FK: checked by the application"
        varchar status "submitted | under_review | decided | withdrawn"
    }
    appeal_decisions {
        bigint id PK
        bigint appeal_id FK "history, never overwritten"
        varchar decision "upheld | partially_upheld | rejected | inadmissible"
        datetime issued_at
        datetime notified_at
    }

    award_cycles ||--o{ appeals : "receives"
    applications |o--o{ appeals : "[same cycle]"
    committees |o--o{ appeals : "[integrity committee of the same cycle]"
    appeals ||--o{ appeal_decisions : "decided by"
```

## H. Results and ratification

```mermaid
erDiagram
    award_slots {
        bigint id PK
        bigint cycle_domain_id "UK (id, cycle_domain_id)"
    }
    application_versions {
        bigint id PK
        bigint application_id
    }
    committees {
        bigint id PK
        bigint cycle_domain_id
    }
    award_results {
        bigint id PK
        bigint cycle_id FK "UK (id, cycle_id)"
        bigint cycle_domain_id FK "[same cycle]"
        bigint award_slot_id FK "[same cycle domain]; UK (slot, decision_version)"
        bigint application_id FK "grant only [same cycle domain]"
        bigint application_version_id FK "grant only [same application]"
        int decision_version
        varchar decision_type "grant | withhold"
        bigint recommending_committee_id FK "[judging committee of the domain]"
        text technical_reason
        varchar status "recommended | ratified | returned | published | superseded | withdrawn"
        bigint supersedes_result_id FK "UK; [same slot]"
        tinyint is_current UK "UK (award_slot_id, is_current)"
    }
    result_ratifications {
        bigint id PK
        bigint result_id FK "[same cycle]"
        bigint cycle_id FK
        bigint trustees_committee_id FK "[trustees committee of the cycle]"
        varchar committee_type FK "generated: trustees"
        varchar decision "ratified | returned_for_correction"
        json procedural_checklist "no scores or technical reasoning"
        datetime decided_at
    }

    award_slots ||--o{ award_results : "versioned decisions"
    application_versions |o--o{ award_results : "granted to (grant only)"
    committees ||--o{ award_results : "recommends"
    award_results |o--o| award_results : "supersedes (same slot)"
    award_results ||--o{ result_ratifications : "procedurally ratified"
    committees ||--o{ result_ratifications : "trustees"
```

## I. Public stories and auditing

```mermaid
erDiagram
    application_versions {
        bigint id PK
    }
    application_consents {
        bigint id PK
        bigint application_version_id "UK (id, application_version_id)"
    }
    published_stories {
        bigint id PK
        bigint application_version_id FK
        bigint publication_consent_id FK "[consent of the same version]"
        varchar public_slug UK
        varchar public_title "public fields only, no private snapshot"
        varchar status "draft | approved | published | unpublished"
        datetime published_at
    }
    file_links {
        bigint id PK
        bigint published_story_id FK "public media"
    }
    audit_logs {
        bigint id PK
        bigint actor_user_id FK "null for system actions"
        bigint cycle_id FK
        varchar action
        varchar subject_type "no FK: history survives its subject"
        bigint subject_id
        json before_data
        json after_data
        datetime occurred_at "microseconds"
    }
    users {
        bigint id PK
    }

    application_versions ||--o{ published_stories : "may be published as"
    application_consents ||--o{ published_stories : "[same version]"
    published_stories ||--o{ file_links : "public media"
    users |o--o{ audit_logs : "acted"
```
