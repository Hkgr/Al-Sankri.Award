<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who belongs to an applicant. Membership grants no editing rights.
 */
class ApplicantMember extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_primary_contact' => 'boolean',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
