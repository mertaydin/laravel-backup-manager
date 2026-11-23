<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupComparator;
use Throwable;

final class CompareBackupsCommand extends Command
{
    protected $signature = 'backup:compare {left : Older backup ID} {right : Newer backup ID} {--profile=} {--disk=}';
    protected $description = 'Compare two verified backup manifests and artifact sizes';

    public function handle(BackupComparator $comparator): int
    {
        try {
            $result = $comparator->compare(
                (string) $this->argument('left'),
                (string) $this->argument('right'),
                $this->option('profile') ? (string) $this->option('profile') : null,
                $this->option('disk') ? (string) $this->option('disk') : null,
            );
            $this->table(
                ['Artifact', 'Status', 'Old size', 'New size', 'Size delta'],
                array_map(static fn (array $row): array => [
                    $row['artifact'], $row['status'], $row['left_size'] ?? '-', $row['right_size'] ?? '-', $row['size_delta'] ?? '-',
                ], $result['artifacts']),
            );
            $this->line('Changed artifacts: '.$result['changed_count']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
