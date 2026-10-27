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
            'documentation_revision' => 'integer',
            'documentation_confirmed_revision' => 'integer',
            'documentation_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Stage 3 is complete when it was confirmed for the current revision of
     * the attachments and links; any later change requires confirming again.
     */
    public function documentationConfirmed(): bool
    {
        return $this->documentation_confirmed_revision !== null
            && $this->documentation_confirmed_revision === $this->documentation_revision;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
