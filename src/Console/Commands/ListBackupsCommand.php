<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupInspector;
use Throwable;

final class ListBackupsCommand extends Command
{
    protected $signature = 'backup:list
        {--profile= : Backup profile name}
        {--disk= : Override the configured storage disk}
        {--limit=20 : Maximum number of backups to display}';

    protected $description = 'List available application backups';

    public function handle(BackupInspector $inspector): int
    {
        try {
            $backups = $inspector->list(
                $this->option('profile') ?: null,
                $this->option('disk') ?: null,
                max(1, (int) $this->option('limit'))
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($backups === []) {
            $this->components->info('No backups found.');

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $backup): array => [
            $backup['id'],
            $backup['created_at'] ?? 'Unknown',
            $backup['profile'],
            $backup['disk'],
            number_format((int) $backup['total_size']).' B',
            isset($backup['error']) ? 'INVALID' : 'Available',
        ], $backups);

        $this->table(['Backup ID', 'Created at', 'Profile', 'Disk', 'Size', 'Status'], $rows);

        return self::SUCCESS;
    }
}
