<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A partner named by the candidate for the work: a name and, optionally,
 * the nature of the partnership. Partners are not accounts or applicants,
 * and no contact or financial details are kept.
 */
class ApplicationPartnership extends Model
{
    public const MAX_ROWS = 10;

    protected $guarded = ['*'];
}
