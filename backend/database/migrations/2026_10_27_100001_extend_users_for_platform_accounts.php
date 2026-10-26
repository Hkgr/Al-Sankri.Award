<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing users table for platform accounts.
 *
 * Existing rows stay usable: the new status column defaults to "active" and
 * every other new column is nullable. Rolling back drops only the columns,
 * indexes and checks added here; existing user data is untouched.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertUsersTableUsesInnoDb();

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->dateTime('phone_verified_at')->nullable()->after('phone');
            $table->string('status', 16)->default('active')->after('password');
            $table->dateTime('disabled_at')->nullable()->after('status');
            $table->string('disabled_reason', 500)->nullable()->after('disabled_at');
            $table->dateTime('last_login_at')->nullable()->after('disabled_reason');

            $table->index('phone', 'idx_users_phone');
            $table->index('status', 'idx_users_status');
        });

        $this->addCheck('chk_users_status', "status IN ('active', 'disabled')");
        $this->addCheck('chk_users_disabled', "(status = 'disabled') = (disabled_at IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropCheck('chk_users_disabled');
        $this->dropCheck('chk_users_status');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_status');
            $table->dropIndex('idx_users_phone');
            $table->dropColumn([
                'phone',
                'phone_verified_at',
                'status',
                'disabled_at',
                'disabled_reason',
                'last_login_at',
            ]);
        });
    }

    /**
     * Every new table references users with foreign keys, which MySQL only
     * enforces between InnoDB tables. Stop early instead of half-migrating.
     */
    private function assertUsersTableUsesInnoDb(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        $engine = DB::scalar(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['users'],
        );

        if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
            throw new RuntimeException(
                "The users table uses the {$engine} engine. Back it up and convert it with "
                .'"ALTER TABLE users ENGINE=InnoDB" before running the platform migrations.'
            );
        }
    }

    private function addCheck(string $name, string $expression): void
    {
        if ($this->isMySql()) {
            DB::statement("ALTER TABLE `users` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    private function dropCheck(string $name): void
    {
        if ($this->isMySql()) {
            DB::statement("ALTER TABLE `users` DROP CONSTRAINT `{$name}`");
        }
    }

    private function isMySql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
