<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A place where the work was implemented (database-schema.md,
 * application_locations). For Syrian cycles the governorate column holds the
 * ISO 3166-2:SY code (App\Support\SyrianGovernorates).
 */
class ApplicationLocation extends Model
{
    protected $guarded = ['*'];
}
