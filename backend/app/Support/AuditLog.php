<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Appends a row to audit_logs. Callers pass minimal metadata (codes, ids,
 * versions, outcomes), never personal details, passwords, tokens, identity
 * numbers, form contents or file contents.
 *
 * The cycle is recorded only when the caller knows it (real attribution,
 * never guessed from a role). The request id comes from the request's own
 * context (App\Http\Middleware\AssignRequestId); console commands have none.
 */
final class AuditLog
{
    /**
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, ?int $actorUserId, ?string $subjectType, ?int $subjectId, ?array $after = null, ?int $cycleId = null): void
    {
        $requestId = Context::get('request_id');

        DB::table('audit_logs')->insert([
            'actor_user_id' => $actorUserId,
            'cycle_id' => $cycleId,
            'action' => $action,
            'subject_type' => $subjectId === null ? null : $subjectType,
            'subject_id' => $subjectType === null ? null : $subjectId,
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'request_id' => is_string($requestId) ? $requestId : null,
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
