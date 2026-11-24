<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class BackupManagerActivityLogger
{
    /** @param array<string, mixed> $metadata */
    public function record(
        string $event,
        string $status,
        ?string $backupId = null,
        ?string $jobId = null,
        ?string $disk = null,
        ?string $message = null,
        array $metadata = [],
        ?string $startedAt = null,
        ?string $finishedAt = null,
        ?int $durationSeconds = null,
    ): void {
        try {
            if (! Schema::hasTable('backup_manager_activity')) {
                return;
            }

            DB::table('backup_manager_activity')->insert([
                'event' => $event,
                'status' => $status,
                'backup_id' => $backupId,
                'job_id' => $jobId,
                'disk' => $disk,
                'message' => $message,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_seconds' => $durationSeconds,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array<int, object> */
    public function recent(int $limit = 50, ?string $event = null): array
    {
        try {
            if (! Schema::hasTable('backup_manager_activity')) {
                return [];
            }

            $query = DB::table('backup_manager_activity')->orderByDesc('id');
            if ($event !== null) {
                $query->where('event', $event);
            }

            return $query->limit(max(1, min(200, $limit)))->get()->all();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
