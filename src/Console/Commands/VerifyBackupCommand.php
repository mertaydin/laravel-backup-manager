<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupInspector;
use Throwable;

final class VerifyBackupCommand extends Command
{
    protected $signature = 'backup:verify
        {id : Backup identifier}
        {--profile= : Backup profile name}
        {--disk= : Override the configured storage disk}';

    protected $description = 'Verify backup artifacts against their SHA-256 manifest';

    public function handle(BackupInspector $inspector): int
    {
        try {
            $result = $inspector->verify(
                (string) $this->argument('id'),
                $this->option('profile') ?: null,
                $this->option('disk') ?: null
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['artifacts'] as $name => $artifact) {
            if ($artifact['valid']) {
                $this->components->twoColumnDetail($name, '<fg=green>OK</>');
            } else {
                $this->components->twoColumnDetail($name, '<fg=red>FAILED</>');
            }
        }

        if (! $result['valid']) {
            foreach ($result['errors'] as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info('Backup integrity verified successfully.');

        return self::SUCCESS;
    }
}
