<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager\\Jobs;

use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Cache\\Repository as CacheRepository;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use MertAydin\\LaravelBackupManager\\BackupManager;
use Throwable;

final class CreateBackupJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $jobId,
        public readonly array $options,
    ) {
    }

    public function handle(BackupManager $manager, CacheRepository $cache): void
    {
        $key = 'backup-manager:jobs:'.$this->jobId;
        $cache->put($key, ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addDay());

        try {
            $result = $manager->run($this->options);
            $cache->put($key, [
                'status' => $result->successful ? 'completed' : 'failed',
                'backup_id' => $result->id,
                'error' => $result->error,
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (Throwable $exception) {
            report($exception);
            $cache->put($key, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        cache()->put('backup-manager:jobs:'.$this->jobId, [
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Backup job failed.',
            'finished_at' => now()->toIso8601String(),
        ], now()->addDay());
    }
}
