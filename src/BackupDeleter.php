<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use MertAydin\LaravelBackupManager\Events\BackupDeleted;
use RuntimeException;
use Throwable;

final class BackupDeleter
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
    ) {}

    /** @return array{deleted: list<string>, failed: list<string>} */
    public function delete(string $id, ?string $profileName = null): array
    {
        if (! preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id)) {
            throw new RuntimeException('Invalid backup identifier.');
        }

        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new RuntimeException("Backup profile [{$profileName}] does not exist.");
        }

        $storage = $profile['storage'] ?? [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $path = ($prefix === '' ? '' : $prefix.'/').$id;
        $manifestPath = $path.'/manifest.json';
        $disks = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));

        // A backup can exist on a secondary target even if its primary copy was
        // removed manually. Inspect every target before deciding whether it exists.
        $foundManifest = false;
        foreach ($disks as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if (! $disk->exists($manifestPath)) {
                    continue;
                }

                $manifest = json_decode((string) $disk->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($manifest) || ($manifest['id'] ?? null) !== $id) {
                    continue;
                }

                $foundManifest = true;
                $locked = (bool) data_get($manifest, 'protection.locked', false);
                $protectedUntil = data_get($manifest, 'protection.protected_until');
                if ($locked || ($protectedUntil && now()->lt(Carbon::parse($protectedUntil)))) {
                    $reason = trim((string) data_get($manifest, 'protection.reason', ''));
                    throw new RuntimeException('This backup is protected from deletion.'.($reason !== '' ? ' Reason: '.$reason : ''));
                }
            } catch (RuntimeException $exception) {
                if (str_starts_with($exception->getMessage(), 'This backup is protected from deletion.')) {
                    throw $exception;
                }
            } catch (Throwable) {
                // The deletion pass reports targets that cannot be accessed.
            }
        }

        if (! $foundManifest) {
            throw new RuntimeException("Backup manifest [{$id}] was not found on any configured storage target.");
        }

        $deleted = [];
        $failed = [];
        foreach ($disks as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                $files = $disk->allFiles($path);
                if ($files === []) {
                    continue;
                }

                // Explicit object-key deletion is reliable for S3-compatible stores
                // such as Cloudflare R2, where directories are virtual prefixes.
                foreach (array_chunk($files, 1000) as $fileChunk) {
                    if ($disk->delete($fileChunk) === false) {
                        throw new RuntimeException("Unable to delete backup objects from disk [{$diskName}].");
                    }
                }

                if ($disk->allFiles($path) !== []) {
                    throw new RuntimeException("Backup objects still exist on disk [{$diskName}] after deletion.");
                }

                $deleted[] = $diskName;
            } catch (Throwable) {
                $failed[] = $diskName;
            }
        }

        if ($failed === []) {
            event(new BackupDeleted($id, $deleted));
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
