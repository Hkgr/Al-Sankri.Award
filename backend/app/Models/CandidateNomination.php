<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The candidate journey's nomination in one cycle: the draft application it
 * created, and when Stage 2 was confirmed. Written once, in the same
 * transaction as the application, and never updated by the candidate.
 */
class CandidateNomination extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'details_confirmed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
