<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A current judge does not also hold «مترشح». Accounts appointed before
 * this rule kept both; this revokes the candidate classification where a
 * current judge grant exists. Later appointments do the same themselves.
 * Rollback restores only the rows this migration revoked, and refuses
 * before any change once that account holds «مترشح» again.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_29_900004_separate_judge_and_candidate_roles';

    private const REVOKED = 'user_role.separated';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $candidateId = DB::table('roles')->where('code', 'candidate')->where('scope', 'platform')->value('id');
        $judgeId = DB::table('roles')->where('code', 'judge')->value('id');
        if ($candidateId === null || $judgeId === null) {
            throw new RuntimeException('The candidate or judge role is missing: run the migrations in order.');
        }

        $now = now();
        $grants = DB::table('user_roles')
            ->where('role_id', $candidateId)
            ->whereNull('revoked_at')
            ->whereExists(function ($query) use ($judgeId, $now): void {
                $query->selectRaw('1')->from('cycle_user_roles')
                    ->whereColumn('cycle_user_roles.user_id', 'user_roles.user_id')
                    ->where('cycle_user_roles.role_id', $judgeId)
                    ->whereNull('cycle_user_roles.revoked_at')
                    ->where('cycle_user_roles.starts_at', '<=', $now)
                    ->where(fn ($window) => $window->whereNull('cycle_user_roles.ends_at')->orWhere('cycle_user_roles.ends_at', '>', $now));
            })
            ->get(['id', 'user_id']);

        foreach ($grants as $grant) {
            $revokedBy = DB::table('cycle_user_roles')
                ->where('user_id', $grant->user_id)
                ->where('role_id', $judgeId)
                ->whereNull('revoked_at')
                ->whereNotNull('assigned_by')
                ->value('assigned_by');

            DB::table('user_roles')->where('id', $grant->id)->update([
                'revoked_at' => $now,
                'revoked_by' => $revokedBy ?? $grant->user_id,
                'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'action' => self::REVOKED,
                'subject_type' => 'user_role',
                'subject_id' => $grant->id,
                'after_data' => json_encode(['user_id' => (int) $grant->user_id, 'migration' => self::MIGRATION], JSON_THROW_ON_ERROR),
                'occurred_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $marks = DB::table('audit_logs')->where('action', self::REVOKED)->where('after_data', 'like', '%'.self::MIGRATION.'%')->get();
        foreach ($marks as $mark) {
            $row = DB::table('user_roles')->where('id', $mark->subject_id)->first();
            $restored = $row !== null && DB::table('user_roles')
                ->where('user_id', $row->user_id)
                ->where('role_id', $row->role_id)
                ->whereNull('revoked_at')
                ->exists();
            if ($restored) {
                throw new RuntimeException('Cannot roll back: an account whose candidate classification this migration revoked holds it again. Nothing was changed.');
            }
        }

        if ($marks->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($marks): void {
            foreach ($marks as $mark) {
                DB::table('user_roles')->where('id', $mark->subject_id)->update([
                    'revoked_at' => null,
                    'revoked_by' => null,
                    'updated_at' => now(),
                ]);
            }
            DB::table('audit_logs')->whereIn('id', $marks->pluck('id'))->delete();
        });
    }
};
