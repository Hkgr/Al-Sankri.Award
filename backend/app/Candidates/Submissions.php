<?php

namespace App\Candidates;

use App\Admin\AdminAccess;
use App\Http\Resources\NominationDetailsResource;
use App\Judging\Assignments;
use App\Models\Application;
use App\Models\ApplicationVersion;
use App\Models\CandidateNomination;
use App\Models\CandidateProfile;
use App\Models\FileLink;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/**
 * The final submission of the candidate journey («إرسال للجنة الحكام»).
 *
 * One transaction, in the shared lock order (Documentation::lockForChange):
 * it checks again that the application is an unsubmitted draft, the cycle's
 * nomination stage is open, Stage 3 was confirmed for the revision the
 * candidate reviewed and that revision is still the current one, and that
 * the account sits on none of the cycle's committees. Then it
 * writes immutable version 1 (`initial`) with the data and form snapshots
 * and their canonical SHA-256, links every attached file to that version
 * as well (same bytes, one target per link row), assigns a reference
 * number, and sets the application to `submitted`.
 *
 * A retry (double click, second tab, lost answer) finds the version and
 * returns the same receipt. Nothing is sent to judging: no assignments,
 * evaluations or committee records are created.
 *
 * The snapshot references Stage 1 by identifiers (profile, applicant,
 * accepted policy versions with a hash of their text); it never copies
 * identity numbers or other encrypted personal fields.
 */
class Submissions
{
    public const SUBMITTED = 'submitted';

    public const ALREADY_SUBMITTED = 'already_submitted';

    public const REVIEW_OUTDATED = 'review_outdated';

    public const COMMITTEE_MEMBER = 'committee_member';

    public const AWARD_ADMIN = 'award_admin';

    /** Committee types whose members may not apply in the same cycle (committees.type). */
    public const EXCLUDING_COMMITTEES = ['judging', 'integrity', 'trustees'];

    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Documentation $documentation, private readonly AdminAccess $access) {}

    /**
     * @return array{0: string, 1: array{reference_number: string, submitted_at: string, version_number: int}|null}
     */
    public function submit(User $user, CandidateNomination $journey, int $reviewedRevision): array
    {
        return DB::transaction(function () use ($user, $journey, $reviewedRevision): array {
            [$refusal, $journey, $application] = $this->documentation->lockForChange($user, $journey);

            if ($refusal === Documentation::SUBMITTED) {
                return $application->current_version_id !== null ? [self::ALREADY_SUBMITTED, $this->receipt($application)] : [$refusal, null];
            }
            if ($refusal !== null) {
                return [$refusal, null];
            }
            // Read after the users row is locked: an appointment of this
            // account (JudgingCommittees) holds the same lock, so its
            // committed result is what is seen here.
            if ($this->access->holdsAdminRole((int) $user->getKey())) {
                return [self::AWARD_ADMIN, null];
            }
            if ($this->access->isJudge((int) $user->getKey(), (int) $application->cycle_id) || $this->sitsOnCycleCommittee($user, (int) $application->cycle_id)) {
                return [self::COMMITTEE_MEMBER, null];
            }
            if ($reviewedRevision !== $journey->documentation_revision || ! $journey->documentationConfirmed()) {
                return [self::REVIEW_OUTDATED, null];
            }

            $links = FileLink::query()->with('file')->where('application_id', $application->getKey())
                ->whereIn('purpose', array_column(Documentation::KINDS, 'purpose'))->orderBy('display_order')->orderBy('id')->get();
            if (! $links->contains('purpose', Documentation::KINDS['explanatory']['purpose'])) {
                return [Documentation::MAIN_DOCUMENT_REQUIRED, null];
            }

            $now = now();
            $reference = $this->newReference((int) DB::table('award_cycles')->where('id', $application->cycle_id)->value('year'));
            $snapshot = $this->snapshot($user, $journey, $application, $links->all(), $reference);

            $version = new ApplicationVersion;
            $version->forceFill([
                'application_id' => $application->getKey(),
                'version_number' => 1,
                'submission_type' => ApplicationVersion::INITIAL,
                'submitted_by' => $user->getKey(),
                'submitted_at' => $now,
                'data_snapshot' => $snapshot,
                'form_snapshot' => $this->formSnapshot(),
                'content_hash' => CanonicalJson::hash($snapshot),
                'created_at' => $now,
            ])->save();

            // The same files, now also attached to the immutable version.
            foreach ($links as $link) {
                (new FileLink)->forceFill([
                    'file_id' => $link->file_id,
                    'application_version_id' => $version->getKey(),
                    'purpose' => $link->purpose,
                    'caption' => $link->caption,
                    'display_order' => $link->display_order,
                ])->save();
            }

            $application->forceFill([
                'status' => Application::STATUS_SUBMITTED,
                'reference_number' => $reference,
                'first_submitted_at' => $now,
                'current_version_id' => $version->getKey(),
            ])->save();

            AuditLog::record('application.submitted', (int) $user->getKey(), 'application', (int) $application->getKey(), [
                'application_version_id' => $version->getKey(),
                'reference_number' => $reference,
                'documentation_revision' => $journey->documentation_revision,
            ], (int) $application->cycle_id);

            app(Assignments::class)->provisionApplication((int) $application->getKey());

            return [self::SUBMITTED, $this->receipt($application->refresh())];
        });
    }

    /**
     * The conflict-of-interest bar (database-rules.md §3), with the checks
     * above: a current `award_admin` grant (any cycle) and a current `judge`
     * grant of this cycle refuse too. Here: a current member (not left) of a
     * judging, integrity or trustees committee of the application's cycle
     * that is not dissolved may not apply. Other roles (the candidate
     * classification included) are not a reason to refuse.
     */
    private function sitsOnCycleCommittee(User $user, int $cycleId): bool
    {
        return DB::table('committee_members as members')
            ->join('committees', 'committees.id', '=', 'members.committee_id')
            ->where('members.user_id', $user->getKey())
            ->whereNull('members.left_at')
            ->where('committees.cycle_id', $cycleId)
            ->whereIn('committees.type', self::EXCLUDING_COMMITTEES)
            ->where('committees.status', '<>', 'dissolved')
            ->exists();
    }

    /**
     * The receipt of a submitted application, or null for a draft.
     *
     * @return array{reference_number: string, submitted_at: string, version_number: int}|null
     */
    public function receipt(Application $application): ?array
    {
        if ($application->current_version_id === null) {
            return null;
        }

        $version = ApplicationVersion::query()->findOrFail($application->current_version_id);

        return [
            'reference_number' => (string) $application->reference_number,
            'submitted_at' => $version->submitted_at->toIso8601ZuluString(),
            'version_number' => $version->version_number,
        ];
    }

    /**
     * @param  list<FileLink>  $links
     * @return array<string, mixed>
     */
    private function snapshot(User $user, CandidateNomination $journey, Application $application, array $links, string $reference): array
    {
        $profile = CandidateProfile::query()->findOrFail($journey->candidate_profile_id);
        $kindOf = array_flip(array_map(fn (array $kind): string => $kind['purpose'], Documentation::KINDS));

        return [
            'schema' => 'candidate-submission/1',
            'application' => [
                'id' => (int) $application->getKey(),
                'reference_number' => $reference,
                'cycle_id' => (int) $application->cycle_id,
                'version_number' => 1,
            ],
            'basic_information' => [
                'candidate_profile_id' => (int) $profile->getKey(),
                'applicant_id' => (int) $profile->applicant_id,
                'profile_type' => $profile->profile_type,
                'applicant_name' => DB::table('applicants')->where('id', $profile->applicant_id)->value('name_ar'),
                'confirmed_at' => $profile->completed_at?->toIso8601ZuluString(),
                'data_accuracy_declared_at' => $profile->data_accuracy_declared_at?->toIso8601ZuluString(),
            ],
            'accepted_policies' => DB::table('account_policy_acceptances as acceptances')
                ->join('account_policy_versions as versions', 'versions.id', '=', 'acceptances.policy_version_id')
                ->where('acceptances.user_id', $user->getKey())
                ->orderBy('versions.code')->orderBy('versions.version_number')
                ->get(['acceptances.id', 'acceptances.accepted_at', 'versions.id as version_id', 'versions.code', 'versions.version_number', 'versions.body_ar'])
                ->map(fn (object $row): array => [
                    'acceptance_id' => (int) $row->id,
                    'policy_version_id' => (int) $row->version_id,
                    'code' => $row->code,
                    'version_number' => (int) $row->version_number,
                    'text_sha256' => hash('sha256', (string) $row->body_ar),
                    'accepted_at' => $row->accepted_at,
                ])->all(),
            'nomination_details' => (new NominationDetailsResource($journey))->resolve(),
            'documentation' => [
                'revision' => (int) $journey->documentation_revision,
                'confirmed_at' => $journey->documentation_confirmed_at?->toIso8601ZuluString(),
                'files' => array_map(fn (FileLink $link): array => [
                    'file_id' => (int) $link->file_id,
                    'kind' => $kindOf[$link->purpose],
                    'title' => $link->caption,
                    'original_name' => $link->file->original_name,
                    'mime_type' => $link->file->detected_mime_type,
                    'size_bytes' => (int) $link->file->size_bytes,
                    'sha256' => $link->file->sha256,
                    'scan_status' => $link->file->scan_status,
                ], $links),
                'links' => DB::table('application_documentation_links')->where('application_id', $application->getKey())->orderBy('position')
                    ->get(['title', 'url'])->map(fn (object $link): array => ['title' => $link->title, 'url' => $link->url])->all(),
            ],
        ];
    }

    /**
     * The form the candidate filled in, as it stood: its stages and the
     * documentation limits.
     *
     * @return array<string, mixed>
     */
    private function formSnapshot(): array
    {
        return [
            'schema' => 'candidate-journey/1',
            'stages' => ['basic_information', 'nomination_details', 'documentation'],
            'documentation' => [
                'document_extensions' => EvidenceInspector::KINDS['document']['extensions'],
                'document_max_bytes' => EvidenceInspector::DOCUMENT_MAX_BYTES,
                'video_extensions' => EvidenceInspector::KINDS['video']['extensions'],
                'video_max_bytes' => EvidenceInspector::VIDEO_MAX_BYTES,
                'kinds' => array_map(fn (array $kind): array => ['purpose' => $kind['purpose'], 'max' => $kind['max']], Documentation::KINDS),
            ],
        ];
    }

    /**
     * SA-<cycle year>-<six characters>, unguessable and unique
     * (uq_applications_reference is the final guard).
     */
    private function newReference(int $year): string
    {
        do {
            $code = '';
            for ($index = 0; $index < 6; $index++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
            $reference = "SA-{$year}-{$code}";
        } while (Application::query()->where('reference_number', $reference)->exists());

        return $reference;
    }
}
