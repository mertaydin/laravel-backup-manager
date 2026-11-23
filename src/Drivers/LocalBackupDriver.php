<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Drivers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use MertAydin\LaravelBackupManager\Contracts\BackupDriver;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\Data\BackupResult;
use MertAydin\LaravelBackupManager\Events\BackupCompleted;
use MertAydin\LaravelBackupManager\Events\BackupFailed;
use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use MertAydin\LaravelBackupManager\Files\ZipArchiver;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
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
        private readonly ArtifactEncryptor $encryptor,
        private readonly BackupInspector $inspector,
    ) {}

    public function run(array $options = []): BackupResult
    {
        $id = now()->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $profileName = (string) ($options['profile'] ?? $this->config->get('backup-manager.default', 'default'));
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);

        if (! is_array($profile)) {
            return BackupResult::failure($id, "Backup profile [{$profileName}] does not exist.");
        }

        $temporaryRoot = (string) $this->config->get('backup-manager.temporary_directory');
        $workdir = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$id;
        if (! $this->files->makeDirectory($workdir, 0700, true, true)) {
            return BackupResult::failure($id, 'Unable to create the temporary backup workspace.');
        }

        $storages = [];
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

            $encryptionMetadata = null;
            $encryptionConfig = $profile['encryption'] ?? [];
            if (($encryptionConfig['enabled'] ?? false) === true) {
                $encryptedArtifacts = [];
                $encryptionKey = (string) ($encryptionConfig['key'] ?? '');

                foreach ($artifacts as $name => $path) {
                    $encryptedName = $name.'.enc';
                    $encryptedPath = $path.'.enc';
                    $encryptionMetadata = $this->encryptor->encrypt($path, $encryptedPath, $encryptionKey);
                    if (! @unlink($path)) {
                        throw new RuntimeException("Unable to remove the temporary plaintext artifact [{$name}].");
                    }
                    $encryptedArtifacts[$encryptedName] = $encryptedPath;
                }

                $artifacts = $encryptedArtifacts;
                $encryptionMetadata['enabled'] = true;
                $encryptionMetadata['key_id'] = $encryptionConfig['key_id'] ?? null;
            }

            $manifest = [
                'id' => $id,
                'created_at' => now()->toIso8601String(),
                'profile' => $profileName,
                'label' => trim((string) ($options['label'] ?? '')) ?: null,
                'note' => trim((string) ($options['note'] ?? '')) ?: null,
                'tags' => array_values(array_unique(array_filter((array) ($options['tags'] ?? []), 'is_string'))),
                'type' => (string) ($options['type'] ?? 'manual'),
                'artifacts' => [],
            ];
            if ($encryptionMetadata !== null) {
                $manifest['encryption'] = $encryptionMetadata;
            }
            $checksumLines = [];
            foreach ($artifacts as $name => $path) {
                $checksum = hash_file('sha256', $path);
                $size = filesize($path);
                if ($checksum === false || $size === false) {
                    throw new RuntimeException("Unable to calculate integrity metadata for artifact [{$name}].");
                }
                $manifest['artifacts'][$name] = ['size' => $size, 'sha256' => $checksum];
                $checksumLines[] = $checksum.'  '.$name;
            }

            $checksumsPath = $workdir.'/checksums.sha256';
            file_put_contents($checksumsPath, implode(PHP_EOL, $checksumLines).PHP_EOL);
            $artifacts['checksums.sha256'] = $checksumsPath;

            $manifestPath = $workdir.'/manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $artifacts['manifest.json'] = $manifestPath;

            $disk = (string) ($options['disk'] ?? $profile['storage']['disk'] ?? 'local');
            $localDisk = (string) ($profile['storage']['local_disk'] ?? 'local');
            $diskNames = array_values(array_unique([$localDisk, $disk]));
            $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/').'/'.$id;

            foreach ($diskNames as $diskName) {
                $storages[$diskName] = new LaravelFilesystemStorage($this->filesystem, $diskName);
                foreach ($artifacts as $name => $path) {
                    $uploaded[$diskName][$name] = $storages[$diskName]->put($path, $prefix.'/'.$name);
                }
            }

            $verification = null;
            if ((bool) $this->config->get('backup-manager.integrity.verify_after_upload', true)) {
                $verification = $this->inspector->verify($id, $profileName, $disk);
                if (! $verification['valid']) {
                    throw new RuntimeException('Post-upload integrity verification failed: '.implode(' ', $verification['errors']));
                }
            }

            $result = BackupResult::success($id, $uploaded[$disk] ?? [], [
                'profile' => $profileName,
                'disk' => $disk,
                'storage_disks' => $diskNames,
                'checksums' => $manifest['artifacts'],
                'encryption' => $encryptionMetadata,
                'verification' => $verification,
            ]);
            event(new BackupCompleted($result));

            return $result;
        } catch (Throwable $exception) {
            foreach ($uploaded as $diskName => $paths) {
                foreach ($paths as $remotePath) {
                    try {
                        $storages[$diskName]->delete($remotePath);
                    } catch (Throwable) {
                        // Preserve the original failure; incomplete artifacts are never reported as successful.
                    }
                }
            }

            $result = BackupResult::failure($id, $exception->getMessage(), ['profile' => $profileName, 'uploaded_artifacts_cleaned' => $uploaded !== []]);
            event(new BackupFailed($result));

            return $result;
        } finally {
            $this->files->deleteDirectory($workdir);
        }
    }
}
