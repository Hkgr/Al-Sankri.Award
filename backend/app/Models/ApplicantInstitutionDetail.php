<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Institution-only fields of an applicant.
 */
class ApplicantInstitutionDetail extends Model
{
    protected $guarded = ['*'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
