<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

final class BackupDeleter
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
    ) {
    }

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
        $disks = array_values(array_unique([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
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

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
