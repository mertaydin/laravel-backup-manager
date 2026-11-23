<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use MertAydin\LaravelBackupManager\BackupManager;
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
            $result = $manager->run(['type' => 'scheduled']);
            if (! $result->successful) {
                $cache->forget($lockKey);
                $this->components->error($result->error ?? 'Scheduled backup failed.');

                return self::FAILURE;
            }

            $profileName = (string) config('backup-manager.default', 'default');
            config([
                'backup-manager.profiles.'.$profileName.'.retention.enabled' => true,
                'backup-manager.profiles.'.$profileName.'.retention.keep_days' => max(1, (int) ($schedule['retain_days'] ?? 30)),
                'backup-manager.profiles.'.$profileName.'.retention.keep_last' => 0,
            ]);

            $pruned = $pruner->run($profileName, null, true);
            $this->components->info('Scheduled backup completed: '.$result->id);
            $this->line('Expired backups deleted: '.count($pruned['deleted']));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $cache->forget($lockKey);
            report($exception);
            $this->components->error('Scheduled backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
