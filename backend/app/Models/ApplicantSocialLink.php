<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public profile link of an applicant (at most five, in entry order).
 * Links are stored as entered and never fetched by the server.
 */
class ApplicantSocialLink extends Model
{
    public const MAX_LINKS = 5;

    protected $guarded = ['*'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
