<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\BackupRestorer;
use RuntimeException;
use Throwable;

final class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {id : Backup identifier}
        {--database : Restore the MySQL database}
        {--files : Restore application files}
        {--profile= : Backup profile name}
        {--disk= : Override the configured storage disk}
        {--target= : Target directory for application files}
        {--force : Execute the restore (default is a verified dry run)}';

    protected $description = 'Verify and restore a database or application file backup';

    public function handle(BackupInspector $inspector, BackupRestorer $restorer): int
    {
        $database = (bool) $this->option('database');
        $files = (bool) $this->option('files');
        if ($database === $files) {
            $this->components->error('Select exactly one component using --database or --files.');

            return self::FAILURE;
        }

        $component = $database ? 'database' : 'files';
        $target = $this->option('target') ? (string) $this->option('target') : null;
        if ($files && $target === null) {
            $this->components->error('The --target option is required when restoring application files.');

            return self::FAILURE;
        }

        $id = (string) $this->argument('id');
        $profileName = $this->option('profile') ? (string) $this->option('profile') : null;
        $diskName = $this->option('disk') ? (string) $this->option('disk') : null;

        try {
            $verification = $inspector->verify($id, $profileName, $diskName);
            if (! $verification['valid']) {
                throw new RuntimeException('Backup integrity verification failed: '.implode(' ', $verification['errors']));
            }

            $artifactNames = array_keys($verification['artifacts']);
            $expectedArtifacts = $component === 'database'
                ? ['database.sql.gz', 'database.sql.gz.enc']
                : ['application.zip', 'application.zip.enc'];

            if (array_intersect($expectedArtifacts, $artifactNames) === []) {
                throw new RuntimeException("The selected backup does not contain a verifiable [{$component}] artifact.");
            }

            if (! $this->option('force')) {
                $this->components->info('Backup integrity verified successfully.');
                $this->line('Component: '.$component);
                if ($target !== null) {
                    $this->line('Target: '.$target);
                }
                $this->components->warn('Dry run only. Add --force to execute this destructive restore.');

                return self::SUCCESS;
            }

            $result = $restorer->restore($id, [
                'component' => $component,
                'profile' => $profileName,
                'disk' => $diskName,
                'target' => $target,
            ]);

            $message = $component === 'database'
                ? 'Database restore completed successfully.'
                : 'Application files restored: '.(int) $result['restored_files'];

            $this->components->info($message);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
