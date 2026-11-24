<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\BackupManagerActivityLogger;
use MertAydin\LaravelBackupManager\BackupPreflight;

final class RunBackupCommand extends Command
{
    protected $signature = 'backup:run
        {--profile= : Backup profile name}
        {--database : Include the database}
        {--files : Include application files}
        {--disk= : Override the configured storage disk}';

    protected $description = 'Create and upload a Laravel application backup';

    public function handle(
        BackupManager $manager,
        BackupPreflight $preflight,
        CacheRepository $cache,
        BackupManagerActivityLogger $activity,
    ): int {
        $startedAt = now();
        $options = [];
        if ($this->option('profile')) {
            $options['profile'] = $this->option('profile');
        }

        if ($this->option('database') || $this->option('files')) {
            $options['database'] = (bool) $this->option('database');
            $options['files'] = (bool) $this->option('files');
        }

        if ($this->option('disk')) {
            $options['disk'] = $this->option('disk');
        }

        $checks = $preflight->inspect($options);
        if (! $checks['ready']) {
            $message = implode(' ', $checks['errors']);
            $cache->put('backup-manager:last-run', ['status' => 'failed', 'error' => $message, 'finished_at' => now()->toIso8601String()], now()->addDays(30));
            $activity->record('backup.artisan', 'failed', disk: isset($options['disk']) ? (string) $options['disk'] : null, message: $message, metadata: ['options' => $options], startedAt: $startedAt->toDateTimeString(), finishedAt: now()->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds(now()));
            foreach ($checks['errors'] as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $maxAttempts = max(1, (int) config('backup-manager.retry.max_attempts', 1));
        $retryDelay = max(0, (int) config('backup-manager.retry.delay_seconds', 30));
        $result = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $manager->run($options);
            if ($result->successful || $attempt === $maxAttempts) {
                break;
            }

            $this->components->warn("Backup attempt {$attempt}/{$maxAttempts} failed. Retrying in {$retryDelay} seconds...");
            if ($retryDelay > 0) {
                sleep($retryDelay);
            }
        }

        $finishedAt = now();
        if (! $result?->successful) {
            $message = $result?->error ?? 'Backup failed.';
            $cache->put('backup-manager:last-run', ['status' => 'failed', 'backup_id' => $result?->id, 'error' => $message, 'finished_at' => $finishedAt->toIso8601String()], now()->addDays(30));
            $activity->record('backup.artisan', 'failed', $result?->id, disk: isset($options['disk']) ? (string) $options['disk'] : null, message: $message, metadata: ['options' => $options, 'attempts' => $maxAttempts], startedAt: $startedAt->toDateTimeString(), finishedAt: $finishedAt->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds($finishedAt));
            $this->components->error($message);

            return self::FAILURE;
        }

        $cache->put('backup-manager:last-run', ['status' => 'completed', 'backup_id' => $result->id, 'finished_at' => $finishedAt->toIso8601String()], now()->addDays(30));
        $activity->record('backup.artisan', 'completed', $result->id, disk: isset($options['disk']) ? (string) $options['disk'] : null, metadata: ['options' => $options, 'attempts' => $attempt], startedAt: $startedAt->toDateTimeString(), finishedAt: $finishedAt->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds($finishedAt));
        $this->components->info('Backup completed: '.$result->id);
        foreach ($result->artifacts as $name => $path) {
            $this->line($name.': '.$path);
        }

        return self::SUCCESS;
    }
}
