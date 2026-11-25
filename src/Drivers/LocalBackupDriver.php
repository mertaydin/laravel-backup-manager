<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Drivers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\Contracts\BackupDriver;
use MertAydin\LaravelBackupManager\Data\BackupResult;
use MertAydin\LaravelBackupManager\Database\DatabaseDumper;
use MertAydin\LaravelBackupManager\Events\BackupCompleted;
use MertAydin\LaravelBackupManager\Events\BackupFailed;
use MertAydin\LaravelBackupManager\Files\ZipArchiver;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use MertAydin\LaravelBackupManager\Storage\LaravelFilesystemStorage;
use RuntimeException;
use Throwable;

final class LocalBackupDriver implements BackupDriver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheFactory $cache,
        private readonly FilesystemFactory $filesystem,
        private readonly DatabaseDumper $dumper,
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
            $result = BackupResult::failure($id, "Backup profile [{$profileName}] does not exist.");
            event(new BackupFailed($result));

            return $result;
        }

        $temporaryRoot = (string) $this->config->get('backup-manager.temporary_directory');
        $workdir = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$id;
        if (! $this->files->makeDirectory($workdir, 0700, true, true)) {
            $result = BackupResult::failure($id, 'Unable to create the temporary backup workspace.');
            event(new BackupFailed($result));

            return $result;
        }

        $lock = $this->cache->store()->lock(
            'backup-manager:profile:'.$profileName,
            max(60, (int) $this->config->get('backup-manager.lock_seconds', 21600)),
        );
        if (! $lock->get()) {
            $this->files->deleteDirectory($workdir);
            $result = BackupResult::failure($id, "Another backup is already running for profile [{$profileName}].");
            event(new BackupFailed($result));

            return $result;
        }

        $storages = [];
        $uploaded = [];
        try {
            $artifacts = [];
            $databaseConfig = $profile['database'] ?? [];
            if (($options['database'] ?? null) === true || (! array_key_exists('database', $options) && ($databaseConfig['enabled'] ?? false))) {
                $connectionName = $databaseConfig['connection'] ?? null;
                $connectionConfig = $this->config->get('database.connections.'.($connectionName ?: $this->config->get('database.default')));
                $connectionDriver = (string) ($connectionConfig['driver'] ?? '');
                if ($connectionDriver === 'mysql') {
                    $connectionConfig['dump_binary'] = $databaseConfig['dump_binary'] ?? $this->config->get('backup-manager.mysql.dump_binary', 'mysqldump');
                } elseif ($connectionDriver === 'pgsql') {
                    $connectionConfig['dump_binary'] = $databaseConfig['postgres_dump_binary'] ?? $this->config->get('backup-manager.postgresql.dump_binary', 'pg_dump');
                }
                $databaseArtifactName = $connectionDriver === 'sqlite' ? 'database.sqlite.gz' : 'database.sql.gz';
                $path = $workdir.'/'.$databaseArtifactName;
                $this->dumper->dump($connectionConfig, $path, $databaseConfig['tables'] ?? [], $databaseConfig['exclude_tables'] ?? []);
                $artifacts[$databaseArtifactName] = $path;
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

            $createdAt = now();
            $nameTemplate = (string) $this->config->get('backup-manager.naming.template', ':app-:date-:time');
            $generatedLabel = str_replace(
                [':app', ':date', ':time', ':id'],
                [Str::slug((string) $this->config->get('app.name', 'laravel-app')), $createdAt->format('Ymd'), $createdAt->format('His'), $id],
                $nameTemplate,
            );
            $label = trim((string) ($options['label'] ?? '')) ?: Str::slug($generatedLabel);
            $manifest = [
                'id' => $id,
                'created_at' => $createdAt->toIso8601String(),
                'profile' => $profileName,
                'label' => $label,
                'note' => trim((string) ($options['note'] ?? '')) ?: null,
                'tags' => array_values(array_unique(array_filter((array) ($options['tags'] ?? []), 'is_string'))),
                'type' => (string) ($options['type'] ?? 'manual'),
                'protection' => [
                    'locked' => (bool) ($options['locked'] ?? false),
                    'protected_until' => $options['protected_until'] ?? null,
                    'reason' => isset($options['protection_reason']) ? trim((string) $options['protection_reason']) : null,
                ],
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
            $additionalDisks = array_values(array_filter((array) ($profile['storage']['disks'] ?? []), 'is_string'));
            $diskNames = array_values(array_unique(array_filter([$localDisk, $disk, ...$additionalDisks])));
            $storagePath = trim((string) ($profile['storage']['path'] ?? 'backups'), '/');
            // Keep installations with an older published config on the package's standard directory.
            if ($storagePath === '' || $storagePath === 'backups') {
                $storagePath = 'backup-manager/backups';
            }
            $prefix = $storagePath.'/'.$id;

            foreach ($diskNames as $diskName) {
                $storages[$diskName] = new LaravelFilesystemStorage($this->filesystem, $diskName);
                foreach ($artifacts as $name => $path) {
                    $uploaded[$diskName][$name] = $storages[$diskName]->put($path, $prefix.'/'.$name);
                }
            }

            $verification = null;
            if ((bool) $this->config->get('backup-manager.integrity.verify_after_upload', true)) {
                foreach ($diskNames as $targetDiskName) {
                    $verification = $this->inspector->verify($id, $profileName, $targetDiskName);
                    if (! $verification['valid']) {
                        throw new RuntimeException("Post-upload integrity verification failed on disk [{$targetDiskName}]: ".implode(' ', $verification['errors']));
                    }
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
            $lock->release();
        }
    }
}
