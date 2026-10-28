<?php

namespace App\Admin;

use RuntimeException;

/**
 * Thrown inside an administration transaction to refuse a change: the
 * transaction rolls back (nothing it wrote, such as a new account, is
 * kept), and the caller turns the outcome into its answer.
 */
final class RefusedChange extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(public readonly string $outcome, public readonly ?array $data = null)
    {
        parent::__construct($outcome);
    }
}
