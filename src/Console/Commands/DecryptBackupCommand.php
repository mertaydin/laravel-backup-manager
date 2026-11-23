<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use RuntimeException;
use Throwable;

final class DecryptBackupCommand extends Command
{
    protected $signature = 'backup:decrypt
        {id : Backup identifier}
        {artifact : Encrypted artifact filename, for example database.sql.gz.enc}
        {--profile= : Backup profile name}
        {--disk= : Override the configured storage disk}
        {--output= : Destination path for the decrypted artifact}
        {--force : Replace an existing destination file}';

    protected $description = 'Download and decrypt a verified backup artifact';

    public function handle(
        BackupInspector $inspector,
        ArtifactEncryptor $encryptor,
        ConfigRepository $config,
        FilesystemFactory $filesystem,
        Filesystem $files,
    ): int {
        $id = (string) $this->argument('id');
        $artifact = (string) $this->argument('artifact');

        if (basename($artifact) !== $artifact || ! str_ends_with($artifact, '.enc')) {
            $this->components->error('Artifact must be a filename ending in .enc.');

            return self::FAILURE;
        }

        $profileName = (string) ($this->option('profile') ?: $config->get('backup-manager.default', 'default'));
        $profile = $config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            $this->components->error("Backup profile [{$profileName}] does not exist.");

            return self::FAILURE;
        }

        $diskName = (string) ($this->option('disk') ?: ($profile['storage']['disk'] ?? 'local'));
        $disk = $filesystem->disk($diskName);
        $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/').'/'.$id;
        $manifestPath = $prefix.'/manifest.json';

        $destinationMayBePartial = false;

        try {
            $verification = $inspector->verify($id, $profileName, $diskName);
            if (! $verification['valid']) {
                throw new RuntimeException('Backup integrity verification failed: '.implode(' ', $verification['errors']));
            }

            $manifest = json_decode((string) $disk->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest['encryption'] ?? null) || ($manifest['encryption']['enabled'] ?? false) !== true) {
                throw new RuntimeException('The selected backup is not marked as encrypted.');
            }

            if (($manifest['encryption']['algorithm'] ?? null) !== ArtifactEncryptor::ALGORITHM
                || (int) ($manifest['encryption']['format_version'] ?? 0) !== 1) {
                throw new RuntimeException('The encrypted artifact format is not supported by this package version.');
            }

            if (! isset($manifest['artifacts'][$artifact])) {
                throw new RuntimeException("Artifact [{$artifact}] is not part of this backup.");
            }

            $encryptionKey = (string) data_get($profile, 'encryption.key', '');
            if ($encryptionKey === '') {
                throw new RuntimeException('The encryption key is not configured for this backup profile.');
            }

            $defaultDestination = storage_path(
                'app/backup-manager/decrypted/'.$id.'/'.substr($artifact, 0, -4)
            );
            $destination = (string) ($this->option('output') ?: $defaultDestination);
            if (is_file($destination) && ! $this->option('force')) {
                throw new RuntimeException("Destination [{$destination}] already exists. Use --force to replace it.");
            }

            $destinationDirectory = dirname($destination);
            if (! $files->isDirectory($destinationDirectory)
                && ! $files->makeDirectory($destinationDirectory, 0700, true, true)) {
                throw new RuntimeException('Unable to create the decrypted artifact destination directory.');
            }

            $temporaryRoot = (string) $config->get('backup-manager.temporary_directory');
            $workdir = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'decrypt_'.$id.'_'.bin2hex(random_bytes(4));
            if (! $files->makeDirectory($workdir, 0700, true, true)) {
                throw new RuntimeException('Unable to create the temporary decryption workspace.');
            }

            try {
                $source = $disk->readStream($prefix.'/'.$artifact);
                if (! is_resource($source)) {
                    throw new RuntimeException('Unable to read the encrypted artifact from backup storage.');
                }

                $temporaryArtifact = $workdir.'/'.$artifact;
                $target = fopen($temporaryArtifact, 'wb');
                if ($target === false) {
                    fclose($source);
                    throw new RuntimeException('Unable to create the temporary encrypted artifact.');
                }

                try {
                    if (stream_copy_to_stream($source, $target) === false) {
                        throw new RuntimeException('Unable to download the encrypted artifact completely.');
                    }
                } finally {
                    fclose($source);
                    fclose($target);
                }

                $destinationMayBePartial = true;
                $encryptor->decrypt($temporaryArtifact, $destination, $encryptionKey);
            } finally {
                $files->deleteDirectory($workdir);
            }

            $this->components->info('Artifact decrypted successfully: '.$destination);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($destinationMayBePartial && isset($destination) && is_file($destination)) {
                @unlink($destination);
            }

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
