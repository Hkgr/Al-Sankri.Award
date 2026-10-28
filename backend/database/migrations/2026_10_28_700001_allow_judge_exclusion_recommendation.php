<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a judge record an exclusion on the evaluation they already send.
 * `exclude` is added to the existing recommendation check; the other
 * values stay. SQLite tests do not install these checks.
 */
return new class extends Migration
{
    private const CHECK = "recommendation IS NULL OR recommendation IN ('recommend', 'not_recommend', 'abstain', 'exclude')";

    private const PREVIOUS = "recommendation IS NULL OR recommendation IN ('recommend', 'not_recommend', 'abstain')";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->enforced()) {
            return;
        }

        $this->replace(self::CHECK);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->enforced()) {
            return;
        }

        if (DB::table('evaluations')->where('recommendation', 'exclude')->exists()) {
            throw new RuntimeException('Cannot roll back while an evaluation records an exclusion.');
        }

        $this->replace(self::PREVIOUS);
    }

    private function enforced(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function replace(string $expression): void
    {
        $drop = DB::getDriverName() === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
        DB::statement("ALTER TABLE `evaluations` {$drop} `chk_evaluations_recommendation`");
        DB::statement("ALTER TABLE `evaluations` ADD CONSTRAINT `chk_evaluations_recommendation` CHECK ({$expression})");
    }
};
