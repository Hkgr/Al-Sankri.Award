<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External sign-in identities (currently Google only) linked to accounts.
 *
 * The identity key is the provider's stable subject identifier ("sub"),
 * never the email address. Each subject belongs to one account, and an
 * account has at most one identity per provider. No provider access or
 * refresh tokens are stored: sign-in does not need them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isMySql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('oauth_identities', function (Blueprint $table) use ($isMySql) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_oauth_identities_user')->cascadeOnDelete();
            $table->string('provider', 32);
            $subject = $table->string('provider_subject', 255);
            if ($isMySql) {
                // Subjects are opaque, case-sensitive ASCII identifiers.
                $subject->charset('ascii')->collation('ascii_bin');
            }
            $table->dateTime('linked_at');
            $table->dateTime('last_login_at')->nullable();
            $table->datetimes();

            $table->unique(['provider', 'provider_subject'], 'uq_oauth_identities_subject');
            $table->unique(['user_id', 'provider'], 'uq_oauth_identities_user_provider');
        });

        $this->addCheck('oauth_identities', 'chk_oauth_identities_provider', "provider IN ('google')");
        $this->addCheck('oauth_identities', 'chk_oauth_identities_subject', "provider_subject <> ''");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
