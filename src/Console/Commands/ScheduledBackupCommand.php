<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\BackupPreflight;
use MertAydin\LaravelBackupManager\BackupPruner;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Throwable;

final class ScheduledBackupCommand extends Command
{
    protected $signature = 'backup:scheduled-run';

    protected $description = 'Run the dashboard-configured scheduled backup and retention cleanup';

    public function handle(
        BackupManager $manager,
        BackupPruner $pruner,
        StorageSettingsRepository $settings,
        CacheRepository $cache,
        BackupPreflight $preflight,
    ): int {
        $schedule = $settings->all()['schedule'] ?? [];
        if (! ($schedule['enabled'] ?? false)) {
            return self::SUCCESS;
        }

        $timezone = (string) ($schedule['timezone'] ?? config('app.timezone', 'UTC'));
        $now = now($timezone);
        if ($now->format('H:i') !== (string) ($schedule['time'] ?? '02:00')) {
            return self::SUCCESS;
        }

        $lockKey = 'backup-manager:scheduled:'.$now->format('Y-m-d');
        if (! $cache->add($lockKey, true, now()->addDays(2))) {
            return self::SUCCESS;
        }

        try {
            $checks = $preflight->inspect(['type' => 'scheduled']);
            if (! $checks['ready']) {
                $cache->forget($lockKey);
                $cache->put('backup-manager:last-run', ['status' => 'failed', 'error' => implode(' ', $checks['errors']), 'finished_at' => now()->toIso8601String()], now()->addDays(30));
                foreach ($checks['errors'] as $error) {
                    $this->components->error($error);
                }

                return self::FAILURE;
            }

            $maxAttempts = max(1, (int) config('backup-manager.retry.max_attempts', 1));
            $retryDelay = max(0, (int) config('backup-manager.retry.delay_seconds', 30));
            $result = null;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $result = $manager->run(['type' => 'scheduled']);
                if ($result->successful || $attempt === $maxAttempts) {
                    break;
                }
                $this->components->warn("Scheduled backup attempt {$attempt}/{$maxAttempts} failed. Retrying in {$retryDelay} seconds...");
                if ($retryDelay > 0) {
                    sleep($retryDelay);
                }
            }
            if (! $result?->successful) {
                $cache->forget($lockKey);
                $cache->put('backup-manager:last-run', ['status' => 'failed', 'backup_id' => $result->id, 'error' => $result->error, 'finished_at' => now()->toIso8601String()], now()->addDays(30));
                $this->components->error($result->error ?? 'Scheduled backup failed.');

                return self::FAILURE;
            }

            $profileName = (string) config('backup-manager.default', 'default');
            config([
                'backup-manager.profiles.'.$profileName.'.retention.enabled' => true,
                'backup-manager.profiles.'.$profileName.'.retention.keep_days' => max(1, (int) ($schedule['retain_days'] ?? 30)),
                'backup-manager.profiles.'.$profileName.'.retention.keep_last' => 0,
            ]);

            $cache->put('backup-manager:last-run', ['status' => 'completed', 'backup_id' => $result->id, 'finished_at' => now()->toIso8601String()], now()->addDays(30));
            $pruned = $pruner->run($profileName, null, true);
            $this->components->info('Scheduled backup completed: '.$result->id);
            $this->line('Expired backups deleted: '.count($pruned['deleted']));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $cache->forget($lockKey);
            $cache->put('backup-manager:last-run', ['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()->toIso8601String()], now()->addDays(30));
            report($exception);
            $this->components->error('Scheduled backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
