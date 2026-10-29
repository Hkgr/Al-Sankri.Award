<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The administrative classification of a submitted application, separate
 * from its workflow status (applications.status) and from any judge's own
 * exclusion recommendation:
 *
 * - review_status: pending «لم يُصنّف بعد», referred «مرسل للتحكيم» or
 *   excluded «مستبعد — مؤرشف»; NULL for a draft;
 * - review_source: `admin` (decided by an administrator) or `legacy` (a
 *   referral recorded from judging history that existed before this
 *   classification; no administrator is named for it);
 * - review_decided_by / review_decided_at: the deciding administrator and
 *   the time (a legacy referral keeps no administrator; its time is the
 *   first real assignment's assigned_at);
 * - review_reason: the exclusion reason (administration only);
 * - review_committee_id: the destination judging committee of a referral,
 *   tied by fk_applications_review_committee to the application's own
 *   main domain, and through it to its cycle.
 *
 * Backfill, in batches, for submitted applications only (drafts stay NULL):
 * an application with judging assignments becomes a legacy referral to the
 * committee of those assignments; every other submitted application becomes
 * pending. A judge's exclusion is never read as an administrative one, and
 * no score, recommendation or assignment changes. Inconsistent legacy states
 * are counted in the review_classification.backfilled audit row, never fixed
 * silently.
 *
 * Rollback is refused while any administrator decision exists (it would be
 * lost); otherwise the columns go and the backfill record with them.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_29_900001_add_application_review_classification';

    private const BATCH = 500;

    private const COLUMNS = ['review_status', 'review_source', 'review_decided_by', 'review_decided_at', 'review_reason', 'review_committee_id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('review_status', 16)->nullable()->after('status');
            $table->string('review_source', 16)->nullable()->after('review_status');
            $table->foreignId('review_decided_by')->nullable()->after('review_source')
                ->constrained('users', 'id', 'fk_applications_review_decided_by')->restrictOnDelete();
            $table->dateTime('review_decided_at')->nullable()->after('review_decided_by');
            $table->string('review_reason', 500)->nullable()->after('review_decided_at');
            $table->unsignedBigInteger('review_committee_id')->nullable()->after('review_reason');

            $table->index(['review_status', 'cycle_id'], 'idx_applications_review');
            $table->foreign(['review_committee_id', 'cycle_domain_id'], 'fk_applications_review_committee')
                ->references(['id', 'cycle_domain_id'])->on('committees')->restrictOnDelete();
        });

        $this->backfill();

        $this->addCheck('chk_applications_review_status', "review_status IS NULL OR review_status IN ('pending', 'referred', 'excluded')");
        $this->addCheck('chk_applications_review_submitted', 'review_status IS NULL OR current_version_id IS NOT NULL');
        // Wrapped in IS TRUE: a comparison with a NULL column (for example a
        // referral without review_source) makes a branch UNKNOWN, and a CHECK
        // accepts UNKNOWN; only an explicitly true combination passes.
        $this->addCheck('chk_applications_review_consistent',
            '((review_status IS NULL AND review_source IS NULL AND review_decided_by IS NULL AND review_decided_at IS NULL AND review_reason IS NULL AND review_committee_id IS NULL)'
            ." OR (review_status = 'pending' AND review_source IS NULL AND review_decided_by IS NULL AND review_decided_at IS NULL AND review_reason IS NULL AND review_committee_id IS NULL)"
            ." OR (review_status = 'referred' AND review_committee_id IS NOT NULL AND review_decided_at IS NOT NULL AND review_reason IS NULL"
            ." AND ((review_source = 'admin' AND review_decided_by IS NOT NULL) OR (review_source = 'legacy' AND review_decided_by IS NULL)))"
            ." OR (review_status = 'excluded' AND review_source = 'admin' AND review_decided_by IS NOT NULL AND review_decided_at IS NOT NULL"
            ." AND review_reason IS NOT NULL AND review_reason <> '' AND review_committee_id IS NULL)) IS TRUE");
    }

    /**
     * Reverse the migrations.
     *
     * Refused, before anything changes, while an administrator decided any
     * classification: dropping the columns would lose that decision.
     */
    public function down(): void
    {
        $decided = DB::table('applications')->where('review_source', 'admin')->count();
        if ($decided > 0) {
            throw new RuntimeException("Cannot roll back the review classification: {$decided} application(s) carry an administrator's referral or exclusion. Nothing was changed.");
        }

        foreach (['chk_applications_review_consistent', 'chk_applications_review_submitted', 'chk_applications_review_status'] as $check) {
            $this->dropCheck($check);
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign('fk_applications_review_committee');
            $table->dropForeign('fk_applications_review_decided_by');
            $table->dropIndex('idx_applications_review');
            $table->dropColumn(self::COLUMNS);
        });

        DB::table('audit_logs')->where('action', 'review_classification.backfilled')
            ->where('after_data', 'like', '%'.self::MIGRATION.'%')->delete();
    }

    /**
     * Classifies every submitted application that has no classification yet
     * (drafts and classified ones are left alone), in batches, and records
     * the counts. Public so the rules can be exercised on their own.
     *
     * @return array<string, int>
     */
    public function backfill(): array
    {
        $counts = ['pending' => 0, 'legacy_referred' => 0, 'in_judging_without_assignments' => 0, 'assignments_in_several_committees' => 0];
        DB::table('applications')->whereNotNull('current_version_id')->whereNull('review_status')->orderBy('id')
            ->chunkById(self::BATCH, function ($applications) use (&$counts): void {
                DB::transaction(function () use ($applications, &$counts): void {
                    foreach ($applications as $application) {
                        $assignments = DB::table('judging_assignments')->where('application_id', $application->id)
                            ->orderBy('assigned_at')->orderBy('id')->get(['committee_id', 'application_version_id', 'assigned_at']);

                        if ($assignments->isEmpty()) {
                            DB::table('applications')->where('id', $application->id)->update(['review_status' => 'pending']);
                            $counts['pending']++;
                            if ($application->status === 'in_judging') {
                                $counts['in_judging_without_assignments']++;
                            }

                            continue;
                        }

                        $committees = $assignments->pluck('committee_id')->unique();
                        if ($committees->count() > 1) {
                            $counts['assignments_in_several_committees']++;
                        }
                        // The committee judging the current version, else the first one.
                        $committee = $assignments->firstWhere('application_version_id', $application->current_version_id)?->committee_id ?? $assignments->first()->committee_id;

                        DB::table('applications')->where('id', $application->id)->update([
                            'review_status' => 'referred',
                            'review_source' => 'legacy',
                            'review_decided_at' => $assignments->first()->assigned_at,
                            'review_committee_id' => $committee,
                        ]);
                        $counts['legacy_referred']++;
                    }
                });
            });

        DB::table('audit_logs')->insert([
            'action' => 'review_classification.backfilled',
            'subject_type' => null,
            'subject_id' => null,
            'after_data' => json_encode($counts + ['migration' => self::MIGRATION]),
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $counts;
    }

    private function addCheck(string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `applications` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    private function dropCheck(string $name): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `applications` DROP CONSTRAINT `{$name}`");
        }
    }
};
