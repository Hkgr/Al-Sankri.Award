<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dateTime('deleted_at')->nullable()->index());
        Schema::create('account_removals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('target_user_id')->index();
            $table->string('mode', 16);
            $table->json('impact');
            $table->dateTime('completed_at');
        });
        Schema::create('removed_account_files', function (Blueprint $table) {
            $table->id();
            $table->string('storage_disk', 32);
            $table->string('storage_path', 500);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 100)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['storage_disk', 'storage_path']);
        });
        $permission = DB::table('permissions')->where('code', 'users.remove_accounts')->value('id');
        $created = $permission === null;
        $permission ??= DB::table('permissions')->insertGetId([
            'code' => 'users.remove_accounts', 'name_ar' => 'حذف حسابات المرشحين والمحكّمين', 'module' => 'users', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('code', 'award_admin')->value('id');
        $link = DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->value('id');
        $linked = $link === null;
        $link ??= DB::table('role_permissions')->insertGetId(['role_id' => $role, 'permission_id' => $permission, 'created_at' => now()]);
        DB::table('audit_logs')->insert([
            'action' => 'account_removal.schema_installed', 'subject_type' => 'permission', 'subject_id' => $permission,
            'after_data' => json_encode(['created' => $created, 'linked' => $linked, 'link_id' => $link]), 'occurred_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('account_removals')->exists()) {
            throw new RuntimeException('Account removal has been used. Restore a verified backup instead of rolling it back.');
        }
        $mark = DB::table('audit_logs')->where('action', 'account_removal.schema_installed')->orderByDesc('id')->first();
        if ($mark !== null) {
            $owned = json_decode($mark->after_data, true);
            if ($owned['created'] && DB::table('role_permissions')->where('permission_id', $mark->subject_id)->when($owned['linked'], fn ($query) => $query->where('id', '<>', $owned['link_id']))->exists()) {
                throw new RuntimeException('Account-removal permission has additional grants. Review these before rollback.');
            }
            if ($owned['linked']) {
                DB::table('role_permissions')->where('id', $owned['link_id'])->where('permission_id', $mark->subject_id)->delete();
            }
            if ($owned['created']) {
                DB::table('permissions')->where('id', $mark->subject_id)->where('code', 'users.remove_accounts')->delete();
            }
        }
        Schema::dropIfExists('removed_account_files');
        Schema::dropIfExists('account_removals');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('deleted_at'));
    }
};
