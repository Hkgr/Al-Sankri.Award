<?php

namespace App\Candidates;

use App\Models\AccountPolicyVersion;
use App\Models\Applicant;
use App\Models\ApplicantIndividualDetail;
use App\Models\ApplicantInstitutionDetail;
use App\Models\ApplicantMember;
use App\Models\ApplicantSocialLink;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\PhoneNumbers;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stage 1 of the candidate journey: confirming the basic information.
 *
 * Confirmation is atomic and happens once per account. Inside one
 * transaction it locks the account's users row (a stable parent that always
 * exists, unlike the profile row being created), then checks that no profile
 * exists yet, that the email is verified and that the accepted policy
 * versions are still the published ones, and only then writes the applicant,
 * its details, links, policy acceptances, the profile and an audit entry.
 * The unique key on candidate_profiles.user_id is the final guard.
 *
 * A confirmed profile is read-only for the candidate: nothing here, or in
 * any candidate endpoint, updates or deletes it.
 */
class BasicProfiles
{
    public const COMPLETED = 'completed';

    public const ALREADY_COMPLETED = 'profile_already_completed';

    public const EMAIL_UNVERIFIED = 'email_unverified';

    public const POLICIES_NOT_CONFIGURED = 'policies_not_configured';

    public const POLICY_CHANGED = 'policy_changed';

    public const ACCOUNT_INACTIVE = 'account_disabled';

    /** Dashboard state of stage 1. */
    public const STAGE_COMPLETED = 'completed';

    public const STAGE_NOT_STARTED = 'not_started';

    public const STAGE_UNAVAILABLE = 'unavailable';

    public function profileOf(User $user): ?CandidateProfile
    {
        return CandidateProfile::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * The published version of each policy required for confirmation.
     *
     * @return array<string, AccountPolicyVersion|null>
     */
    public function requiredPolicies(): array
    {
        $current = AccountPolicyVersion::currentFor(AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE);

        return collect(AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE)
            ->mapWithKeys(fn (string $code): array => [$code => $current[$code] ?? null])
            ->all();
    }

    public function policiesConfigured(): bool
    {
        return ! in_array(null, $this->requiredPolicies(), true);
    }

    /**
     * Stage 1 for the dashboard: completed, not started, or unavailable
     * because the required policies are not published yet.
     *
     * @return array{status: string, completed_at: string|null, profile_type: string|null}
     */
    public function stage(User $user): array
    {
        $profile = $this->profileOf($user);

        if ($profile !== null) {
            return [
                'status' => self::STAGE_COMPLETED,
                'completed_at' => $profile->completed_at->toIso8601ZuluString(),
                'profile_type' => $profile->profile_type,
            ];
        }

        return [
            'status' => $this->policiesConfigured() ? self::STAGE_NOT_STARTED : self::STAGE_UNAVAILABLE,
            'completed_at' => null,
            'profile_type' => null,
        ];
    }

    /**
     * Confirms the basic information, once.
     *
     * @param  array<string, mixed>  $data  Validated BasicProfileRequest data.
     * @return array{0: string, 1: CandidateProfile|null} An outcome constant,
     *                                                    and the account's profile when it exists.
     */
    public function finalize(User $user, array $data): array
    {
        try {
            return DB::transaction(fn (): array => $this->finalizeLocked($user, $data));
        } catch (UniqueConstraintViolationException) {
            // A parallel request for this account confirmed first.
            $profile = $this->profileOf($user);

            return $profile !== null ? [self::ALREADY_COMPLETED, $profile] : throw new RuntimeException('Basic profile confirmation conflicted without a profile.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: CandidateProfile|null}
     */
    private function finalizeLocked(User $user, array $data): array
    {
        $account = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        if ($account === null || ! $account->isActive()) {
            return [self::ACCOUNT_INACTIVE, null];
        }

        // A retry (double click, second tab, lost response) finds the result
        // of the first confirmation and never overwrites it.
        if ($existing = $this->profileOf($account)) {
            return [self::ALREADY_COMPLETED, $existing];
        }

        if (! $account->hasVerifiedEmail()) {
            return [self::EMAIL_UNVERIFIED, null];
        }

        $policies = AccountPolicyVersion::query()
            ->whereIn('code', AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE)
            ->where('status', AccountPolicyVersion::STATUS_PUBLISHED)
            ->sharedLock()
            ->get()
            ->keyBy('code');

        if ($policies->count() !== count(AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE)) {
            return [self::POLICIES_NOT_CONFIGURED, null];
        }

        foreach ($policies as $code => $policy) {
            if ((int) ($data['accepted_policies'][$code] ?? 0) !== (int) $policy->getKey()) {
                return [self::POLICY_CHANGED, null];
            }
        }

        $now = now();
        $type = $data['profile_type'];
        $applicant = $type === CandidateProfile::TYPE_INDIVIDUAL
            ? $this->createIndividual($account, $data['individual'])
            : $this->createInstitution($account, $data['institution']);

        foreach (array_values($data['social_links'] ?? []) as $index => $link) {
            (new ApplicantSocialLink)->forceFill([
                'applicant_id' => $applicant->getKey(),
                'position' => $index + 1,
                'platform' => $link['platform'],
                'url' => $link['url'],
            ])->save();
        }

        foreach ($policies as $policy) {
            DB::table('account_policy_acceptances')->insert([
                'user_id' => $account->getKey(),
                'policy_version_id' => $policy->getKey(),
                'context' => 'basic_profile',
                'accepted_at' => $now,
            ]);
        }

        $profile = new CandidateProfile;
        $profile->forceFill([
            'user_id' => $account->getKey(),
            'applicant_id' => $applicant->getKey(),
            'profile_type' => $type,
            'data_accuracy_declared_at' => $now,
            'representation_declared_at' => $type === CandidateProfile::TYPE_INSTITUTION ? $now : null,
            'completed_at' => $now,
        ])->save();

        // Codes and identifiers only: no personal details in the audit trail.
        AuditLog::record('candidate_profile.completed', (int) $account->getKey(), 'candidate_profile', (int) $profile->getKey(), [
            'profile_type' => $type,
            'applicant_id' => $applicant->getKey(),
            'policy_version_ids' => $policies->map(fn (AccountPolicyVersion $policy): int => (int) $policy->getKey())->all(),
        ]);

        return [self::COMPLETED, $profile];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function createIndividual(User $account, array $fields): Applicant
    {
        $applicant = new Applicant;
        $applicant->forceFill([
            'type' => Applicant::TYPE_INDIVIDUAL,
            'name_ar' => implode(' ', array_filter([$fields['first_name'], $fields['middle_name'] ?? null, $fields['last_name']])),
            'representative_user_id' => $account->getKey(),
            'contact_email' => $fields['contact_email'],
            'contact_phone' => PhoneNumbers::toE164($fields['primary_phone']['country'], $fields['primary_phone']['number']),
            'headquarters_country_code' => $fields['residence_country'],
            'headquarters_address' => $fields['address'],
            'status' => Applicant::STATUS_ACTIVE,
        ])->save();

        $secondary = $fields['secondary_phone'] ?? null;

        (new ApplicantIndividualDetail)->forceFill([
            'applicant_id' => $applicant->getKey(),
            'first_name' => $fields['first_name'],
            'middle_name' => $fields['middle_name'] ?? null,
            'last_name' => $fields['last_name'],
            'birth_date' => $fields['birth_date'],
            'nationality_country_code' => $fields['nationality'],
            'gender' => $fields['gender'],
            'national_id_number' => $fields['national_id_number'],
            'secondary_phone' => is_array($secondary) && ($secondary['number'] ?? null) !== null
                ? PhoneNumbers::toE164($secondary['country'], $secondary['number'])
                : null,
        ])->save();

        return $applicant;
    }

    /**
     * The institution is the applicant; the account holder stays its human
     * representative (its primary contact member) and keeps their own login
     * name and email.
     *
     * @param  array<string, mixed>  $fields
     */
    private function createInstitution(User $account, array $fields): Applicant
    {
        $applicant = new Applicant;
        $applicant->forceFill([
            'type' => Applicant::TYPE_ORGANIZATION,
            'name_ar' => $fields['name'],
            'representative_user_id' => $account->getKey(),
            'contact_email' => $fields['email'],
            'contact_phone' => PhoneNumbers::toE164($fields['phone']['country'], $fields['phone']['number']),
            'headquarters_country_code' => $fields['country'],
            'registration_number' => $fields['license_number'],
            'status' => Applicant::STATUS_ACTIVE,
        ])->save();

        (new ApplicantInstitutionDetail)->forceFill([
            'applicant_id' => $applicant->getKey(),
            'activity_type' => $fields['activity_type'],
            'website_url' => $fields['website'] ?? null,
        ])->save();

        (new ApplicantMember)->forceFill([
            'applicant_id' => $applicant->getKey(),
            'user_id' => $account->getKey(),
            'full_name' => $fields['representative_name'],
            'role_title' => $fields['representative_title'],
            'is_primary_contact' => true,
        ])->save();

        return $applicant;
    }
}
