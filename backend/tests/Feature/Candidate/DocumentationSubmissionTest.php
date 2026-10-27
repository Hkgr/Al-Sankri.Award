<?php

namespace Tests\Feature\Candidate;

use App\Candidates\AccountContext;
use App\Candidates\Documentation;
use App\Candidates\Submissions;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;
use ZipArchive;

/**
 * Stage 3 («ملفات العمل وتوثيقه»), the read-only review and the final
 * submission: file checks with exact limits, ownership and bindings, draft
 * management, one immutable submitted version, idempotent retries, stale
 * reviews, and the permanent lock after submission.
 */
class DocumentationSubmissionTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage, RefreshDatabase;

    private const DOCUMENT_LIMIT = 26_214_400;

    private const VIDEO_LIMIT = 52_428_800;

    private const NATIONAL_ID = '04019876543';

    private string $docKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('candidate_evidence');
        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'operator@example.org']);
        $this->candidate = User::factory()->candidate()->create(['email' => 'layla@example.org']);
    }

    public function test_stage_3_needs_stages_1_and_2(): void
    {
        $this->configureCycle();
        $this->signIn();
        $this->read('/api/candidate/documentation')->assertConflict()->assertJsonPath('code', 'basic_information_required');

        $this->completeStage1($this->candidate);
        $this->read('/api/candidate/documentation')->assertOk()->assertJsonPath('availability.status', 'nomination_details_required');
        $this->read('/api/candidate/overview')->assertJsonPath('data.documentation.status', 'locked');
        $this->upload('explanatory', $this->pdf(), key: $this->journeyKey())->assertConflict()->assertJsonPath('code', 'journey_changed');

        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $this->docKey = (string) $this->read('/api/candidate/documentation')->assertOk()->json('journey_key');
        $this->read('/api/candidate/overview')->assertJsonPath('data.documentation.status', 'not_started')->assertJsonPath('data.submission', null);
    }

    public function test_files_are_checked_by_type_structure_and_exact_size(): void
    {
        $this->readyForDocumentation();

        foreach ([$this->pdf(), $this->doc(), $this->docx()] as $file) {
            $this->upload('supporting', $file)->assertCreated();
        }
        $this->upload('video', $this->file('clip.mp4', "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2", 4096))->assertCreated();
        $this->upload('video', $this->webm())->assertCreated();

        // Exact limits: 25 MiB documents, 50 MiB videos.
        $this->upload('explanatory', $this->pdf(self::DOCUMENT_LIMIT))->assertCreated()->assertJsonPath('data.attachments.5.size_bytes', self::DOCUMENT_LIMIT);
        $this->upload('supporting', $this->pdf(self::DOCUMENT_LIMIT + 1))->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload('video', $this->file('long.mp4', "\x00\x00\x00\x18ftypisom", self::VIDEO_LIMIT))->assertCreated();
        $this->upload('video', $this->file('too-long.mp4', "\x00\x00\x00\x18ftypisom", self::VIDEO_LIMIT + 1))->assertUnprocessable();

        // Wrong family, renamed executables and pages, macros, other formats.
        $refused = [
            ['supporting', $this->file('program.pdf', "MZ\x90\x00", 2048)],
            ['supporting', $this->file('page.pdf', '<html><script>alert(1)</script></html>', 0)],
            ['supporting', $this->docx(withMacros: true)],
            ['supporting', $this->file('macro.docm', "PK\x03\x04", 2048)],
            ['supporting', $this->file('tool.exe', "MZ\x90\x00", 2048)],
            ['video', $this->pdf(name: 'video.mp4')],
            ['video', $this->file('clip.mov', "\x00\x00\x00\x14ftypqt  ", 2048)],
            ['explanatory', $this->file('empty.pdf', '', 0)],
        ];
        foreach ($refused as [$kind, $file]) {
            $this->upload($kind, $file)->assertUnprocessable()->assertJsonValidationErrors(['file']);
        }

        $files = DB::table('files')->get();
        $this->assertCount(7, $files);
        $this->assertCount(7, Storage::disk('candidate_evidence')->allFiles(), 'refused uploads leave no bytes');
        foreach ($files as $file) {
            $this->assertSame('pending', $file->scan_status, 'type checks are not a malware scan');
            $this->assertMatchesRegularExpression('#^applications/\d+/[0-9a-f-]{36}\.(pdf|doc|docx|mp4|webm)$#', $file->storage_path);
            $this->assertSame(hash_file('sha256', Storage::disk('candidate_evidence')->path($file->storage_path)), $file->sha256);
        }
    }

    public function test_draft_attachments_and_links_are_managed_and_persist(): void
    {
        $this->readyForDocumentation();

        $this->confirmStage3(0)->assertUnprocessable()->assertJsonPath('code', 'explanatory_document_required');
        $first = $this->upload('explanatory', $this->pdf(name: 'الشرح الأول.pdf'))->assertCreated()->json('data.attachments.0');
        $replacement = $this->upload('explanatory', $this->docx())->assertCreated()->json('data.attachments');
        $this->assertCount(1, $replacement, 'the explanatory document is replaced, not doubled');
        $this->assertSame(1, DB::table('files')->count(), 'the replaced file and its bytes are gone');
        $this->assertCount(1, Storage::disk('candidate_evidence')->allFiles());
        $this->send('/api/candidate/documentation/files/remove', ['attachment' => $replacement[0]['id']], $this->docKey)
            ->assertUnprocessable()->assertJsonPath('code', 'explanatory_document_replace_only');
        $this->send('/api/candidate/documentation/files/remove', ['attachment' => $first['id']], $this->docKey)->assertNotFound();

        $supporting = $this->upload('supporting', $this->pdf(), title: '  تقرير   الأثر ')->assertCreated()->json('data.attachments.1');
        $this->assertSame('تقرير الأثر', $supporting['title']);
        $video = $this->upload('video', $this->webm())->assertCreated()->json('data.attachments.2');
        $this->send('/api/candidate/documentation/files/remove', ['attachment' => $supporting['id']], $this->docKey)->assertOk()->assertJsonCount(2, 'data.attachments');

        // Links: HTTPS only, no credentials, kept as supplied.
        foreach (['javascript:alert(1)', 'http://example.org', 'https://user:secret@example.org/x', 'data:text/html,hi', 'https://', 'ftp://example.org/a'] as $bad) {
            $this->send('/api/candidate/documentation/links', ['links' => [['title' => 'رابط', 'url' => $bad]]], $this->docKey)
                ->assertUnprocessable()->assertJsonValidationErrors(['links.0.url']);
        }
        $links = [['title' => 'فيديو التوثيق', 'url' => 'https://www.youtube.com/watch?v=abc&t=10'], ['title' => 'موقع المبادرة', 'url' => 'https://example.org/مبادرة']];
        $this->send('/api/candidate/documentation/links', ['links' => $links], $this->docKey)->assertOk();

        // Survives a refresh; every change raised the revision.
        $state = $this->read('/api/candidate/documentation')->assertOk();
        $this->assertSame($links, $state->json('data.links'));
        $this->assertSame(['explanatory', 'video'], array_column($state->json('data.attachments'), 'kind'));
        $revision = (int) $state->json('data.revision');
        $this->assertSame(6, $revision);

        $this->confirmStage3($revision - 1)->assertConflict()->assertJsonPath('code', 'documentation_changed');
        $this->confirmStage3($revision)->assertOk()->assertJsonPath('data.confirmed', true);
        $this->read('/api/candidate/overview')->assertJsonPath('data.documentation.status', 'completed');
        $this->send('/api/candidate/documentation/links', ['links' => []], $this->docKey)->assertOk()->assertJsonPath('data.confirmed', false);

        // Authenticated downloads: documents as attachments, videos inline.
        $download = $this->download($replacement[0]['id'])->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', (string) $this->download($video['id'])->assertOk()->headers->get('Content-Disposition'));
    }

    public function test_files_belong_to_their_owner_and_requests_to_their_account_and_journey(): void
    {
        $this->readyForDocumentation();
        $mine = (int) $this->upload('explanatory', $this->pdf())->assertCreated()->json('data.attachments.0.id');
        $contextOfA = AccountContext::for($this->candidate);
        $keyOfA = $this->docKey;

        $other = User::factory()->candidate()->create(['email' => 'other@example.org']);
        $this->completeStage1($other);
        $this->withIndividualDetails($other);
        $this->signIn($other->email);
        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $this->docKey = (string) $this->read('/api/candidate/documentation')->json('journey_key');

        // B, with B's own context and key, cannot reach A's file.
        $this->download($mine)->assertNotFound();
        $this->send('/api/candidate/documentation/files/remove', ['attachment' => $mine], $this->docKey)->assertNotFound();
        // A's page in B's session: refused before anything is read or written.
        $this->browser('GET', "/api/candidate/documentation/files/{$mine}", ['account_context' => $contextOfA, 'journey_key' => $keyOfA])
            ->assertConflict()->assertJsonPath('code', 'account_mismatch');
        $this->upload('supporting', $this->pdf(), context: $contextOfA, key: $keyOfA)->assertConflict()->assertJsonPath('code', 'account_mismatch');
        // B's context with A's journey key: not B's journey.
        $this->upload('supporting', $this->pdf(), key: $keyOfA)->assertConflict()->assertJsonPath('code', 'journey_changed');
        $this->read('/api/candidate/review')->assertOk()->assertJsonPath('data.documentation.attachments', []);
        $this->assertSame(1, DB::table('file_links')->count());
    }

    public function test_the_reviewed_participation_is_submitted_once_as_an_immutable_version(): void
    {
        $this->readyForDocumentation();
        $this->upload('explanatory', $this->pdf())->assertCreated();
        $this->upload('video', $this->webm(), title: 'زيارة الحي')->assertCreated();
        $this->send('/api/candidate/documentation/links', ['links' => [['title' => 'معرض الصور', 'url' => 'https://example.org/gallery']]], $this->docKey)->assertOk();
        $revision = (int) $this->confirmStage3(3)->assertOk()->json('data.revision');

        $review = $this->read('/api/candidate/review')->assertOk();
        $this->assertSame($revision, $review->json('data.documentation.revision'));
        $this->assertSame('1500.50', $review->json('data.nomination_details.funding.amount_usd'));
        $this->assertNotNull($review->json('data.basic_information'));

        $receipt = $this->submit($revision)->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^SA-2026-[A-HJ-NP-Z2-9]{6}$/', $receipt['reference_number']);
        $this->assertSame(1, $receipt['version_number']);

        $application = DB::table('applications')->sole();
        $version = DB::table('application_versions')->sole();
        $this->assertSame(['submitted', $receipt['reference_number'], (int) $version->id], [$application->status, $application->reference_number, (int) $application->current_version_id]);
        $this->assertNotNull($application->first_submitted_at);
        $this->assertSame(['initial', 1], [$version->submission_type, (int) $version->version_number]);
        $this->assertStringNotContainsString(self::NATIONAL_ID, $version->data_snapshot, 'no identity number in the snapshot');
        $snapshot = json_decode($version->data_snapshot, true);
        $this->assertSame(CanonicalJson::hash($snapshot), $version->content_hash);
        $this->assertSame(['https://example.org/gallery'], array_column($snapshot['documentation']['links'], 'url'));
        $this->assertSame(['explanatory', 'video'], array_column($snapshot['documentation']['files'], 'kind'));
        $this->assertSame('1500.50', $snapshot['nomination_details']['funding']['amount_usd']);
        $this->assertSame(['candidate_profile_id', 'applicant_id', 'profile_type', 'applicant_name', 'confirmed_at', 'data_accuracy_declared_at'], array_keys($snapshot['basic_information']));
        $this->assertSame(2, DB::table('file_links')->where('application_version_id', $version->id)->count(), 'the same files are linked to the version');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'application.submitted')->count());
        foreach (['judging_assignments', 'evaluations', 'eligibility_reviews'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table}: nothing is sent to judging");
        }

        $this->read('/api/candidate/review')->assertJsonPath('data.submission.reference_number', $receipt['reference_number']);
        $this->read('/api/candidate/overview')->assertJsonPath('data.documentation.status', 'submitted')
            ->assertJsonPath('data.submission.reference_number', $receipt['reference_number']);
    }

    public function test_a_retry_returns_the_same_receipt_and_a_stale_review_is_refused(): void
    {
        $this->readyForDocumentation();
        $this->upload('explanatory', $this->pdf())->assertCreated();
        $this->confirmStage3(1)->assertOk();

        // Another tab adds a link after the review was opened.
        $this->send('/api/candidate/documentation/links', ['links' => [['title' => 'رابط', 'url' => 'https://example.org']]], $this->docKey)->assertOk();
        $this->submit(1)->assertConflict()->assertJsonPath('code', 'review_outdated');
        $this->submit(2)->assertConflict()->assertJsonPath('code', 'review_outdated');
        $this->assertSame(0, DB::table('application_versions')->count());

        $this->confirmStage3(2)->assertOk();
        $first = $this->submit(2)->assertCreated()->json('data');
        // A double click, a lost answer, another tab: the same receipt.
        $this->assertSame($first, $this->submit(2)->assertOk()->assertJsonPath('code', 'already_submitted')->json('data'));
        $this->assertSame($first, $this->submit(1)->assertOk()->json('data'));
        $this->assertSame([1, 1], [DB::table('application_versions')->count(), DB::table('applications')->whereNotNull('reference_number')->count()]);
    }

    public function test_every_documentation_change_is_refused_after_submission(): void
    {
        $this->readyForDocumentation();
        $document = (int) $this->upload('explanatory', $this->pdf())->assertCreated()->json('data.attachments.0.id');
        $video = (int) $this->upload('video', $this->webm())->assertCreated()->json('data.attachments.1.id');
        $this->confirmStage3(2)->assertOk();

        // An upload that started before the submission and finishes after it:
        // its bytes are stored, then the submission commits first.
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && DB::transactionLevel() > 1 && str_contains($query->sql, 'users')) {
                $armed = false;
                $journey = app(Documentation::class)->journeyOf($this->candidate);
                [$outcome] = app(Submissions::class)->submit($this->candidate, $journey, 2);
                $this->assertSame(Submissions::SUBMITTED, $outcome);
            }
        });
        $this->upload('supporting', $this->pdf())->assertConflict()->assertJsonPath('code', 'application_submitted');
        $this->assertFalse($armed);
        $this->assertCount(2, Storage::disk('candidate_evidence')->allFiles(), 'the late upload left no bytes');

        $before = $this->snapshotTables();
        $this->upload('supporting', $this->pdf())->assertConflict()->assertJsonPath('code', 'application_submitted');
        $this->upload('explanatory', $this->docx())->assertConflict()->assertJsonPath('code', 'application_submitted');
        $this->send('/api/candidate/documentation/files/remove', ['attachment' => $video], $this->docKey)->assertConflict()->assertJsonPath('code', 'application_submitted');
        $this->send('/api/candidate/documentation/links', ['links' => [['title' => 'رابط', 'url' => 'https://example.org']]], $this->docKey)->assertConflict();
        $this->confirmStage3(2)->assertConflict()->assertJsonPath('code', 'application_submitted');
        $this->assertEquals($before, $this->snapshotTables());

        // The owner can still read everything.
        $this->download($document)->assertOk();
        $this->download($video)->assertOk();
        $this->read('/api/candidate/documentation')->assertOk()->assertJsonPath('data.editable', false)->assertJsonCount(2, 'data.attachments');
    }

    // ---------- helpers ----------

    private function readyForDocumentation(): void
    {
        $this->configureCycle();
        $this->completeStage1($this->candidate);
        $this->withIndividualDetails($this->candidate);
        $this->signIn();
        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $this->docKey = (string) $this->read('/api/candidate/documentation')->assertOk()->json('journey_key');
    }

    /**
     * The person's details that the Stage 1 summary shows (the shared
     * completeStage1 helper inserts the profile only).
     */
    private function withIndividualDetails(User $user): void
    {
        $applicantId = (int) DB::table('candidate_profiles')->where('user_id', $user->id)->value('applicant_id');
        (new ApplicantIndividualDetail)->forceFill([
            'applicant_id' => $applicantId, 'first_name' => 'ليلى', 'last_name' => 'حسن', 'birth_date' => '1990-04-02',
            'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => self::NATIONAL_ID,
        ])->save();
    }

    private function upload(string $kind, UploadedFile $file, ?string $title = null, ?string $key = null, ?string $context = null): TestResponse
    {
        $this->travel(3)->seconds();

        return $this->browser('POST', '/api/candidate/documentation/files', array_filter([
            'kind' => $kind,
            'title' => $title,
            'operation_id' => (string) Str::uuid(),
            'account_context' => $context ?? AccountContext::for($this->signedIn),
            'journey_key' => $key ?? $this->docKey,
        ], fn ($value): bool => $value !== null), files: ['file' => $file]);
    }

    private function confirmStage3(int $revision): TestResponse
    {
        return $this->send('/api/candidate/documentation/confirm', ['revision' => $revision], $this->docKey);
    }

    private function submit(int $revision): TestResponse
    {
        return $this->send('/api/candidate/submission', ['reviewed_revision' => $revision], $this->docKey);
    }

    private function download(int $attachment): TestResponse
    {
        return $this->browser('GET', "/api/candidate/documentation/files/{$attachment}", [
            'account_context' => AccountContext::for($this->signedIn),
            'journey_key' => $this->docKey,
        ]);
    }

    /**
     * A file of exactly $bytes bytes (at least its head), padded with zeros
     * on disk, never held in memory.
     */
    private function file(string $name, string $head, int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sa-test-');
        file_put_contents($path, $head);
        if ($bytes > strlen($head)) {
            $handle = fopen($path, 'r+');
            ftruncate($handle, $bytes);
            fclose($handle);
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(int $bytes = 2048, string $name = 'شرح العمل.pdf'): UploadedFile
    {
        return $this->file($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", $bytes);
    }

    private function doc(): UploadedFile
    {
        return $this->file('وثيقة.doc', hex2bin('D0CF11E0A1B11AE1').str_repeat("\0", 504).str_repeat('x', 1024), 4096);
    }

    private function webm(): UploadedFile
    {
        return $this->file('clip.webm', "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02", 4096);
    }

    private function docx(bool $withMacros = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sa-docx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document/>');
        if ($withMacros) {
            $zip->addFromString('word/vbaProject.bin', 'macro');
        }
        $zip->close();

        return new UploadedFile($path, 'شرح.docx', null, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotTables(): array
    {
        return collect(['files', 'file_links', 'application_documentation_links', 'candidate_nominations', 'applications', 'application_versions'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all() + ['bytes' => Storage::disk('candidate_evidence')->allFiles()];
    }
}
