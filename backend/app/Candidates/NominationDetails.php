<?php

namespace App\Candidates;

use App\Models\Application;
use App\Models\ApplicationLocation;
use App\Models\ApplicationPartnership;
use App\Models\CandidateNomination;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stage 2 of the candidate journey: «فئة الترشح وبيانات العمل».
 *
 * Confirmation is atomic and happens once per candidate profile and cycle.
 * Inside one transaction it locks the account's users row, checks again that
 * Stage 1 is confirmed, that the cycle is still usable and that the chosen
 * domain and subcategory are still open, and only then creates the draft
 * application (with its locations and partners) and the journey row. The
 * unique key on candidate_nominations (profile, cycle) is the final guard.
 *
 * The application stays a draft: no reference number, no submitted version,
 * no first_submitted_at, no eligibility or judging. Only Stage 2's own fields
 * are read-only for the candidate afterwards.
 */
class NominationDetails
{
    public const CONFIRMED = 'confirmed';

    public const ALREADY_CONFIRMED = 'nomination_details_already_confirmed';

    public const BASIC_INFORMATION_REQUIRED = 'basic_information_required';

    public const UNAVAILABLE = 'nomination_unavailable';

    public const JOURNEY_CHANGED = 'journey_changed';

    public const OPTION_UNAVAILABLE = 'option_unavailable';

    /** Dashboard states. */
    public const STAGE_LOCKED = 'locked';

    public const STAGE_UNAVAILABLE = 'unavailable';

    public const STAGE_NOT_STARTED = 'not_started';

    public const STAGE_COMPLETED = 'completed';

    public function __construct(private readonly NominationCycles $cycles) {}

    public function profileOf(User $user): ?CandidateProfile
    {
        return CandidateProfile::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * The confirmed Stage 2 to show: the one of the usable cycle, or, when no
     * cycle is usable now, the profile's only confirmed one. Never "the
     * latest": with several and no usable cycle, none is chosen.
     */
    public function confirmedFor(CandidateProfile $profile, ?object $cycle): ?CandidateNomination
    {
        $journeys = CandidateNomination::query()->where('candidate_profile_id', $profile->getKey())->get();

        if ($cycle !== null) {
            return $journeys->firstWhere('cycle_id', (int) $cycle->id);
        }

        return $journeys->count() === 1 ? $journeys->first() : null;
    }

    /**
     * The confirmed journey a form's key refers to, if any.
     */
    public function confirmedByKey(CandidateProfile $profile, mixed $journeyKey): ?CandidateNomination
    {
        if (! is_string($journeyKey)) {
            return null;
        }

        return CandidateNomination::query()
            ->where('candidate_profile_id', $profile->getKey())
            ->get()
            ->first(fn (CandidateNomination $journey): bool => hash_equals($this->cycles->journeyKey($profile, (int) $journey->cycle_id), $journeyKey));
    }

    /**
     * Stage 2 for the dashboard: statuses only, no work details.
     *
     * @return array{status: string, reason: string|null, confirmed_at: string|null}
     */
    public function stage(User $user): array
    {
        $profile = $this->profileOf($user);

        if ($profile === null) {
            return ['status' => self::STAGE_LOCKED, 'reason' => self::BASIC_INFORMATION_REQUIRED, 'confirmed_at' => null];
        }

        $current = $this->cycles->current();

        if ($journey = $this->confirmedFor($profile, $current['cycle'])) {
            return ['status' => self::STAGE_COMPLETED, 'reason' => null, 'confirmed_at' => $journey->details_confirmed_at->toIso8601ZuluString()];
        }

        if ($current['status'] !== NominationCycles::AVAILABLE) {
            return ['status' => self::STAGE_UNAVAILABLE, 'reason' => $current['status'], 'confirmed_at' => null];
        }

        return ['status' => self::STAGE_NOT_STARTED, 'reason' => null, 'confirmed_at' => null];
    }

    /**
     * Checks that run before a section check or a confirmation is validated.
     * Returns the usable cycle, or an outcome to answer with instead.
     *
     * @return array{0: string|null, 1: object|null, 2: CandidateNomination|null}
     *                                                                            [refusal outcome, usable cycle, existing confirmed journey]
     */
    public function gate(User $user, mixed $journeyKey): array
    {
        $profile = $this->profileOf($user);

        if ($profile === null) {
            return [self::BASIC_INFORMATION_REQUIRED, null, null];
        }

        // A retry of a confirmed journey gets its stored result, even after
        // the stage has closed, and never another journey's.
        if ($existing = $this->confirmedByKey($profile, $journeyKey)) {
            return [self::ALREADY_CONFIRMED, null, $existing];
        }

        $current = $this->cycles->current();

        if ($current['status'] !== NominationCycles::AVAILABLE) {
            return [self::UNAVAILABLE, null, null];
        }

        if (! is_string($journeyKey) || ! hash_equals($this->cycles->journeyKey($profile, (int) $current['cycle']->id), $journeyKey)) {
            return [self::JOURNEY_CHANGED, null, null];
        }

        return [null, $current['cycle'], null];
    }

    /**
     * Confirms Stage 2, once.
     *
     * @param  array<string, mixed>  $data  Validated NominationDetailsRequest data.
     * @return array{0: string, 1: CandidateNomination|null}
     */
    public function confirm(User $user, array $data, string $journeyKey): array
    {
        try {
            return DB::transaction(fn (): array => $this->confirmLocked($user, $data, $journeyKey));
        } catch (UniqueConstraintViolationException) {
            // A parallel request of this journey confirmed first.
            $profile = $this->profileOf($user);
            $existing = $profile ? $this->confirmedByKey($profile, $journeyKey) : null;

            return $existing !== null ? [self::ALREADY_CONFIRMED, $existing] : throw new RuntimeException('Stage 2 confirmation conflicted without a confirmed journey.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: CandidateNomination|null}
     */
    private function confirmLocked(User $user, array $data, string $journeyKey): array
    {
        $account = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
        $profile = $account?->isActive() ? $this->profileOf($account) : null;

        if ($profile === null) {
            return [self::BASIC_INFORMATION_REQUIRED, null];
        }

        if ($existing = $this->confirmedByKey($profile, $journeyKey)) {
            return [self::ALREADY_CONFIRMED, $existing];
        }

        $current = $this->cycles->current();

        if ($current['status'] !== NominationCycles::AVAILABLE) {
            return [self::UNAVAILABLE, null];
        }

        $cycleId = (int) $current['cycle']->id;

        if (! hash_equals($this->cycles->journeyKey($profile, $cycleId), $journeyKey)) {
            return [self::JOURNEY_CHANGED, null];
        }

        // The stage row and the chosen options, read under a shared lock so a
        // concurrent closure is seen either before or after this confirmation.
        DB::table('cycle_stages')->where('id', $current['cycle']->stage_id)->sharedLock()->first();
        $domain = $this->cycles->options($cycleId, lock: true)->firstWhere('code', $data['domain']);
        $subcategory = $domain === null ? null : collect($domain['subcategories'])->firstWhere('code', $data['subcategory']);

        if ($subcategory === null) {
            return [self::OPTION_UNAVAILABLE, null];
        }

        $now = now();
        $completed = $data['implementation_state'] === Application::IMPLEMENTATION_COMPLETED;
        $funding = $data['funding_type'];

        $application = new Application;
        $application->forceFill([
            'cycle_id' => $cycleId,
            'cycle_domain_id' => $domain['cycle_domain_id'],
            'subcategory_id' => $subcategory['id'],
            'applicant_id' => $profile->applicant_id,
            'created_by' => $account->getKey(),
            'title' => $data['title'],
            'purpose_and_mission' => $data['purpose_and_mission'],
            'objectives' => $data['objectives'],
            'documented_impact' => $data['impact'],
            'implementation_started_on' => $data['started_on'],
            'implementation_ended_on' => $completed ? $data['ended_on'] : null,
            'implementation_status' => $completed ? Application::IMPLEMENTATION_COMPLETED : Application::IMPLEMENTATION_ONGOING,
            'funding_type' => $funding,
            'funder_name' => in_array($funding, Application::FUNDED_BY_OTHERS, true) ? $data['funder_name'] : null,
            'funding_amount_usd' => $funding === 'not_needed' ? null : $data['funding_amount'],
            'has_partnerships' => (bool) $data['has_partnerships'],
            'status' => Application::STATUS_DRAFT,
        ])->save();

        $country = $this->cycles->country($current['cycle']);
        foreach (array_values($data['locations']) as $location) {
            (new ApplicationLocation)->forceFill([
                'application_id' => $application->getKey(),
                'country_code' => $country,
                'governorate' => $location['governorate'],
                'locality' => $location['locality'],
                'address' => $location['address'] ?? null,
                'is_primary' => false,
            ])->save();
        }

        foreach ($data['has_partnerships'] ? array_values($data['partnerships'] ?? []) : [] as $index => $partner) {
            (new ApplicationPartnership)->forceFill([
                'application_id' => $application->getKey(),
                'position' => $index + 1,
                'partner_name' => $partner['name'],
                'contribution' => $partner['contribution'] ?? null,
            ])->save();
        }

        $journey = new CandidateNomination;
        $journey->forceFill([
            'user_id' => $account->getKey(),
            'candidate_profile_id' => $profile->getKey(),
            'applicant_id' => $profile->applicant_id,
            'cycle_id' => $cycleId,
            'application_id' => $application->getKey(),
            'details_confirmed_at' => $now,
        ])->save();

        // Identifiers and codes only: no narrative, places or amounts.
        AuditLog::record('nomination_details.confirmed', (int) $account->getKey(), 'application', (int) $application->getKey(), [
            'candidate_nomination_id' => $journey->getKey(),
            'cycle_id' => $cycleId,
            'domain' => $data['domain'],
            'subcategory' => $data['subcategory'],
            'funding_type' => $funding,
        ]);

        return [self::CONFIRMED, $journey];
    }
}
