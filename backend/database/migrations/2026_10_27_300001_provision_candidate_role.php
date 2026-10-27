<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the canonical platform role «مترشح» (code "candidate"), which
 * every new public account receives (App\Auth\CandidateRole).
 *
 * Idempotent: an existing "candidate" role is kept exactly as it is. The
 * role carries no permissions; it classifies the account and gates the
 * candidate dashboard. It does not mean that a nomination was submitted.
 *
 * When this migration creates the role, it records that in audit_logs
 * (action role.provisioned), so a rollback can tell its own role from one
 * that existed before and must be preserved.
 */
return new class extends Migration
{
    private const CODE = 'candidate';

    private const PROVISIONED = 'role.provisioned';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existing = DB::table('roles')->where('code', self::CODE)->first();

        if ($existing !== null) {
            if ($existing->scope !== 'platform') {
                throw new RuntimeException('A "candidate" role already exists with scope ['.$existing->scope.']; it must be a platform role.');
            }

            return;
        }

        DB::transaction(function (): void {
            $roleId = DB::table('roles')->insertGetId([
                'code' => self::CODE,
                'name_ar' => 'مترشح',
                'description' => 'حساب عام مسجَّل في منصة الجائزة. التصنيف لا يعني تقديم طلب ترشح.',
                'scope' => 'platform',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'action' => self::PROVISIONED,
                'subject_type' => 'role',
                'subject_id' => $roleId,
                'after_data' => json_encode(['code' => self::CODE, 'migration' => '2026_10_27_300001_provision_candidate_role']),
                'occurred_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Removes only a role this migration created, and only while nothing
     * refers to it. A role that existed before, with its permissions, is
     * left untouched. Refused while any account holds or held the role:
     * those grants are account history, and removing them would silently
     * strip every candidate of access.
     */
    public function down(): void
    {
        $roleId = DB::table('roles')->where('code', self::CODE)->value('id');

        if ($roleId === null) {
            return;
        }

        $grants = DB::table('user_roles')->where('role_id', $roleId)->count();

        if ($grants > 0) {
            throw new RuntimeException("Cannot remove the candidate role: {$grants} grant(s) reference it.");
        }

        $createdHere = DB::table('audit_logs')
            ->where('action', self::PROVISIONED)
            ->where('subject_type', 'role')
            ->where('subject_id', $roleId)
            ->exists();

        if (! $createdHere || DB::table('role_permissions')->where('role_id', $roleId)->exists()) {
            return;
        }

        DB::table('roles')->where('id', $roleId)->delete();
    }
};
