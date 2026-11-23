<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager\\Storage;

use Illuminate\\Contracts\\Filesystem\\Factory as FilesystemFactory;
use RuntimeException;
use Throwable;

final class StorageHealthChecker
{
    public function __construct(private readonly FilesystemFactory $filesystem) {}

    /** @return array{disk: string, driver: string, checks: array<string, bool>, successful: bool} */
    public function check(string $diskName): array
    {
        $diskConfig = config('filesystems.disks.'.$diskName);
        if (! is_array($diskConfig)) {
            throw new RuntimeException("Filesystem disk [{$diskName}] is not configured.");
        }

        $disk = $this->filesystem->disk($diskName);
        $probe = '.backup-manager-health/'.bin2hex(random_bytes(16)).'.tmp';
        $checks = ['connection' => false, 'list' => false, 'write' => false, 'read' => false, 'delete' => false];

        try {
            $disk->directories('');
            $checks['connection'] = true;
            $disk->files('');
            $checks['list'] = true;

            $written = $disk->put($probe, 'backup-manager-health-check');
            $checks['write'] = $written !== false;
            if ($checks['write']) {
                $checks['read'] = $disk->get($probe) === 'backup-manager-health-check';
                $checks['delete'] = $disk->delete($probe);
            }
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            try {
                if ($disk->exists($probe)) {
                    $disk->delete($probe);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'disk' => $diskName,
            'driver' => (string) ($diskConfig['driver'] ?? 'unknown'),
            'checks' => $checks,
            'successful' => ! in_array(false, $checks, true),
        ];
    }
}
