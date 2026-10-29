<?php

use App\Support\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner-authorized, one-time removal of the retired Stage 2 work fields and
 * their stored values, in every cycle, for drafts and submitted
 * applications alike: funding (type, funder, amount), partnerships (the
 * yes/no answer and the partners), places of implementation, and the
 * implementation schedule (start, end, ongoing or completed).
 *
 * In bounded batches, each in its own transaction:
 * 1. applications: the seven retired columns become NULL (the columns stay,
 *    retired; chk_applications_retired_work refuses any new value);
 * 2. application_locations and application_partnerships: every row is
 *    deleted (both held only these work fields; the tables stay, unused);
 * 3. application_versions: exactly data_snapshot.nomination_details
 *    .{implementation, locations, funding, partnerships} are removed (the
 *    form snapshot never held them; the same path is cleared there if
 *    present), and content_hash is recomputed with CanonicalJson. Ids,
 *    version numbers, submission attribution and times, the rest of the
 *    snapshot and the linked files are untouched;
 * 4. audit_logs: only `funding_type` is removed from the payload of
 *    `nomination_details.confirmed` events about an application; the events
 *    stay.
 *
 * Evidence is durable and interruption-safe: every destructive batch writes
 * its own nomination_fields.purged row (kind, rows removed, and for
 * versions their ids with the old and new hashes) IN THE SAME TRANSACTION
 * as the change, so a batch that committed always left its evidence, and an
 * interrupted run resumed later keeps the evidence of both runs. A final
 * `summary` row (this run's counts) is informational only. No removed value
 * is ever recorded, and no copy of one is kept anywhere.
 *
 * The values cannot be recreated: down() decodes every evidence row
 * (structurally, whatever its JSON formatting) and refuses as soon as any
 * batch removed something. Restore the pre-deployment backup instead. A
 * fresh installation (nothing removed) can still roll back.
 *
 * Run it with the application in maintenance mode (administration.md §10),
 * so no request from an earlier release can write a retired value while
 * the batches run.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_29_900003_purge_retired_nomination_fields';

    private const BATCH = 200;

    /** Refuses any new value in the retired columns (MySQL/MariaDB). */
    private const GUARD = 'chk_applications_retired_work';

    private const COLUMNS = ['funding_type', 'funder_name', 'funding_amount_usd', 'has_partnerships', 'implementation_started_on', 'implementation_ended_on', 'implementation_status'];

    /** What a destructive batch records (its evidence kinds). */
    private const KINDS = ['applications', 'locations', 'partnerships', 'versions', 'form_snapshots', 'audit_rows'];

    /** The retired keys of a snapshot's nomination_details, exactly. */
    private const SNAPSHOT_KEYS = ['implementation', 'locations', 'funding', 'partnerships'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->purge();

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $retired = implode(' AND ', array_map(fn (string $column): string => "{$column} IS NULL", self::COLUMNS));
            DB::statement('ALTER TABLE `applications` ADD CONSTRAINT `'.self::GUARD."` CHECK ({$retired})");
        }
    }

    /**
     * Reverse the migrations: only the guard can go. Refused, before any
     * change, when any run of this migration (completed or interrupted)
     * removed stored values, which no rollback can recreate; restore the
     * pre-deployment backup instead.
     */
    public function down(): void
    {
        if (array_sum($this->evidence()) > 0) {
            throw new RuntimeException('Cannot roll back: this migration permanently removed retired nomination values, which a rollback cannot recreate. Restore the backup taken before deployment instead. Nothing was changed.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `applications` DROP CONSTRAINT `'.self::GUARD.'`');
        }
        DB::table('audit_logs')->whereIn('id', array_keys($this->records()))->delete();
    }

    /**
     * Rows removed or redacted so far, per kind, over every run: the sum of
     * the evidence each destructive batch committed with its change.
     *
     * @return array<string, int>
     */
    public function evidence(): array
    {
        $totals = array_fill_keys(self::KINDS, 0);
        foreach ($this->records() as $record) {
            if (in_array($record['kind'] ?? null, self::KINDS, true)) {
                $totals[$record['kind']] += (int) ($record['removed'] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * Removes the stored values, in batches, and records what changed.
     * Idempotent: what is already removed is not touched again, so an
     * interrupted run can simply be repeated. Public so the removal can be
     * exercised on its own. Returns this run's counts.
     *
     * @return array<string, int>
     */
    public function purge(): array
    {
        $counts = array_fill_keys(self::KINDS, 0) + ['unverified_old_hashes' => 0];

        do {
            $ids = DB::table('applications')
                ->where(function ($query): void {
                    foreach (self::COLUMNS as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if ($ids !== []) {
                DB::transaction(function () use ($ids): void {
                    DB::table('applications')->whereIn('id', $ids)->update(array_fill_keys(self::COLUMNS, null));
                    $this->record(['kind' => 'applications', 'removed' => count($ids)]);
                });
                $counts['applications'] += count($ids);
            }
        } while ($ids !== []);

        foreach (['application_locations' => 'locations', 'application_partnerships' => 'partnerships'] as $table => $kind) {
            do {
                $ids = DB::table($table)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
                if ($ids !== []) {
                    DB::transaction(function () use ($table, $kind, $ids): void {
                        DB::table($table)->whereIn('id', $ids)->delete();
                        $this->record(['kind' => $kind, 'removed' => count($ids)]);
                    });
                    $counts[$kind] += count($ids);
                }
            } while ($ids !== []);
        }

        DB::table('application_versions')->orderBy('id')->chunkById(self::BATCH, function ($versions) use (&$counts): void {
            DB::transaction(function () use ($versions, &$counts): void {
                $changed = [];
                $forms = 0;
                foreach ($versions as $version) {
                    $data = json_decode((string) $version->data_snapshot, true);
                    $form = json_decode((string) $version->form_snapshot, true);
                    [$data, $dataChanged] = $this->withoutRetiredKeys($data);
                    [$form, $formChanged] = $this->withoutRetiredKeys($form);
                    if (! $dataChanged && ! $formChanged) {
                        continue;
                    }

                    $update = [];
                    if ($dataChanged) {
                        $old = (string) $version->content_hash;
                        $before = json_decode((string) $version->data_snapshot, true);
                        $verified = CanonicalJson::hash($before) === $old;
                        $new = CanonicalJson::hash($data);
                        $update += ['data_snapshot' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'content_hash' => $new];
                        $changed[] = ['application_version_id' => (int) $version->id, 'old_hash' => $old, 'new_hash' => $new, 'old_hash_verified' => $verified];
                        $counts['unverified_old_hashes'] += $verified ? 0 : 1;
                    }
                    if ($formChanged) {
                        $update['form_snapshot'] = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $forms++;
                    }
                    DB::table('application_versions')->where('id', $version->id)->update($update);
                }

                if ($changed !== []) {
                    $this->record(['kind' => 'versions', 'removed' => count($changed), 'versions' => $changed]);
                    $counts['versions'] += count($changed);
                }
                if ($forms > 0) {
                    $this->record(['kind' => 'form_snapshots', 'removed' => $forms]);
                    $counts['form_snapshots'] += $forms;
                }
            });
        });

        DB::table('audit_logs')->where('action', 'nomination_details.confirmed')->where('subject_type', 'application')
            ->where('after_data', 'like', '%funding_type%')->orderBy('id')
            ->chunkById(self::BATCH, function ($rows) use (&$counts): void {
                DB::transaction(function () use ($rows, &$counts): void {
                    $redacted = 0;
                    foreach ($rows as $row) {
                        $payload = json_decode((string) $row->after_data, true);
                        if (! is_array($payload) || ! array_key_exists('funding_type', $payload)) {
                            continue;
                        }
                        unset($payload['funding_type']);
                        DB::table('audit_logs')->where('id', $row->id)->update(['after_data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                        $redacted++;
                    }
                    if ($redacted > 0) {
                        $this->record(['kind' => 'audit_rows', 'removed' => $redacted]);
                        $counts['audit_rows'] += $redacted;
                    }
                });
            });

        $this->record(['kind' => 'summary'] + $counts);

        return $counts;
    }

    /**
     * The snapshot without the retired keys of its nomination_details.
     *
     * @return array{0: mixed, 1: bool}
     */
    private function withoutRetiredKeys(mixed $snapshot): array
    {
        if (! is_array($snapshot) || ! is_array($snapshot['nomination_details'] ?? null)) {
            return [$snapshot, false];
        }

        $present = array_intersect(self::SNAPSHOT_KEYS, array_keys($snapshot['nomination_details']));
        foreach ($present as $key) {
            unset($snapshot['nomination_details'][$key]);
        }

        return [$snapshot, $present !== []];
    }

    /**
     * This migration's evidence rows, decoded: id => data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $records = [];
        foreach (DB::table('audit_logs')->where('action', 'nomination_fields.purged')->orderBy('id')->get(['id', 'after_data']) as $row) {
            $data = json_decode((string) $row->after_data, true);
            if (is_array($data) && ($data['migration'] ?? null) === self::MIGRATION) {
                $records[(int) $row->id] = $data;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(array $data): void
    {
        DB::table('audit_logs')->insert([
            'action' => 'nomination_fields.purged',
            'subject_type' => null,
            'subject_id' => null,
            'after_data' => json_encode($data + ['migration' => self::MIGRATION], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
};
