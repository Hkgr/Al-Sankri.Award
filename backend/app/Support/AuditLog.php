<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Appends a row to audit_logs. Callers pass minimal metadata (codes, ids,
 * versions), never personal details, passwords or tokens.
 */
final class AuditLog
{
    /**
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, ?int $actorUserId, string $subjectType, int $subjectId, ?array $after = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
