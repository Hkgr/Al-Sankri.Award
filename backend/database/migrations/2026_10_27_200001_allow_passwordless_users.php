<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an account exist without a local password, for people who sign up
 * with Google. Such accounts keep a NULL password instead of a dummy shared
 * one; Laravel's password check rejects NULL, and the owner can still set a
 * password through the reset flow.
 *
 * Existing rows and their password hashes are unchanged in both directions.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Refuses to run while passwordless accounts exist, because restoring
     * NOT NULL would otherwise need invented passwords or deleted accounts.
     */
    public function down(): void
    {
        $passwordless = DB::table('users')->whereNull('password')->count();

        if ($passwordless > 0) {
            throw new RuntimeException(
                "Cannot make users.password required again: {$passwordless} account(s) have no password. "
                .'Those owners must set a password (password reset) first.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
