<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('preferred_locale', 2)->nullable();
        });
        Schema::create('application_progress_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('application_version_id')->constrained('application_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('cycle_id')->index();
            $table->unsignedInteger('sequence');
            $table->string('stage', 32);
            $table->boolean('via_committee')->default(false);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('recorded_at');
            $table->boolean('historical')->default(false);
            $table->unique(['application_version_id', 'sequence'], 'uq_progress_version_sequence');
        });
        Schema::create('application_progress_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('application_progress_events')->cascadeOnDelete();
            // Immutable account identifier, not retargeted to an archive identity during account removal.
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->string('locale', 2)->default('ar');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('attempt_limit')->default(8);
            $table->timestamp('available_at');
            $table->timestamp('last_enqueued_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'recipient_user_id'], 'uq_progress_event_recipient');
            $table->index(['status', 'available_at'], 'ix_progress_notifications_due');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_progress_notifications');
        Schema::dropIfExists('application_progress_events');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferred_locale');
        });
    }
};
