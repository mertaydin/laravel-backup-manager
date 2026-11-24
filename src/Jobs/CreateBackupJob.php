<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use MertAydin\LaravelBackupManager\BackupManager;
use RuntimeException;
use Throwable;

final class CreateBackupJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $options */
    public int $tries;

    public int $backoff;

    public function __construct(
        public readonly string $jobId,
        public readonly array $options,
    ) {
        $this->tries = max(1, (int) config('backup-manager.retry.max_attempts', 1));
        $this->backoff = max(0, (int) config('backup-manager.retry.delay_seconds', 30));
    }

    public function handle(BackupManager $manager, CacheRepository $cache, BackupManagerActivityLogger $activity): void
    {
        $key = 'backup-manager:jobs:'.$this->jobId;
        $startedAt = now();
        $cache->put($key, ['status' => 'running', 'started_at' => $startedAt->toIso8601String()], now()->addDay());\n        $activity->record('backup.started', 'running', jobId: $this->jobId, disk: isset($this->options['disk']) ? (string) $this->options['disk'] : null, metadata: ['type' => $this->options['type'] ?? 'manual'], startedAt: $startedAt->toDateTimeString());

        try {
            $result = $manager->run($this->options);
            $finishedAt = now();
            $status = [
                'status' => $result->successful ? 'completed' : 'failed',
                'job_id' => $this->jobId,
                'backup_id' => $result->id,
                'error' => $result->error,
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt->toIso8601String(),
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            ];
            if (! $result->successful) {
                $cache->put($key, ['status' => 'retrying', 'backup_id' => $result->id, 'error' => $result->error, 'finished_at' => now()->toIso8601String()], now()->addDay());
                throw new RuntimeException($result->error ?? 'Backup job failed.');
            }
            $cache->put($key, $status, now()->addDay());
            $cache->put('backup-manager:last-run', $status, now()->addDays(30));\n            $activity->record('backup.completed', 'completed', $result->id, $this->jobId, isset($this->options['disk']) ? (string) $this->options['disk'] : null, startedAt: $startedAt->toDateTimeString(), finishedAt: $finishedAt->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds($finishedAt));\n            $this->releaseDedupeLock($cache);
        } catch (Throwable $exception) {
            report($exception);
            $finishedAt = now();
            $status = [
                'status' => $this->job !== null && $this->attempts() < $this->tries ? 'retrying' : 'failed',
                'job_id' => $this->jobId,
                'error' => $exception->getMessage(),
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt->toIso8601String(),
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            ];
            $cache->put($key, $status, now()->addDay());
            if ($status['status'] === 'failed') {
                $status['options'] = $this->options;
            }
            $cache->put('backup-manager:last-run', $status, now()->addDays(30));

            throw $exception;
        }
    }

    private function releaseDedupeLock(CacheRepository $cache): void\n    {\n        $profile = (string) ($this->options['profile'] ?? config('backup-manager.default', 'default'));\n        $key = 'backup-manager:dedupe:'.$profile;\n        if ($cache->get($key) === $this->jobId) {\n            $cache->forget($key);\n        }\n    }\n\n    public function failed(?Throwable $exception): void
    {
        $finishedAt = now();
        $jobStatus = cache()->get('backup-manager:jobs:'.$this->jobId, []);
        $startedAt = is_array($jobStatus) && empty($jobStatus['started_at']) === false
            ? Carbon::parse($jobStatus['started_at'])
            : $finishedAt;
        $status = [
            'status' => 'failed',
            'job_id' => $this->jobId,
            'error' => $exception?->getMessage() ?? 'Backup job failed.',
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            'options' => $this->options,
        ];
        cache()->put('backup-manager:jobs:'.$this->jobId, array_diff_key($status, ['options' => true]), now()->addDay());
        cache()->put('backup-manager:last-run', $status, now()->addDays(30));\n        app(BackupManagerActivityLogger::class)->record('backup.failed', 'failed', jobId: $this->jobId, disk: isset($this->options['disk']) ? (string) $this->options['disk'] : null, message: $status['error'], startedAt: $startedAt->toDateTimeString(), finishedAt: $finishedAt->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds($finishedAt));\n        $this->releaseDedupeLock(cache());
    }
}
