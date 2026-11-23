<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use MertAydin\LaravelBackupManager\Events\BackupDeleted;
use RuntimeException;

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
        $primaryDisk = (string) ($storage['disk'] ?? 'local');
        $manifestPath = trim((string) ($storage['path'] ?? 'backups'), '/').'/'.$id.'/manifest.json';
        $primary = $this->filesystem->disk($primaryDisk);
        if (! $primary->exists($manifestPath)) {
            throw new RuntimeException("Backup manifest [{$id}] was not found on disk [{$primaryDisk}].");
        }
        $manifest = json_decode((string) $primary->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $locked = (bool) data_get($manifest, 'protection.locked', false);
        $protectedUntil = data_get($manifest, 'protection.protected_until');
        if ($locked || ($protectedUntil && now()->lt(\\Illuminate\\Support\\Carbon::parse($protectedUntil)))) {
            $reason = trim((string) data_get($manifest, 'protection.reason', ''));
            throw new RuntimeException('This backup is protected from deletion.'.($reason !== '' ? ' Reason: '.$reason : ''));
        }

        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $path = ($prefix === '' ? '' : $prefix.'/').$id;
        $disks = array_values(array_unique([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ]));

        $deleted = [];
        $failed = [];
        foreach ($disks as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if (! $disk->deleteDirectory($path)) {
                    $failed[] = $diskName;

                    continue;
                }
                $deleted[] = $diskName;
            } catch (\Throwable) {
                $failed[] = $diskName;
            }
        }

        if ($failed === []) {
            event(new BackupDeleted($id, $deleted));
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
