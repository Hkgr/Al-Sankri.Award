<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attaches a file to exactly one target: a draft application or a
 * submitted application version (chk_file_links_one_target).
 */
class FileLink extends Model
{
    protected $guarded = ['*'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
