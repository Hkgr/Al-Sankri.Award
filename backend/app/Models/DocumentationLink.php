<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An external documentation link of a draft application: a title and an
 * HTTPS address, kept exactly as validated. Its content is never fetched.
 */
class DocumentationLink extends Model
{
    public const MAX_ROWS = 20;

    protected $table = 'application_documentation_links';

    protected $guarded = ['*'];
}
