<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 of the candidate journey («ملفات العمل وتوثيقه») and the final
 * submission, on the existing tables wherever they fit:
 *
 * - uploaded files are rows of `files`, attached through `file_links`
 *   (one target per row, unchanged); `files.upload_operation_id` is the
 *   browser's identifier for one upload, unique per uploader, so a retried
 *   upload whose answer was lost finds its file instead of adding another;
 * - `application_documentation_links`: the external documentation links
 *   (title and HTTPS address) of a draft application. A submission copies
 *   them into the immutable version snapshot;
 * - `candidate_nominations.documentation_revision` counts every change to
 *   the attachments and links; Stage 3 is confirmed for one revision, and
 *   the final submission is bound to the revision the candidate reviewed.
 *
 * The submission itself uses the existing applications columns
 * (status, reference_number, first_submitted_at, current_version_id) and
 * application_versions.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->char('upload_operation_id', 36)->nullable()->after('source_file_id');
            $table->unique(['uploaded_by', 'upload_operation_id'], 'uq_files_upload_operation');
        });

        Schema::create('application_documentation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications', 'id', 'fk_application_documentation_links_application')->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('title', 150);
            $table->string('url', 2048);
            $table->datetimes();

            $table->unique(['application_id', 'position'], 'uq_application_documentation_links_position');
        });
        $this->addCheck('application_documentation_links', 'chk_application_documentation_links_position', 'position BETWEEN 1 AND 20');
        $this->addCheck('application_documentation_links', 'chk_application_documentation_links_title', "title <> ''");
        $this->addCheck('application_documentation_links', 'chk_application_documentation_links_url', "url LIKE 'https://%'");

        Schema::table('candidate_nominations', function (Blueprint $table) {
            $table->unsignedInteger('documentation_revision')->default(0)->after('details_confirmed_at');
            $table->unsignedInteger('documentation_confirmed_revision')->nullable()->after('documentation_revision');
            $table->dateTime('documentation_confirmed_at')->nullable()->after('documentation_confirmed_revision');
        });
        $this->addCheck('candidate_nominations', 'chk_candidate_nominations_documentation',
            '(documentation_confirmed_revision IS NULL) = (documentation_confirmed_at IS NULL)');
    }

    /**
     * Reverse the migrations.
     *
     * Refused, before anything changes, while any Stage 3 state exists:
     * documentation links, uploads with an operation identifier, or a
     * journey whose documentation has changed or been confirmed. When the
     * Stage 2 migrations were applied in the same batch (a fresh install),
     * they roll back next, so their protected state is counted here too and
     * the batch never stops half-way.
     */
    public function down(): void
    {
        $state = array_filter([...$this->stage2StateRolledBackNext(),
            'documentation link(s)' => Schema::hasTable('application_documentation_links') ? DB::table('application_documentation_links')->count() : 0,
            'uploaded file(s)' => Schema::hasColumn('files', 'upload_operation_id') ? DB::table('files')->whereNotNull('upload_operation_id')->count() : 0,
            'journey(s) with documentation' => Schema::hasColumn('candidate_nominations', 'documentation_revision')
                ? DB::table('candidate_nominations')->where(fn ($query) => $query->where('documentation_revision', '>', 0)->orWhereNotNull('documentation_confirmed_at'))->count()
                : 0,
        ]);

        if ($state !== []) {
            $described = implode(', ', array_map(fn (string $kind, int $rows): string => "{$rows} {$kind}", array_keys($state), $state));
            throw new RuntimeException("Cannot roll back the Stage 3 documentation schema: {$described} exist. Nothing was changed.");
        }

        $this->dropCheck('candidate_nominations', 'chk_candidate_nominations_documentation');
        Schema::table('candidate_nominations', function (Blueprint $table) {
            $table->dropColumn(['documentation_revision', 'documentation_confirmed_revision', 'documentation_confirmed_at']);
        });

        Schema::dropIfExists('application_documentation_links');

        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique('uq_files_upload_operation');
            $table->dropColumn('upload_operation_id');
        });
    }

    /**
     * The Stage 2 state 400003 would refuse, if 400003 is in this batch.
     *
     * @return array<string, int>
     */
    private function stage2StateRolledBackNext(): array
    {
        $batch = fn (string $migration): ?int => DB::table('migrations')->where('migration', $migration)->value('batch');
        if ($batch('2026_10_27_400003_create_candidate_nominations_table') !== $batch('2026_10_27_500001_add_candidate_documentation')) {
            return [];
        }

        $columns = ['subcategory_id', 'purpose_and_mission', 'objectives', 'funding_type', 'funder_name', 'funding_amount_usd', 'has_partnerships'];

        return [
            'confirmed journey(s)' => DB::table('candidate_nominations')->count(),
            'partnership row(s)' => DB::table('application_partnerships')->count(),
            'application(s) holding Stage 2 values' => DB::table('applications')->where(function ($query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })->count(),
            'configured subcategory row(s)' => DB::table('cycle_domain_subcategories')->count(),
        ];
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    private function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        }
    }
};
