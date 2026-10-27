<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the canonical platform role «مترشح» (code "candidate"), which
 * every new public account receives (App\Auth\CandidateRole).
 *
 * Idempotent: an existing "candidate" role is kept as it is. The role
 * carries no permissions; it classifies the account and gates the candidate
 * dashboard. It does not mean that a nomination was submitted.
 */
return new class extends Migration
{
    private const CODE = 'candidate';

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

        DB::table('roles')->insert([
            'code' => self::CODE,
            'name_ar' => 'مترشح',
            'description' => 'حساب عام مسجَّل في منصة الجائزة. التصنيف لا يعني تقديم طلب ترشح.',
            'scope' => 'platform',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Refused while any account holds or held the role: those grants are
     * part of the account history, and removing them would silently strip
     * every candidate of access.
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

        DB::table('roles')->where('id', $roleId)->delete();
    }
};
