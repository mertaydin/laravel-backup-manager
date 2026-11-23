<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupReporter;
use Throwable;

final class ReportBackupsCommand extends Command
{
    protected $signature = 'backup:report {--profile=} {--disk=} {--json : Output machine-readable JSON}';
    protected $description = 'Generate a backup inventory and storage usage report';

    public function handle(BackupReporter $reporter): int
    {
        try {
            $report = $reporter->generate(
                $this->option('profile') ? (string) $this->option('profile') : null,
                $this->option('disk') ? (string) $this->option('disk') : null,
            );
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }

            $this->table(['Metric', 'Value'], [
                ['Total backups', $report['total_backups']],
                ['Valid backups', $report['valid_backups']],
                ['Invalid backups', $report['invalid_backups']],
                ['Stored bytes', $report['total_bytes']],
                ['Latest backup', $report['latest_backup']['id'] ?? '—'],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
