<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subcategories of a cycle domain (for example الجودة within الإبداع).
 *
 * Each subcategory belongs to one cycle domain and, through the composite
 * key (cycle_domain_id, cycle_id), to that domain's cycle. (id,
 * cycle_domain_id) is the parent key that lets an application's subcategory
 * be proven to belong to the application's own domain. Subcategories carry
 * no award slots, rankings, committees or rubrics.
 *
 * Rows are provisioned by `php artisan nomination:setup` for an explicitly
 * chosen cycle; this migration creates no rows.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cycle_domain_subcategories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('cycle_domain_id');
            $table->string('code', 64);
            $table->string('name_ar', 150);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->foreign(['cycle_domain_id', 'cycle_id'], 'fk_cycle_domain_subcategories_domain')
                ->references(['id', 'cycle_id'])->on('cycle_domains')->restrictOnDelete();
            $table->unique(['cycle_domain_id', 'code'], 'uq_cycle_domain_subcategories_code');
            $table->unique(['cycle_id', 'code'], 'uq_cycle_domain_subcategories_cycle_code');
            $table->unique(['id', 'cycle_domain_id'], 'uq_cycle_domain_subcategories_id_domain');
        });
        $this->addCheck('cycle_domain_subcategories', 'chk_cycle_domain_subcategories_code', "code <> ''");
    }

    /**
     * Reverse the migrations.
     *
     * Refused, before anything is dropped, while subcategories exist: they
     * are cycle configuration that applications may refer to.
     */
    public function down(): void
    {
        if (Schema::hasTable('cycle_domain_subcategories')) {
            $rows = DB::table('cycle_domain_subcategories')->count();

            if ($rows > 0) {
                throw new RuntimeException("Cannot drop cycle_domain_subcategories: {$rows} subcategory row(s) are configured.");
            }
        }

        Schema::dropIfExists('cycle_domain_subcategories');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
