<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual-only fields of an applicant. The identity number is encrypted
 * at rest with the application key (Laravel's "encrypted" cast) and is
 * hidden from every serialization.
 */
class ApplicantIndividualDetail extends Model
{
    public const GENDERS = ['male', 'female'];

    protected $guarded = ['*'];

    protected $hidden = ['national_id_number'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'national_id_number' => 'encrypted',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
