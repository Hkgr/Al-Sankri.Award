<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored file (`files`): private bytes on a disk under a generated name.
 * The original name is metadata only. `scan_status` stays `pending` until a
 * real malware scan records a result; content-type checks are not a scan.
 */
class StoredFile extends Model
{
    public const SCAN_PENDING = 'pending';

    protected $table = 'files';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }
}
