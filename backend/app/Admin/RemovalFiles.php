<?php

namespace App\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RemovalFiles
{
    /** Retryable deletion of unlinked private bytes, after the owning transaction commits. */
    public function drain(int $limit = 100): int
    {
        $ids = DB::table('removed_account_files')->whereNull('completed_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $row = DB::table('removed_account_files')->where('id', $id)->lockForUpdate()->first();
                if ($row === null || $row->completed_at !== null) {
                    return;
                }
                if (DB::table('files')->where('storage_disk', $row->storage_disk)->where('storage_path', $row->storage_path)->exists()) {
                    DB::table('removed_account_files')->where('id', $id)->update(['last_error' => 'referenced', 'updated_at' => now()]);

                    return;
                }
                try {
                    $disk = Storage::disk($row->storage_disk);
                    if ($disk->exists($row->storage_path) && ! $disk->delete($row->storage_path)) {
                        throw new \RuntimeException('storage_delete_failed');
                    }
                    DB::table('removed_account_files')->where('id', $id)->update([
                        'completed_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null, 'updated_at' => now(),
                    ]);
                } catch (Throwable) {
                    DB::table('removed_account_files')->where('id', $id)->update([
                        'attempts' => $row->attempts + 1, 'last_error' => 'storage_unavailable', 'updated_at' => now(),
                    ]);
                }
            });
        }

        return DB::table('removed_account_files')->whereNull('completed_at')->count();
    }
}
