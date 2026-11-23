<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use RuntimeException;

final class BackupPruner
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
        private readonly BackupInspector $inspector,
    ) {}

    /**
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     deleted: list<string>,
     *     failed: list<string>,
     *     protected_count: int,
     *     invalid_count: int,
     *     cutoff: string
     * }
     */
    public function run(?string $profileName = null, ?string $diskName = null, bool $execute = false): array
    {
        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new RuntimeException("Backup profile [{$profileName}] does not exist.");
        }

        $retention = $profile['retention'] ?? [];
        if (($retention['enabled'] ?? false) !== true) {
            throw new RuntimeException("Retention is disabled for backup profile [{$profileName}].");
        }

        $keepDays = max(0, (int) ($retention['keep_days'] ?? 0));
        $keepLast = max(0, (int) ($retention['keep_last'] ?? 0));
        if ($keepDays === 0 && $keepLast === 0) {
            throw new RuntimeException('Retention must keep at least one day of backups or a positive number of recent backups.');
        }

        $diskName ??= (string) ($profile['storage']['disk'] ?? 'local');
        $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/');
        $backupRoot = $prefix === '' ? '' : $prefix.'/';
        $backups = $this->inspector->list($profileName, $diskName, PHP_INT_MAX);
        $cutoff = now()->subDays($keepDays);
        $protectedIds = [];

        foreach (array_slice($backups, 0, $keepLast) as $backup) {
            $protectedIds[(string) $backup['id']] = true;
        }

        $candidates = [];
        $protectedCount = 0;
        $invalidCount = 0;

        foreach ($backups as $backup) {
            $id = (string) ($backup['id'] ?? '');
            $manifestDirectory = basename(dirname((string) ($backup['manifest_path'] ?? '')));

            if (isset($backup['error'])
                || ! preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id)
                || $manifestDirectory !== $id
                || empty($backup['created_at'])) {
                $invalidCount++;

                continue;
            }

            try {
                $createdAt = Carbon::parse((string) $backup['created_at']);
            } catch (\Throwable) {
                $invalidCount++;

                continue;
            }

            $withinAge = $keepDays > 0 && $createdAt->greaterThanOrEqualTo($cutoff);
            $withinCount = isset($protectedIds[$id]);

            if ($withinAge || $withinCount) {
                $protectedCount++;

                continue;
            }

            $candidates[] = $backup;
        }

        $deleted = [];
        $failed = [];
        if ($execute) {
            $localDiskName = (string) ($profile['storage']['local_disk'] ?? 'local');
            $diskNames = array_values(array_unique([$diskName, $localDiskName]));
            foreach ($candidates as $backup) {
                $id = (string) $backup['id'];
                $allDeleted = true;

                foreach ($diskNames as $targetDiskName) {
                    try {
                        if (! $this->filesystem->disk($targetDiskName)->deleteDirectory($backupRoot.$id)) {
                            $allDeleted = false;
                        }
                    } catch (\Throwable) {
                        $allDeleted = false;
                    }
                }

                if ($allDeleted) {
                    $deleted[] = $id;
                } else {
                    $failed[] = $id;
                }
            }
        }

        return [
            'candidates' => $candidates,
            'deleted' => $deleted,
            'failed' => $failed,
            'protected_count' => $protectedCount,
            'invalid_count' => $invalidCount,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }
}
