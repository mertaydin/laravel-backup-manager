<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupPruner;
use Throwable;

final class PruneBackupsCommand extends Command
{
    protected $signature = 'backup:prune
        {--profile= : Backup profile name}
        {--disk= : Override the configured storage disk}
        {--execute : Actually delete expired backups (default is a dry run)}';

    protected $description = 'Preview or delete backups outside the configured retention policy';

    public function handle(BackupPruner $pruner): int
    {
        try {
            $result = $pruner->run(
                $this->option('profile') ?: null,
                $this->option('disk') ?: null,
                (bool) $this->option('execute')
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($result['candidates'] === []) {
            $this->components->info('No expired backups found.');

            return self::SUCCESS;
        }

        $rows = array_map(static function (array $backup) use ($execute, $result): array {
            $id = (string) $backup['id'];
            $action = ! $execute
                ? 'Would delete'
                : (in_array($id, $result['deleted'], true) ? 'Deleted' : 'Failed');

            return [
                $id,
                (string) $backup['created_at'],
                number_format((int) $backup['total_size']).' B',
                $action,
            ];
        }, $result['candidates']);

        $this->table(['Backup ID', 'Created at', 'Size', 'Action'], $rows);
        $this->line('Protected backups: '.$result['protected_count']);
        $this->line('Invalid manifests skipped: '.$result['invalid_count']);

        if (! $execute) {
            $this->components->warn('Dry run only. Add --execute to permanently delete the listed backup sets.');

            return self::SUCCESS;
        }

        $this->components->info('Deleted backups: '.count($result['deleted']));
        if ($result['failed'] !== []) {
            $this->components->error('Failed to delete: '.implode(', ', $result['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
