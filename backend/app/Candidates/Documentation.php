<?php

namespace App\Candidates;

use App\Models\Application;
use App\Models\CandidateNomination;
use App\Models\CandidateProfile;
use App\Models\DocumentationLink;
use App\Models\FileLink;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stage 3 of the candidate journey: «ملفات العمل وتوثيقه».
 *
 * Works on the application of the candidate's confirmed Stage 2 journey,
 * never on another. Until the final submission the candidate may add,
 * replace and remove attachments and edit the links; every change raises
 * the journey's documentation revision, which un-confirms Stage 3.
 *
 * Every change runs in one transaction with the lock order shared by the
 * Stage 2 confirmation, nomination:setup and the final submission: the
 * account's users row, then the configuration (award_cycles), then the
 * journey and its application. Inside it the application must still be an
 * unsubmitted draft and the cycle's nomination stage open, so a change
 * that loses the race with the submission is refused.
 *
 * Files are inspected (EvidenceInspector) and written to the private disk
 * before the transaction, under a generated name; bytes are removed again
 * if the transaction refuses or fails, and bytes of replaced or removed
 * attachments only after it has committed. Bytes referenced by a submitted
 * version are never removed.
 */
class Documentation
{
    public const DISK = 'candidate_evidence';

    /**
     * Kinds of attachment: their file_links purpose, format family and the
     * maximum number per application.
     *
     * @var array<string, array{purpose: string, format: string, max: int}>
     */
    public const KINDS = [
        'explanatory' => ['purpose' => 'explanatory_document', 'format' => 'document', 'max' => 1],
        'supporting' => ['purpose' => 'supporting_document', 'format' => 'document', 'max' => 20],
        'video' => ['purpose' => 'documentation_video', 'format' => 'video', 'max' => 10],
    ];

    public const STORED = 'stored';

    public const ALREADY_STORED = 'already_stored';

    public const INVALID_FILE = 'invalid_file';

    public const TOO_MANY = 'too_many_attachments';

    public const NOT_FOUND = 'attachment_not_found';

    public const MAIN_DOCUMENT_REQUIRED = 'explanatory_document_required';

    public const MAIN_DOCUMENT_REPLACE_ONLY = 'explanatory_document_replace_only';

    public const SAVED = 'saved';

    public const CONFIRMED = 'confirmed';

    public const REVISION_CHANGED = 'documentation_changed';

    public const SUBMITTED = 'application_submitted';

    public const UNAVAILABLE = 'documentation_unavailable';

    /** Dashboard states. */
    public const STAGE_LOCKED = 'locked';

    public const STAGE_UNAVAILABLE = 'unavailable';

    public const STAGE_NOT_STARTED = 'not_started';

    public const STAGE_IN_PROGRESS = 'in_progress';

    public const STAGE_COMPLETED = 'completed';

    public const STAGE_SUBMITTED = 'submitted';

    public function __construct(
        private readonly NominationDetails $nominations,
        private readonly NominationCycles $cycles,
        private readonly EvidenceInspector $inspector,
    ) {}

    /**
     * The journey Stage 3 works on: the account's confirmed Stage 2 (of the
     * usable cycle, or the only one), as the Stage 2 page shows it.
     */
    public function journeyOf(User $user): ?CandidateNomination
    {
        $profile = $this->nominations->profileOf($user);

        return $profile === null ? null : $this->nominations->confirmedFor($profile, $this->cycles->current()['cycle']);
    }

    public function journeyKey(CandidateNomination $journey): string
    {
        return $this->cycles->journeyKey(CandidateProfile::query()->findOrFail($journey->candidate_profile_id), (int) $journey->cycle_id);
    }

    /**
     * Whether the journey's application may still change now.
     */
    public function editable(CandidateNomination $journey): bool
    {
        $application = Application::query()->findOrFail($journey->application_id);

        return $application->isEditable() && $this->cycles->stageOpenFor((int) $journey->cycle_id);
    }

    /**
     * Stage 3 for the dashboard: statuses and dates only.
     *
     * @return array{status: string, confirmed_at: string|null}
     */
    public function stage(User $user): array
    {
        $journey = $this->journeyOf($user);

        if ($journey === null) {
            return ['status' => self::STAGE_LOCKED, 'confirmed_at' => null];
        }

        $application = Application::query()->findOrFail($journey->application_id);
        $confirmedAt = $journey->documentationConfirmed() ? $journey->documentation_confirmed_at?->toIso8601ZuluString() : null;

        $status = match (true) {
            ! $application->isEditable() => self::STAGE_SUBMITTED,
            ! $this->cycles->stageOpenFor((int) $journey->cycle_id) => self::STAGE_UNAVAILABLE,
            $confirmedAt !== null => self::STAGE_COMPLETED,
            $journey->documentation_revision > 0 => self::STAGE_IN_PROGRESS,
            default => self::STAGE_NOT_STARTED,
        };

        return ['status' => $status, 'confirmed_at' => $confirmedAt];
    }

    /**
     * Stores one file as an attachment of the journey's application. A
     * retry with the same operation identifier returns the attachment that
     * was stored the first time, without storing anything again.
     *
     * @return array{0: string, 1: FileLink|string|null}
     */
    public function upload(User $user, CandidateNomination $journey, string $kind, UploadedFile $file, ?string $title, string $operationId): array
    {
        if ($existing = $this->attachmentByOperation($user, $journey, $operationId)) {
            return [self::ALREADY_STORED, $existing];
        }

        $check = $this->inspector->inspect($file, self::KINDS[$kind]['format']);
        if (! $check['ok']) {
            return [self::INVALID_FILE, $check['message']];
        }

        // Checked again inside the transaction; this only avoids storing
        // bytes for an application that is plainly locked already.
        if (! $this->editable($journey)) {
            return [Application::query()->findOrFail($journey->application_id)->isEditable() ? self::UNAVAILABLE : self::SUBMITTED, null];
        }

        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());
        $directory = 'applications/'.$journey->application_id;
        $name = Str::uuid()->toString().'.'.$check['extension'];
        $path = Storage::disk(self::DISK)->putFileAs($directory, $file, $name);
        $released = [];

        try {
            $result = DB::transaction(function () use ($user, $journey, $kind, $file, $title, $operationId, $check, $sha256, $path, &$released): array {
                [$refusal, $journey, $application] = $this->lockForChange($user, $journey);
                if ($refusal !== null) {
                    return [$refusal, null];
                }

                $purpose = self::KINDS[$kind]['purpose'];
                $current = FileLink::query()->where('application_id', $application->getKey())->where('purpose', $purpose)->get();
                if ($kind !== 'explanatory' && $current->count() >= self::KINDS[$kind]['max']) {
                    return [self::TOO_MANY, null];
                }

                $stored = new StoredFile;
                $stored->forceFill([
                    'uploaded_by' => $user->getKey(),
                    'storage_disk' => self::DISK,
                    'storage_path' => $path,
                    'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 250, ''),
                    'detected_mime_type' => $check['mime'],
                    'size_bytes' => $check['size'],
                    'sha256' => $sha256,
                    'scan_status' => StoredFile::SCAN_PENDING,
                    'access_level' => 'private',
                    'upload_operation_id' => $operationId,
                ])->save();

                $link = new FileLink;
                $link->forceFill([
                    'file_id' => $stored->getKey(),
                    'application_id' => $application->getKey(),
                    'purpose' => $purpose,
                    'caption' => $title,
                    'display_order' => (int) FileLink::query()->where('application_id', $application->getKey())->max('display_order') + 1,
                ])->save();

                // The explanatory document is replaced, never doubled.
                if ($kind === 'explanatory') {
                    foreach ($current as $old) {
                        $released = [...$released, ...$this->detach($old)];
                    }
                }

                $this->changed($journey);
                AuditLog::record('documentation.file_added', (int) $user->getKey(), 'application', (int) $application->getKey(), [
                    'file_link_id' => $link->getKey(),
                    'purpose' => $purpose,
                    'size_bytes' => $check['size'],
                    'replaced' => $kind === 'explanatory' && $current->isNotEmpty(),
                ]);

                return [self::STORED, $link];
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel retry of the same upload stored it first.
            $this->deleteBytes([$path]);
            $existing = $this->attachmentByOperation($user, $journey, $operationId);

            return $existing !== null ? [self::ALREADY_STORED, $existing] : throw new RuntimeException('Upload conflicted without a stored attachment.');
        } catch (Throwable $exception) {
            $this->deleteBytes([$path]);
            throw $exception;
        }

        $this->deleteBytes($result[0] === self::STORED ? $released : [$path]);

        return $result;
    }

    /**
     * Removes a supporting document or a video. The explanatory document is
     * replaced, not removed.
     *
     * @return array{0: string, 1: null}
     */
    public function remove(User $user, CandidateNomination $journey, int $attachmentId): array
    {
        $released = [];

        $outcome = DB::transaction(function () use ($user, $journey, $attachmentId, &$released): string {
            [$refusal, $journey, $application] = $this->lockForChange($user, $journey);
            if ($refusal !== null) {
                return $refusal;
            }

            $link = FileLink::query()->where('application_id', $application->getKey())->whereKey($attachmentId)
                ->whereIn('purpose', array_column(self::KINDS, 'purpose'))->first();
            if ($link === null) {
                return self::NOT_FOUND;
            }
            if ($link->purpose === self::KINDS['explanatory']['purpose']) {
                return self::MAIN_DOCUMENT_REPLACE_ONLY;
            }

            $released = $this->detach($link);
            $this->changed($journey);
            AuditLog::record('documentation.file_removed', (int) $user->getKey(), 'application', (int) $application->getKey(), [
                'file_link_id' => $attachmentId,
                'purpose' => $link->purpose,
            ]);

            return self::SAVED;
        });

        $this->deleteBytes($released);

        return [$outcome, null];
    }

    /**
     * Replaces the documentation links with the validated list. Saving the
     * same list again changes nothing.
     *
     * @param  list<array{title: string, url: string}>  $links
     */
    public function saveLinks(User $user, CandidateNomination $journey, array $links): string
    {
        return DB::transaction(function () use ($user, $journey, $links): string {
            [$refusal, $journey, $application] = $this->lockForChange($user, $journey);
            if ($refusal !== null) {
                return $refusal;
            }

            $current = DocumentationLink::query()->where('application_id', $application->getKey())->orderBy('position')
                ->get(['title', 'url'])->map(fn (DocumentationLink $link): array => ['title' => $link->title, 'url' => $link->url])->all();
            $wanted = array_map(fn (array $link): array => ['title' => $link['title'], 'url' => $link['url']], $links);
            if ($current === $wanted) {
                return self::SAVED;
            }

            DocumentationLink::query()->where('application_id', $application->getKey())->delete();
            foreach ($wanted as $index => $link) {
                (new DocumentationLink)->forceFill(['application_id' => $application->getKey(), 'position' => $index + 1] + $link)->save();
            }

            $this->changed($journey);
            AuditLog::record('documentation.links_saved', (int) $user->getKey(), 'application', (int) $application->getKey(), ['count' => count($wanted)]);

            return self::SAVED;
        });
    }

    /**
     * Confirms Stage 3 for the revision the candidate saw.
     */
    public function confirm(User $user, CandidateNomination $journey, int $revision): string
    {
        return DB::transaction(function () use ($user, $journey, $revision): string {
            [$refusal, $journey, $application] = $this->lockForChange($user, $journey);
            if ($refusal !== null) {
                return $refusal;
            }
            if ($revision !== $journey->documentation_revision) {
                return self::REVISION_CHANGED;
            }
            if (! FileLink::query()->where('application_id', $application->getKey())->where('purpose', self::KINDS['explanatory']['purpose'])->exists()) {
                return self::MAIN_DOCUMENT_REQUIRED;
            }

            if (! $journey->documentationConfirmed()) {
                $journey->forceFill(['documentation_confirmed_revision' => $revision, 'documentation_confirmed_at' => now()])->save();
                AuditLog::record('documentation.confirmed', (int) $user->getKey(), 'application', (int) $application->getKey(), ['revision' => $revision]);
            }

            return self::CONFIRMED;
        });
    }

    /**
     * The attachments and links of the journey's application, for its owner.
     *
     * @return array{revision: int, confirmed: bool, confirmed_at: string|null, editable: bool, attachments: list<array<string, mixed>>, links: list<array{title: string, url: string}>}
     */
    public function state(CandidateNomination $journey): array
    {
        $application = Application::query()->findOrFail($journey->application_id);
        $kindOf = array_flip(array_map(fn (array $kind): string => $kind['purpose'], self::KINDS));

        return [
            'revision' => (int) $journey->documentation_revision,
            'confirmed' => $journey->documentationConfirmed(),
            'confirmed_at' => $journey->documentationConfirmed() ? $journey->documentation_confirmed_at?->toIso8601ZuluString() : null,
            'editable' => $application->isEditable() && $this->cycles->stageOpenFor((int) $journey->cycle_id),
            'attachments' => $application->fileLinks()->with('file')->whereIn('purpose', array_keys($kindOf))->get()
                ->map(fn (FileLink $link): array => [
                    'id' => (int) $link->getKey(),
                    'kind' => $kindOf[$link->purpose],
                    'title' => $link->caption,
                    'name' => $link->file->original_name,
                    'mime_type' => $link->file->detected_mime_type,
                    'size_bytes' => (int) $link->file->size_bytes,
                    'scan_status' => $link->file->scan_status,
                    'operation_id' => $link->file->upload_operation_id,
                    'uploaded_at' => $link->created_at?->toIso8601ZuluString(),
                ])->values()->all(),
            'links' => $application->documentationLinks()->get(['title', 'url'])
                ->map(fn (DocumentationLink $link): array => ['title' => $link->title, 'url' => $link->url])->all(),
        ];
    }

    /**
     * The owner's attachment of the journey's application (draft links stay
     * after submission, so downloads keep working).
     */
    public function attachment(CandidateNomination $journey, int $attachmentId): ?FileLink
    {
        return FileLink::query()->with('file')->where('application_id', $journey->application_id)->whereKey($attachmentId)
            ->whereIn('purpose', array_column(self::KINDS, 'purpose'))->first();
    }

    /**
     * The users row, then the configuration, then the journey and its
     * application, each locked; refuses a change to anything but an open,
     * unsubmitted draft.
     *
     * @return array{0: string|null, 1: CandidateNomination, 2: Application}
     */
    public function lockForChange(User $user, CandidateNomination $journey): array
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();
        $this->cycles->lockConfiguration();
        $journey = CandidateNomination::query()->whereKey($journey->getKey())->lockForUpdate()->firstOrFail();
        $application = Application::query()->whereKey($journey->application_id)->lockForUpdate()->firstOrFail();

        if (! $application->isEditable()) {
            return [self::SUBMITTED, $journey, $application];
        }
        if (! $this->cycles->stageOpenFor((int) $journey->cycle_id, lock: true)) {
            return [self::UNAVAILABLE, $journey, $application];
        }

        return [null, $journey, $application];
    }

    private function attachmentByOperation(User $user, CandidateNomination $journey, string $operationId): ?FileLink
    {
        return FileLink::query()->with('file')
            ->where('application_id', $journey->application_id)
            ->whereHas('file', fn ($query) => $query->where('uploaded_by', $user->getKey())->where('upload_operation_id', $operationId))
            ->first();
    }

    /**
     * Removes a draft attachment row, and its file row when nothing else
     * references it. Returns the storage paths whose bytes may go once the
     * transaction has committed (never those of a submitted version).
     *
     * @return list<string>
     */
    private function detach(FileLink $link): array
    {
        $link->delete();

        if (FileLink::query()->where('file_id', $link->file_id)->exists()) {
            return [];
        }

        $file = StoredFile::query()->whereKey($link->file_id)->first();
        $file?->delete();

        return $file === null ? [] : [$file->storage_path];
    }

    /**
     * A change to the attachments or links: the next revision, which is no
     * longer the confirmed one.
     */
    private function changed(CandidateNomination $journey): void
    {
        $journey->forceFill(['documentation_revision' => $journey->documentation_revision + 1])->save();
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteBytes(array $paths): void
    {
        foreach ($paths as $path) {
            if (! StoredFile::query()->where('storage_disk', self::DISK)->where('storage_path', $path)->exists()) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }

    /**
     * The original name as display metadata only: no path, no control
     * characters.
     */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name === '' ? 'file' : $name;
    }
}
