<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Drivers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use MertAydin\LaravelBackupManager\Contracts\BackupDriver;
use MertAydin\LaravelBackupManager\Data\BackupResult;
use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use MertAydin\LaravelBackupManager\Files\ZipArchiver;
use MertAydin\LaravelBackupManager\Storage\LaravelFilesystemStorage;
use RuntimeException;
use Throwable;

final class LocalBackupDriver implements BackupDriver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
        private readonly MySqlDumper $dumper,
        private readonly ZipArchiver $archiver,
        private readonly Filesystem $files,
    ) {
    }

    public function run(array $options = []): BackupResult
    {
        $id = now()->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $profileName = (string) ($options['profile'] ?? $this->config->get('backup-manager.default', 'default'));
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);

        if (! is_array($profile)) {
            return BackupResult::failure($id, "Backup profile [{$profileName}] does not exist.");
        }

        if (($profile['encryption']['enabled'] ?? false) === true) {
            return BackupResult::failure($id, 'Backup encryption is enabled but no encryption engine is available yet. Disable encryption rather than creating an unencrypted backup.');
        }

        $temporaryRoot = (string) $this->config->get('backup-manager.temporary_directory');
        $workdir = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$id;
        if (! $this->files->makeDirectory($workdir, 0700, true, true)) {
            return BackupResult::failure($id, 'Unable to create the temporary backup workspace.');
        }

        $storage = null;
        $uploaded = [];
        try {
            $artifacts = [];
            $databaseConfig = $profile['database'] ?? [];
            if (($options['database'] ?? null) === true || (! array_key_exists('database', $options) && ($databaseConfig['enabled'] ?? false))) {
                $connectionName = $databaseConfig['connection'] ?? null;
                $connectionConfig = $this->config->get('database.connections.'.($connectionName ?: $this->config->get('database.default')));
                if (($connectionConfig['driver'] ?? null) !== 'mysql') {
                    throw new RuntimeException('The initial database exporter currently supports MySQL connections only.');
                }

                $connectionConfig['dump_binary'] = $databaseConfig['dump_binary'] ?? $this->config->get('backup-manager.mysql.dump_binary', 'mysqldump');
                $path = $workdir.'/database.sql.gz';
                $this->dumper->dump($connectionConfig, $path, $databaseConfig['tables'] ?? [], $databaseConfig['exclude_tables'] ?? []);
                $artifacts['database.sql.gz'] = $path;
            }

            $fileConfig = $profile['files'] ?? [];
            if (($options['files'] ?? null) === true || (! array_key_exists('files', $options) && ($fileConfig['enabled'] ?? false))) {
                $path = $workdir.'/application.zip';
                $this->archiver->archive(
                    base_path(),
                    $fileConfig['paths'] ?? [],
                    $fileConfig['exclude'] ?? [],
                    $path,
                    (bool) ($fileConfig['include_dotfiles'] ?? false),
                    (bool) ($fileConfig['exclude_env'] ?? true)
                );
                $artifacts['application.zip'] = $path;
            }

            if ($artifacts === []) {
                throw new RuntimeException('No backup components are enabled. Enable database and/or files in the selected profile.');
            }

            $manifest = ['id' => $id, 'created_at' => now()->toIso8601String(), 'profile' => $profileName, 'artifacts' => []];
            $checksumLines = [];
            foreach ($artifacts as $name => $path) {
                $checksum = hash_file('sha256', $path);
                $manifest['artifacts'][$name] = ['size' => filesize($path), 'sha256' => $checksum];
                $checksumLines[] = $checksum.'  '.$name;
            }

            $checksumsPath = $workdir.'/checksums.sha256';
            file_put_contents($checksumsPath, implode(PHP_EOL, $checksumLines).PHP_EOL);
            $artifacts['checksums.sha256'] = $checksumsPath;

            $manifestPath = $workdir.'/manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $artifacts['manifest.json'] = $manifestPath;

            $disk = (string) ($options['disk'] ?? $profile['storage']['disk'] ?? 'local');
            $storage = new LaravelFilesystemStorage($this->filesystem, $disk);
            $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/').'/'.$id;
            foreach ($artifacts as $name => $path) {
                $uploaded[$name] = $storage->put($path, $prefix.'/'.$name);
            }

            return BackupResult::success($id, $uploaded, ['profile' => $profileName, 'disk' => $disk, 'checksums' => $manifest['artifacts']]);
        } catch (Throwable $exception) {
            if ($storage !== null) {
                foreach ($uploaded as $remotePath) {
                    try {
                        $storage->delete($remotePath);
                    } catch (Throwable) {
                        // Preserve the original failure; incomplete artifacts are never reported as successful.
                    }
                }
            }

            return BackupResult::failure($id, $exception->getMessage(), ['profile' => $profileName, 'uploaded_artifacts_cleaned' => $uploaded !== []]);
        } finally {
            $this->files->deleteDirectory($workdir);
        }
    }
}
