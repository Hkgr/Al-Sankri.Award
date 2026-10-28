<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An application to one cycle domain (database-schema.md, applications).
 * The candidate journey creates a draft (Stage 2), attaches its
 * documentation (Stage 3), and submits it once (App\Candidates\Submissions):
 * status `submitted`, a reference number, first_submitted_at and version 1.
 * Nothing is mass assignable.
 */
class Application extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

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
            'first_submitted_at' => 'datetime',
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

    /** Draft attachments (Stage 3), in display order. */
    public function fileLinks(): HasMany
    {
        return $this->hasMany(FileLink::class)->orderBy('display_order')->orderBy('id');
    }

    public function documentationLinks(): HasMany
    {
        return $this->hasMany(DocumentationLink::class)->orderBy('position');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationVersion::class, 'current_version_id');
    }

    /**
     * Whether the candidate may still change it: a draft never submitted.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->current_version_id === null;
    }
}
