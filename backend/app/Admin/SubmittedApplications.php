<?php

namespace App\Admin;

use App\Candidates\Documentation;
use App\Candidates\NominationTaxonomy;
use App\Http\Resources\BasicProfileResource;
use App\Http\Resources\NominationDetailsResource;
use App\Judging\CommitteeDecision;
use App\Models\Application;
use App\Models\ApplicationVersion;
use App\Models\CandidateProfile;
use App\Models\FileLink;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * «ملفات الترشح»: submitted participations only. An application appears
 * once it has a submitted version (current_version_id), whatever workflow
 * status it has moved to since; drafts never do.
 *
 * The page of one application shows the immutable submitted version: its
 * data snapshot (Stage 2, the documentation manifest and links, accepted
 * policies) and the files linked to that version. Stage 1 is resolved
 * through the profile the snapshot references (Stage 1 is confirmed once
 * and never edited). Nothing here changes an application.
 *
 * Every submitted application is listed whatever its administrative
 * classification (pending, referred or excluded): an exclusion archives,
 * it neither deletes nor hides. The classification can be filtered.
 */
class SubmittedApplications
{
    /** Workflow statuses a submitted application can have (applications.status). */
    public const STATUSES = ['submitted', 'completion_requested', 'eligible', 'ineligible', 'in_judging', 'withdrawn', 'closed'];

    /**
     * The two applicant kinds the table shows, as Stage 1 stores them in
     * applicants.type (an institution profile is an `organization`
     * applicant; `official_institution` is the other institutional type).
     *
     * @var array<string, list<string>>
     */
    public const APPLICANT_TYPES = [
        'individual' => ['individual'],
        'institution' => ['organization', 'official_institution'],
    ];

    /** Sortable columns: request key => column. */
    public const SORTS = [
        'submitted_at' => 'applications.first_submitted_at',
        'reference' => 'applications.reference_number',
        'title' => 'applications.title',
        'applicant' => 'applicants.name_ar',
        'status' => 'applications.status',
    ];

    public const PER_PAGE = [10, 25, 50];

    /**
     * The table's filters (shared by the table and its exports).
     *
     * @return array<string, list<mixed>>
     */
    public static function filterRules(): array
    {
        return [
            'cycle' => ['nullable', 'integer', 'min:1'],
            'domain' => ['nullable', 'string', Rule::in(NominationTaxonomy::domainCodes())],
            'subcategory' => ['nullable', 'string', 'max:64'],
            'applicant_type' => ['nullable', Rule::in(array_keys(self::APPLICANT_TYPES))],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'review' => ['nullable', Rule::in(Application::REVIEW_STATUSES)],
            'q' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ];
    }

    /**
     * One page of the table.
     *
     * @param  array{cycle?: int|null, domain?: string|null, subcategory?: string|null, applicant_type?: string|null, status?: string|null, review?: string|null, q?: string|null, sort?: string|null, direction?: string|null, page?: int|null, per_page?: int|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(array $filters): array
    {
        $total = $this->count($filters);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);

        return [
            'data' => $this->rows($filters, $page, $perPage),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ];
    }

    /**
     * How many submitted applications the filters match (the table's query).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * Every matching row, in the table's order and with the table's fields
     * (exports). The caller checks count() against its limit first.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters): array
    {
        return $this->rows($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters, ?int $page = null, ?int $perPage = null): array
    {
        $sort = self::SORTS[$filters['sort'] ?? 'submitted_at'] ?? self::SORTS['submitted_at'];
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $this->filtered($filters)
            ->select([
                'applications.id', 'applications.reference_number', 'applications.title', 'applications.status', 'applications.review_status', 'applications.first_submitted_at',
                'applicants.name_ar as applicant_name', 'applicants.type as applicant_type',
                'award_domains.code as domain_code', 'cycle_domains.name_ar as domain_name',
                'cycle_domain_subcategories.code as subcategory_code', 'cycle_domain_subcategories.name_ar as subcategory_name',
            ])
            ->selectSub(
                DB::table('file_links')->selectRaw('count(*)')->whereColumn('file_links.application_version_id', 'applications.current_version_id'),
                'attachment_count',
            )
            ->orderBy($sort, $direction)
            ->orderBy('applications.id', $direction)
            ->when($page !== null, fn (Builder $q) => $q->forPage((int) $page, (int) $perPage))
            ->get();

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'reference_number' => $row->reference_number,
            'title' => $row->title,
            'applicant_name' => $row->applicant_name,
            'applicant_type' => $row->applicant_type === 'individual' ? 'individual' : 'institution',
            'domain' => ['code' => $row->domain_code, 'name' => $row->domain_name],
            'subcategory' => $row->subcategory_code === null ? null : ['code' => $row->subcategory_code, 'name' => $row->subcategory_name],
            'submitted_at' => Carbon::parse($row->first_submitted_at, 'UTC')->toIso8601ZuluString(),
            'status' => $row->status,
            'review' => $row->review_status ?? Application::REVIEW_PENDING,
            'attachment_count' => (int) $row->attachment_count,
        ])->all();
    }

    /**
     * The whole submitted participation, or null when the id is not a
     * submitted application.
     *
     * @return array<string, mixed>|null
     */
    public function show(int $applicationId): ?array
    {
        $application = $this->submitted($applicationId);
        if ($application === null) {
            return null;
        }

        $version = ApplicationVersion::query()->findOrFail($application->current_version_id);
        $snapshot = $version->data_snapshot;
        $profile = CandidateProfile::query()->find($snapshot['basic_information']['candidate_profile_id'] ?? null);
        $cycle = DB::table('award_cycles')->where('id', $application->cycle_id)->first(['code', 'name_ar', 'year']);
        $policyTitles = DB::table('account_policy_versions')
            ->whereIn('id', array_column($snapshot['accepted_policies'] ?? [], 'policy_version_id'))->pluck('title_ar', 'id');

        return [
            'summary' => [
                'id' => (int) $application->getKey(),
                'reference_number' => $application->reference_number,
                'cycle' => ['code' => $cycle->code, 'name' => $cycle->name_ar, 'year' => (int) $cycle->year],
                'domain' => $snapshot['nomination_details']['domain'] ?? null,
                'subcategory' => $snapshot['nomination_details']['subcategory'] ?? null,
                'status' => $application->status,
                'submitted_at' => $version->submitted_at->toIso8601ZuluString(),
                'version_number' => $version->version_number,
                'content_hash' => $version->content_hash,
            ],
            'basic_information' => $profile === null ? null : (new BasicProfileResource($profile))->resolve(),
            'nomination_details' => NominationDetailsResource::withoutRetired($snapshot['nomination_details'] ?? null),
            'documentation' => [
                'confirmed_at' => $snapshot['documentation']['confirmed_at'] ?? null,
                'attachments' => $this->versionFiles((int) $version->getKey())->map(fn (FileLink $link): array => [
                    'id' => (int) $link->getKey(),
                    'kind' => $this->kindOf($link->purpose),
                    'title' => $link->caption,
                    'name' => $link->file->original_name,
                    'mime_type' => $link->file->detected_mime_type,
                    'size_bytes' => (int) $link->file->size_bytes,
                    'sha256' => $link->file->sha256,
                    'scan_status' => $link->file->scan_status,
                ])->all(),
                'links' => $snapshot['documentation']['links'] ?? [],
            ],
            'judging' => app(CommitteeDecision::class)->forApplication($application),
            // Administration only: judges receive the summary, Stage 2 and the files.
            'review' => app(ApplicationReviews::class)->state((int) $application->getKey()),
            'declarations' => [
                'data_accuracy_declared_at' => $snapshot['basic_information']['data_accuracy_declared_at'] ?? null,
                'accepted_policies' => array_map(fn (array $policy): array => [
                    'code' => $policy['code'],
                    'title' => $policyTitles[$policy['policy_version_id']] ?? null,
                    'version_number' => $policy['version_number'],
                    'accepted_at' => $policy['accepted_at'] === null ? null : Carbon::parse($policy['accepted_at'], 'UTC')->toIso8601ZuluString(),
                    'text_sha256' => $policy['text_sha256'],
                ], $snapshot['accepted_policies'] ?? []),
            ],
        ];
    }

    /**
     * One file of the application's submitted version, or null when the
     * link does not belong to that version.
     */
    public function file(int $applicationId, int $linkId): ?FileLink
    {
        $application = $this->submitted($applicationId);

        return $application === null ? null : $this->versionFiles((int) $application->current_version_id)->firstWhere('id', $linkId);
    }

    private function submitted(int $applicationId): ?Application
    {
        return Application::query()->whereKey($applicationId)->whereNotNull('current_version_id')->whereIn('status', self::STATUSES)->first();
    }

    /**
     * @return Collection<int, FileLink>
     */
    private function versionFiles(int $versionId)
    {
        return FileLink::query()->with('file')->where('application_version_id', $versionId)
            ->whereIn('purpose', array_column(Documentation::KINDS, 'purpose'))
            ->orderBy('display_order')->orderBy('id')->get();
    }

    private function kindOf(string $purpose): string
    {
        foreach (Documentation::KINDS as $kind => $rules) {
            if ($rules['purpose'] === $purpose) {
                return $kind;
            }
        }

        return $purpose;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = DB::table('applications')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->join('cycle_domains', 'cycle_domains.id', '=', 'applications.cycle_domain_id')
            ->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->leftJoin('cycle_domain_subcategories', 'cycle_domain_subcategories.id', '=', 'applications.subcategory_id')
            ->whereNotNull('applications.current_version_id')
            ->whereIn('applications.status', self::STATUSES);

        if (! empty($filters['cycle'])) {
            $query->where('applications.cycle_id', (int) $filters['cycle']);
        }
        if (! empty($filters['domain'])) {
            $query->where('award_domains.code', $filters['domain']);
            if (! empty($filters['subcategory'])) {
                $query->where('cycle_domain_subcategories.code', $filters['subcategory']);
            }
        }
        if (! empty($filters['applicant_type'])) {
            $query->whereIn('applicants.type', self::APPLICANT_TYPES[$filters['applicant_type']]);
        }
        if (! empty($filters['status'])) {
            $query->where('applications.status', $filters['status']);
        }
        if (! empty($filters['review'])) {
            $query->where('applications.review_status', $filters['review']);
        }
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $like = '%'.addcslashes(trim((string) $filters['q']), '\\%_').'%';
            $query->where(fn (Builder $search) => $search
                ->where('applications.reference_number', 'like', $like)
                ->orWhere('applications.title', 'like', $like)
                ->orWhere('applicants.name_ar', 'like', $like));
        }

        return $query;
    }
}
