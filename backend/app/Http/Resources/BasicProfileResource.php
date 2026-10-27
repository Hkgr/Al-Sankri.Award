<?php

namespace App\Http\Resources;

use App\Models\CandidateProfile;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The confirmed basic information, for its owner only (GET and POST
 * /api/candidate/basic-profile, behind auth, candidate and no-store). This
 * is the only resource that contains the identity number, birth date or
 * address; /api/user and the dashboard overview never do.
 *
 * @mixin CandidateProfile
 */
class BasicProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $applicant = $this->applicant;

        return [
            'profile_type' => $this->profile_type,
            'completed_at' => $this->completed_at->toIso8601ZuluString(),
            'individual' => $this->when($this->profile_type === CandidateProfile::TYPE_INDIVIDUAL, function () use ($applicant): array {
                $details = $applicant->individualDetails;

                return [
                    'first_name' => $details->first_name,
                    'middle_name' => $details->middle_name,
                    'last_name' => $details->last_name,
                    'birth_date' => $details->birth_date->toDateString(),
                    'nationality' => $this->country($details->nationality_country_code),
                    'gender' => $details->gender,
                    'national_id_number' => $details->national_id_number,
                    'residence_country' => $this->country($applicant->headquarters_country_code),
                    'address' => $applicant->headquarters_address,
                    'primary_phone' => $applicant->contact_phone,
                    'secondary_phone' => $details->secondary_phone,
                    'contact_email' => $applicant->contact_email,
                ];
            }),
            'institution' => $this->when($this->profile_type === CandidateProfile::TYPE_INSTITUTION, function () use ($applicant): array {
                $details = $applicant->institutionDetails;
                $representative = $applicant->members()->where('is_primary_contact', true)->first();

                return [
                    'name' => $applicant->name_ar,
                    'country' => $this->country($applicant->headquarters_country_code),
                    'activity_type' => $details->activity_type,
                    'license_number' => $applicant->registration_number,
                    'representative_name' => $representative?->full_name,
                    'representative_title' => $representative?->role_title,
                    'phone' => $applicant->contact_phone,
                    'email' => $applicant->contact_email,
                    'website' => $details->website_url,
                ];
            }),
            'social_links' => $applicant->socialLinks->map(fn ($link): array => [
                'platform' => $link->platform,
                'url' => $link->url,
            ])->all(),
            'accepted_policies' => DB::table('account_policy_acceptances')
                ->join('account_policy_versions', 'account_policy_versions.id', '=', 'account_policy_acceptances.policy_version_id')
                ->where('account_policy_acceptances.user_id', $this->user_id)
                ->where('account_policy_acceptances.context', 'basic_profile')
                ->orderBy('account_policy_versions.code')
                ->get(['account_policy_versions.code', 'account_policy_versions.version_number', 'account_policy_versions.title_ar', 'account_policy_acceptances.accepted_at'])
                ->map(fn (object $row): array => [
                    'code' => $row->code,
                    'version_number' => (int) $row->version_number,
                    'title' => $row->title_ar,
                    'accepted_at' => Carbon::parse($row->accepted_at)->toIso8601ZuluString(),
                ])->all(),
            'declarations' => [
                'data_accuracy_declared_at' => $this->data_accuracy_declared_at->toIso8601ZuluString(),
                'representation_declared_at' => $this->representation_declared_at?->toIso8601ZuluString(),
            ],
        ];
    }

    /**
     * @return array{code: string, name: string|null}|null
     */
    private function country(?string $code): ?array
    {
        return $code === null ? null : ['code' => $code, 'name' => Countries::name($code)];
    }
}
