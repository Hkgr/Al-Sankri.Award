<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An application to one cycle domain (database-schema.md, applications).
 * The candidate journey only ever creates drafts: submission, versions and
 * reference numbers belong to later workflows. Nothing is mass assignable.
 */
class Application extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const IMPLEMENTATION_ONGOING = 'ongoing';

    public const IMPLEMENTATION_COMPLETED = 'completed';

    public const FUNDING_TYPES = ['fully_funded', 'partially_funded', 'self_funded', 'not_needed'];

    /** Funding types that name a funder (and report its amount). */
    public const FUNDED_BY_OTHERS = ['fully_funded', 'partially_funded'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'implementation_started_on' => 'date',
            'implementation_ended_on' => 'date',
            // Kept as an exact string ("1500.50"), never a float.
            'funding_amount_usd' => 'decimal:2',
            'has_partnerships' => 'boolean',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ApplicationLocation::class)->orderBy('id');
    }

    public function partnerships(): HasMany
    {
        return $this->hasMany(ApplicationPartnership::class)->orderBy('position');
    }
}
