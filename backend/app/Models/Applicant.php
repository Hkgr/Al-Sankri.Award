<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The applying individual or entity (database-schema.md, applicants). It is
 * not a login account: representative_user_id is the accountable account.
 * Nothing here is mass assignable; values are set by the services that own
 * each workflow.
 */
class Applicant extends Model
{
    public const TYPE_INDIVIDUAL = 'individual';

    /** The general organization type, used for institutions in basic onboarding. */
    public const TYPE_ORGANIZATION = 'organization';

    public const STATUS_ACTIVE = 'active';

    protected $guarded = ['*'];

    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'representative_user_id');
    }

    public function individualDetails(): HasOne
    {
        return $this->hasOne(ApplicantIndividualDetail::class);
    }

    public function institutionDetails(): HasOne
    {
        return $this->hasOne(ApplicantInstitutionDetail::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ApplicantMember::class);
    }

    public function socialLinks(): HasMany
    {
        return $this->hasMany(ApplicantSocialLink::class)->orderBy('position');
    }
}
