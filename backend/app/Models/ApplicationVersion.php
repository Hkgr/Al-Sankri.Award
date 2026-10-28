<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An immutable submitted version of an application: the data and form
 * snapshots and their SHA-256. Never updated after it is written.
 */
class ApplicationVersion extends Model
{
    public const UPDATED_AT = null;

    public const INITIAL = 'initial';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'submitted_at' => 'datetime',
            'data_snapshot' => 'array',
            'form_snapshot' => 'array',
        ];
    }
}
