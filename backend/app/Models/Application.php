<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An application to one cycle domain (database-schema.md, applications).
 * The candidate journey creates a draft (Stage 2), attaches its
 * documentation (Stage 3), and submits it once (App\Candidates\Submissions):
 * status `submitted`, a reference number, first_submitted_at, version 1 and
 * the administrative classification `pending` (App\Admin\ApplicationReviews).
 * Nothing is mass assignable.
 *
 * Retired: the funding, partnership, implementation-schedule columns and
 * the application_locations and application_partnerships tables hold no
 * values any more (2026_10_29_900003) and are never written.
 */
class Application extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_IN_JUDGING = 'in_judging';

    /** Administrative classification (review_status), separate from status. */
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_REFERRED = 'referred';

    public const REVIEW_EXCLUDED = 'excluded';

    public const REVIEW_STATUSES = [self::REVIEW_PENDING, self::REVIEW_REFERRED, self::REVIEW_EXCLUDED];

    /** Who decided a classification (review_source). */
    public const REVIEW_BY_ADMIN = 'admin';

    public const REVIEW_LEGACY = 'legacy';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'first_submitted_at' => 'datetime',
            'review_decided_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
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
