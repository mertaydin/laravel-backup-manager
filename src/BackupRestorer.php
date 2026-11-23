<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use MertAydin\LaravelBackupManager\Database\DatabaseRestorer;
use MertAydin\LaravelBackupManager\Events\BackupRestored;
use MertAydin\LaravelBackupManager\Files\ZipRestorer;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use RuntimeException;

final class BackupRestorer
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
        private readonly Filesystem $files,
        private readonly BackupInspector $inspector,
        private readonly ArtifactEncryptor $encryptor,
        private readonly DatabaseRestorer $databaseRestorer,
        private readonly ZipRestorer $zip,
        private readonly StorageSettingsRepository $settings,
    ) {}

    /**
     * @param  array{component: string, profile?: string, disk?: string, target?: string}  $options
     * @return array{id: string, component: string, target: string|null, restored_files: int|null}
     */
    public function restore(string $id, array $options): array
    {
        $component = (string) ($options['component'] ?? '');
        if (! in_array($component, ['database', 'files'], true)) {
            throw new RuntimeException('Select exactly one restore component: database or files.');
        }

        $profileName = (string) ($options['profile'] ?? $this->config->get('backup-manager.default', 'default'));
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new RuntimeException("Backup profile [{$profileName}] does not exist.");
        }

        $targets = [];
        try {
            $targets = (array) ($this->settings->all()['backup_targets'] ?? []);
        } catch (\Throwable) {
            // Fall back to the profile disk before settings are installed.
        }
        $diskName = (string) ($options['disk'] ?? $targets[0] ?? $profile['storage']['disk'] ?? 'local');
        $verification = $this->inspector->verify($id, $profileName, $diskName);
        if (! $verification['valid']) {
            throw new RuntimeException('Backup integrity verification failed: '.implode(' ', $verification['errors']));
        }

        $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/');
        $backupPrefix = ($prefix === '' ? '' : $prefix.'/').$id;
        $disk = $this->filesystem->disk($diskName);
        $manifest = json_decode((string) $disk->get($backupPrefix.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        $encrypted = (bool) data_get($manifest, 'encryption.enabled', false);
        $encryptionKey = '';
        if ($encrypted) {
            if (data_get($manifest, 'encryption.algorithm') !== ArtifactEncryptor::ALGORITHM
                || (int) data_get($manifest, 'encryption.format_version', 0) !== 1) {
                throw new RuntimeException('The encrypted backup format is not supported by this package version.');
            }

            $encryptionKey = (string) data_get($profile, 'encryption.key', '');
            if ($encryptionKey === '') {
                throw new RuntimeException('The encryption key is not configured for the selected backup profile.');
            }
        }

        $connectionName = null;
        $connection = null;
        if ($component === 'database') {
            $database = $profile['database'] ?? [];
            $connectionName = $database['connection'] ?? $this->config->get('database.default');
            $connection = $this->config->get('database.connections.'.$connectionName);
            if (! is_array($connection) || ! in_array(($connection['driver'] ?? null), ['mysql', 'pgsql', 'sqlite'], true)) {
                throw new RuntimeException('The selected database profile does not use a supported database connection.');
            }
        }

        $artifactName = $component === 'database'
            ? ((($connection['driver'] ?? null) === 'sqlite') ? 'database.sqlite.gz' : 'database.sql.gz')
            : 'application.zip';
        $storedArtifact = $artifactName.($encrypted ? '.enc' : '');
        if (! isset($manifest['artifacts'][$storedArtifact])) {
            throw new RuntimeException("The selected backup does not contain the [{$component}] artifact.");
        }

        $target = null;
        if ($component === 'files') {
            $target = trim((string) ($options['target'] ?? ''));
            if ($target === '') {
                throw new RuntimeException('A target directory is required when restoring application files.');
            }
        }

        $temporaryRoot = (string) $this->config->get('backup-manager.temporary_directory');
        $workdir = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'restore_'.$id.'_'.bin2hex(random_bytes(4));
        if (! $this->files->makeDirectory($workdir, 0700, true, true)) {
            throw new RuntimeException('Unable to create the temporary restore workspace.');
        }

        try {
            $downloadedPath = $workdir.'/'.$storedArtifact;
            $this->download($disk, $backupPrefix.'/'.$storedArtifact, $downloadedPath);

            $restorePath = $downloadedPath;
            if ($encrypted) {
                $restorePath = $workdir.'/'.$artifactName;
                $this->encryptor->decrypt($downloadedPath, $restorePath, $encryptionKey);
            }

            $restoredFiles = null;
            if ($component === 'database') {
                $database = $profile['database'] ?? [];
                if (($connection['driver'] ?? null) === 'pgsql') {
                    $connection['restore_binary'] = $database['postgres_restore_binary'] ?? $database['restore_binary'] ?? $this->config->get('backup-manager.postgresql.restore_binary', 'psql');
                }
                $binary = (string) ($database['restore_binary'] ?? $this->config->get('backup-manager.mysql.restore_binary', 'mysql'));
                $this->databaseRestorer->restore((string) $connectionName, $connection, $restorePath, $binary);
            } else {
                $restoredFiles = $this->zip->restore($restorePath, $target, true);
            }

            event(new BackupRestored($id, $component));

            return [
                'id' => $id,
                'component' => $component,
                'target' => $target,
                'restored_files' => $restoredFiles,
            ];
        } finally {
            $this->files->deleteDirectory($workdir);
        }
    }

    private function download(object $disk, string $sourcePath, string $destinationPath): void
    {
        $stream = $disk->readStream($sourcePath);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read the selected backup artifact from storage.');
        }

        $target = fopen($destinationPath, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create the temporary restore artifact.');
        }

        try {
            if (stream_copy_to_stream($stream, $target) === false || ! fflush($target)) {
                throw new RuntimeException('Unable to download the selected backup artifact completely.');
            }
        } finally {
            fclose($stream);
            fclose($target);
        }
    }
}
