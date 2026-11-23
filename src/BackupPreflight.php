<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Symfony\Component\Process\ExecutableFinder;

class BackupPreflight
{
    public function __construct(
        private readonly StorageHealthChecker $storageHealth,
        private readonly ExecutableFinder $executables,
        private readonly StorageSettingsRepository $settings,
    ) {}

    /** @param array<string, mixed> $options
     * @return array{ready: bool, checks: array<string, bool>, errors: list<string>}
     */
    public function inspect(array $options = []): array
    {
        $profileName = (string) ($options['profile'] ?? config('backup-manager.default', 'default'));
        $profile = config('backup-manager.profiles.'.$profileName, []);
        $diskName = (string) ($options['disk'] ?? data_get($profile, 'storage.disk', 'local'));
        $database = (bool) ($options['database'] ?? data_get($profile, 'database.enabled', false));
        $files = (bool) ($options['files'] ?? data_get($profile, 'files.enabled', false));
        $checks = [];
        $errors = [];

        $temporary = (string) config('backup-manager.temporary_directory');
        $writableParent = $temporary;
        while (! is_dir($writableParent) && dirname($writableParent) !== $writableParent) {
            $writableParent = dirname($writableParent);
        }
        $checks['temporary_directory'] = is_dir($temporary) ? is_writable($temporary) : is_dir($writableParent) && is_writable($writableParent);
        if (! $checks['temporary_directory']) {
            $errors[] = 'The backup temporary directory is not writable.';
        }

        $selectedTargets = [];
        try {
            $selectedTargets = (array) ($this->settings->all()['backup_targets'] ?? []);
        } catch (\Throwable) {
            // Use profile configuration until the settings migration is installed.
        }
        $storageDisks = array_values(array_unique($selectedTargets !== [] && ! isset($options['disk'])
            ? $selectedTargets
            : [$diskName]));
        foreach ($storageDisks as $storageDisk) {
            try {
                $storage = $this->storageHealth->check($storageDisk, (string) data_get($profile, 'storage.path', 'backups'));
                $checks['storage:'.$storageDisk] = $storage['successful'];
            } catch (\Throwable) {
                $checks['storage:'.$storageDisk] = false;
            }
            if (! $checks['storage:'.$storageDisk]) {
                $errors[] = "Storage disk [{$storageDisk}] failed its connection or permission checks.";
            }
        }

        if ($database) {
            $connectionName = (string) data_get($profile, 'database.connection', config('database.default'));
            $connection = config('database.connections.'.$connectionName, []);
            $driver = (string) ($connection['driver'] ?? '');
            $checks['database_connection'] = is_array($connection) && in_array($driver, ['mysql', 'pgsql', 'sqlite'], true);
            if ($checks['database_connection']) {
                try {
                    app('db')->connection($connectionName)->getPdo();
                } catch (\Throwable) {
                    $checks['database_connection'] = false;
                }
            }
            if (! $checks['database_connection']) {
                $errors[] = 'The selected database connection is unavailable or uses an unsupported driver. Supported drivers: MySQL, PostgreSQL and SQLite.';
            }

            if ($driver === 'mysql' || $driver === 'pgsql') {
                $binary = $driver === 'pgsql'
                    ? (string) data_get($profile, 'database.postgres_dump_binary', config('backup-manager.postgresql.dump_binary', 'pg_dump'))
                    : (string) data_get($profile, 'database.dump_binary', config('backup-manager.mysql.dump_binary', 'mysqldump'));
                $checks['database_dump_binary'] = $this->executables->find($binary) !== null;
                if (! $checks['database_dump_binary']) {
                    $errors[] = "The {$binary} executable for the selected database driver could not be found in the PHP process PATH.";
                }
            } elseif ($driver === 'sqlite') {
                $checks['pdo_sqlite'] = in_array('sqlite', \PDO::getAvailableDrivers(), true);
                if (! $checks['pdo_sqlite']) {
                    $errors[] = 'The PDO SQLite extension is required for SQLite backups.';
                }
            }
        }

        if ($files) {
            $checks['zip_extension'] = class_exists(\ZipArchive::class);
            if (! $checks['zip_extension']) {
                $errors[] = 'The PHP ZIP extension is required for application file backups.';
            }
        }

        if ((bool) data_get($profile, 'encryption.enabled', false)) {
            $checks['sodium_extension'] = extension_loaded('sodium');
            if (! $checks['sodium_extension']) {
                $errors[] = 'The PHP sodium extension is required for encrypted backups.';
            }
        }

        return ['ready' => $errors === [], 'checks' => $checks, 'errors' => $errors];
    }
}
