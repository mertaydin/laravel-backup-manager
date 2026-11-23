<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class BackupProtector
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function update(string $id, ?string $profileName = null, bool $locked = true, ?string $protectedUntil = null, ?string $reason = null): void
    {
        if (! preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id)) {
            throw new RuntimeException('Invalid backup identifier.');
        }

        if ($protectedUntil !== null) {
            try {
                $expiry = Carbon::parse($protectedUntil);
            } catch (Throwable) {
                throw new RuntimeException('The protection expiry must be a valid date/time.');
            }
            if ($expiry->isPast()) {
                throw new RuntimeException('The protection expiry must be in the future.');
            }
        }

        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new RuntimeException("Backup profile [{$profileName}] does not exist.");
        }

        $storage = $profile['storage'] ?? [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $relative = ($prefix === '' ? '' : $prefix.'/').$id.'/manifest.json';
        $disks = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));

        $originals = [];
        foreach ($disks as $diskName) {
            $disk = $this->filesystem->disk($diskName);
            if ($disk->exists($relative)) {
                $originals[$diskName] = (string) $disk->get($relative);
            }
        }
        if ($originals === []) {
            throw new RuntimeException("Backup manifest [{$id}] was not found on any configured storage destination.");
        }

        $manifest = json_decode(reset($originals), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['id'] ?? null) !== $id) {
            throw new RuntimeException('Backup manifest is invalid or does not match the requested backup.');
        }

        $manifest['protection'] = [
            'locked' => $locked && $protectedUntil === null,
            'protected_until' => $protectedUntil,
            'reason' => $reason !== null ? trim($reason) : null,
            'updated_at' => now()->toIso8601String(),
        ];
        $contents = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $updated = [];

        try {
            foreach ($originals as $diskName => $original) {
                if (! $this->filesystem->disk($diskName)->put($relative, $contents)) {
                    throw new RuntimeException("Unable to update the backup manifest on disk [{$diskName}].");
                }
                $updated[] = $diskName;
            }
        } catch (Throwable $exception) {
            foreach ($updated as $diskName) {
                try {
                    $this->filesystem->disk($diskName)->put($relative, $originals[$diskName]);
                } catch (Throwable) {
                    report($exception);
                }
            }
            throw $exception;
        }
    }
}
