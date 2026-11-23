<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;
use Symfony\Component\Process\ExecutableFinder;

class BackupPreflight
{
    public function __construct(
        private readonly BackupInspector $inspector,
        private readonly StorageHealthChecker $storageHealth,
        private readonly ExecutableFinder $executables,
    ) {}

    /** @param array<string, mixed> $options
     *  @return array{ready: bool, checks: array<string, bool>, errors: list<string>}
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

        try {
            $storage = $this->storageHealth->check($diskName, (string) data_get($profile, 'storage.path', 'backups'));
            $checks['storage'] = $storage['successful'];
        } catch (\Throwable) {
            $checks['storage'] = false;
        }
        if (! $checks['storage']) {
            $errors[] = "Storage disk [{$diskName}] failed its connection or permission checks.";
        }

        if ($database) {
            $connectionName = (string) data_get($profile, 'database.connection', config('database.default'));
            $connection = config('database.connections.'.$connectionName, []);
            $checks['mysql_connection'] = is_array($connection) && ($connection['driver'] ?? null) === 'mysql';
            if (! $checks['mysql_connection']) {
                $errors[] = 'The selected database connection is not a supported MySQL connection.';
            }

            $binary = (string) data_get($profile, 'database.dump_binary', config('backup-manager.mysql.dump_binary', 'mysqldump'));
            $checks['mysqldump'] = $this->executables->find($binary) !== null;
            if (! $checks['mysqldump']) {
                $errors[] = 'The mysqldump executable could not be found in the PHP process PATH.';
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
