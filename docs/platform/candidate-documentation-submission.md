# Stage 3 and final submission («ملفات العمل وتوثيقه», «إرسال للجنة الحكام»)

For the people who deploy, operate and review the platform. Related: [candidate-nomination-details.md](candidate-nomination-details.md) (Stage 2, the cycle and its stage), [candidate-onboarding.md](candidate-onboarding.md) (Stage 1, account binding), [database-schema.md](database-schema.md) §M.

## 1. What the candidate does

1. **Stage 3** (`/dashboard/documentation`), after Stages 1 and 2 are confirmed:
   - **Explanatory document** «وثيقة شرح العمل»: required, exactly one. PDF, DOC or DOCX, up to **26,214,400 bytes** (25 MiB). It can be replaced until submission; it cannot be removed.
   - **Supporting documents** «وثائق داعمة إضافية»: optional, up to 20. Same formats and limit, each with an optional title.
   - **Videos** «فيديوهات توثيق العمل»: optional, up to 10. MP4 or WebM, up to **52,428,800 bytes** (50 MiB) each, with an optional title. They play natively; there is no transcoding.
   - **Links** «روابط توثيق إضافية»: optional, up to 20 rows of title and HTTPS address. Addresses are kept as written; their content is never fetched or embedded.
   - «حفظ المرحلة والمتابعة إلى المراجعة» confirms Stage 3. It needs the explanatory document, no file still uploading and no unsaved link change.
2. **Review** (`/dashboard/review`): Stage 1, Stage 2 and Stage 3 as stored, as read-only summaries. «العودة إلى ملفات العمل» goes back to Stage 3 only; Stages 1 and 2 stay read-only.
3. **Submission:** «إرسال للجنة الحكام», then the confirmation dialog («تأكيد الإرسال»). The receipt «تم إرسال المشاركة» shows the reference number and the time in Damascus. From then on the whole participation is view-only, and the owner can still download every file.

Submitting records the application for the committee workflow that follows. It creates **no** judging assignments, evaluations or committee records, and sends no email.

## 2. Availability

Stage 3 and the submission follow the **same cycle stage as Stage 2**: the journey's cycle must be published and its `nomination_details` stage enabled and inside its window. There is **no new activation step**. Closing that stage (`php artisan nomination:setup <cycle> --actor=<email> --close`) closes uploads and submission for drafts; submitted participations stay viewable.

## 3. Files and storage

- **Private disk `candidate_evidence`** (`config/filesystems.php`): `storage/app/private/candidate-evidence`, inside `storage/`, outside the public document root, never served by URL (`serve: false`). No `storage:link` is involved.
- **Generated names:** `applications/<application id>/<uuid>.<ext>`. The original name is metadata only.
- **Checks on every upload** (`App\Candidates\EvidenceInspector`):
  - the real size against the exact byte limits;
  - the extension (`.docm`, `.exe` and anything else not listed are refused);
  - the MIME type detected from the content (`finfo`);
  - the format's structure: PDF header; OLE header for DOC; for DOCX, a Word package without a VBA project or macro-enabled content type; MP4 `ftyp` box; WebM EBML header.
  - For DOCX, only `[Content_Types].xml` is decompressed, and only up to **64 KiB** (`CONTENT_TYPES_MAX_BYTES`; real files are 1–3 KB). The upload limit bounds the compressed archive, not what an entry inflates to. So the entry's declared size is checked first, and the read stops one byte past the limit in case that size is forged. A larger or unreadable entry is refused with the usual file error.

  A renamed executable or web page is refused.
- **Not a malware scan.** Accepted files keep `scan_status = pending`; nothing marks them `clean`. No scanner is configured, and adding one is a separate decision.
- **SHA-256** of every file is stored in `files.sha256`.
- **Downloads:** the owner only, through `GET /api/candidate/documentation/files/{id}` with the page's account context and journey key in the query (a link or `<video>` cannot send headers). Documents are sent as attachments; videos are sent inline with byte ranges, `nosniff`, a sandbox CSP and `no-store`. No online Office viewer is used.
- **Consistency:**
  - bytes are written before the database transaction and deleted again if it refuses or fails;
  - bytes of replaced or removed drafts are deleted only after the commit;
  - bytes referenced by a submitted version are never deleted.

## 4. Saving, retries and the lock

- **One file per request** (multipart). The browser gives each file an operation identifier (`files.upload_operation_id`, unique per uploader). If an answer is lost, the page re-reads the saved list; a retry with the same identifier returns the stored attachment and never adds it twice.
- **Recovery reads are bound to the page's journey.**
  - When an upload or submission answer is lost, the page re-reads `GET /api/candidate/documentation/journey` or `GET /api/candidate/review/journey`, sending its own `journey_key` in the query and its account context in the header.
  - The server resolves exactly that owned journey, even after its cycle closed, or answers `409 journey_changed`. It never substitutes the journey of the cycle that is current now.
  - The page accepts the answer only if the same `journey_key` comes back. Otherwise it keeps what it showed, says the outcome is unknown, and sends nothing again by itself.
  - Opening the pages normally still shows the journey of the current cycle.
- **Deadlines:**
  - an upload has a bounded 5-minute deadline (`UPLOAD_TIMEOUT_MS`);
  - every other request keeps 20 seconds;
  - a timed-out upload or submission is treated as unknown and reconciled, never reported as failed.
- **Lock order:** every Stage 3 write and the submission use one transaction with the platform's lock order: the account's `users` row, then the configuration (`award_cycles`), then the journey and the application. Inside it they recheck that the application is an unsubmitted draft and the stage is open. An upload that started before the submission and finishes after it is refused, and its bytes are deleted.
- **Revisions:** every change to attachments or links raises `candidate_nominations.documentation_revision`. Stage 3 is confirmed for one revision, and the submission is bound to the revision the candidate reviewed. A change from another tab after the review returns `409 review_outdated`, and the candidate reviews again.
- **Submission** (`App\Candidates\Submissions`):
  - **Conflict-of-interest bar** ([database-rules.md](database-rules.md) §3), checked inside the transaction before anything is written:
    - **Who is refused:** an account with a current membership (`committee_members.left_at` empty) in a `judging`, `integrity` or `trustees` committee of the application's cycle, unless that committee is `dissolved`. Draft committees count.
    - **Answer:** `403 committee_member`, with nothing written.
    - **Not checked:**
      - award-management roles, because the repository defines no such role code yet (only `candidate`); add the check when that role exists;
      - other roles, which are not a reason to refuse.
  - **Application:** keeps the existing application; sets `status = submitted`, `first_submitted_at` and `current_version_id`; assigns `reference_number` `SA-<cycle year>-<6 characters>`.
  - **Version:** writes `application_versions` version 1 (`initial`) with:
    - `data_snapshot`: the Stage 2 data as shown, the documentation manifest (names, types, sizes, SHA-256, scan status) and the links;
    - Stage 1 references only: profile and applicant ids, profile type, applicant name and confirmation times. No identity number or other encrypted field is copied;
    - the accepted account-policy versions, with a SHA-256 of their text;
    - `form_snapshot`: the stages and limits;
    - `content_hash`: SHA-256 of the canonical snapshot.
  - **Files:** every attached file also gets a `file_links` row for the version (same bytes, one target per row).
  - **Audit:** one `application.submitted` entry.
- **Retries:** a double click, another tab or a lost answer returns **the same receipt** (`200 already_submitted`); there is never a second version.
- **After submission** every upload, replacement, removal, link change and Stage 3 confirmation is refused by the server (`409 application_submitted`). Reads and downloads keep working for the owner.

## 5. Deployment

1. Back up the database.
2. `php artisan migrate --force`: one additive migration, `2026_10_27_500001_add_candidate_documentation` (`files.upload_operation_id`, `application_documentation_links`, three columns on `candidate_nominations`). Earlier migrations are unchanged.
3. Build and publish the frontend as today.
4. **PHP and web-server limits** (Plesk; *not* changed by this PR, set them in the domain's PHP settings and nginx/Apache directives):

   | Setting | Value | Why |
   | --- | --- | --- |
   | `upload_max_filesize` | `64M` | allows one 50 MiB video |
   | `post_max_size` | `64M` | the file plus multipart overhead |
   | nginx `client_max_body_size` (if nginx proxies) | `64m` | otherwise nginx answers 413 before PHP |
   | `max_execution_time` / `max_input_time` | at least `300` | the 5-minute upload deadline |
   | nginx `proxy_read_timeout` / `fastcgi_read_timeout` | at least `300s` | same |

   Requests over these limits get an understandable message in the page: Laravel's 413 for `post_max_size`, and the client's 413 handling for an upstream nginx 413 page.
5. PHP extensions `fileinfo` and `zip` must be enabled (standard on Plesk PHP 8.4); the inspector needs both.
6. The disk directory is created on first upload, under `storage/` (already writable by the application).

No `.env`, Document Root or `deploy.sh` change is needed.

## 6. Limitations

- Legacy `.doc` files can contain macros; their structure is checked (OLE), but macros inside a `.doc` are not detected. DOCX macros are refused.
- There is no malware scanning (files stay `pending`). Staff who open candidate files later should treat them as untrusted.
- Links are not checked for reachability, and their content may change or disappear after submission; only the address and title are frozen.
- One journey per candidate profile and cycle (unchanged from Stage 2). There is no reopening or correction workflow after submission.
- The conflict-of-interest bar covers committee memberships only (see §4); there is no award-management role to check yet, and no eligibility-review workflow.
