<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupProtector;
use Throwable;

final class ProtectBackupCommand extends Command
{
    protected $signature = 'backup:protect
        {id : Backup identifier}
        {--profile= : Backup profile name}
        {--until= : Protect until this date/time (ISO-8601)}
        {--reason= : Reason for protecting this backup}
        {--unlock : Remove deletion protection}';

    protected $description = 'Protect a backup from manual deletion and retention pruning';

    public function handle(BackupProtector $protector): int
    {
        try {
            $unlock = (bool) $this->option('unlock');
            $until = $this->option('until') ? (string) $this->option('until') : null;
            if ($unlock && $until !== null) {
                $this->components->error('Do not combine --unlock and --until.');
                return self::FAILURE;
            }

            $protector->update(
                (string) $this->argument('id'),
                $this->option('profile') ? (string) $this->option('profile') : null,
                ! $unlock,
                $until,
                $this->option('reason') ? (string) $this->option('reason') : null,
            );

            $this->components->info($unlock ? 'Backup deletion protection removed.' : 'Backup is now protected from deletion.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
