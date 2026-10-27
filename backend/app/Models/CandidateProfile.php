<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 1 of the candidate journey: the confirmed basic information of an
 * account. A row exists only once the basic information is confirmed, and
 * it is read-only from then on: no candidate endpoint updates or deletes it.
 */
class CandidateProfile extends Model
{
    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_INSTITUTION = 'institution';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data_accuracy_declared_at' => 'datetime',
            'representation_declared_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
