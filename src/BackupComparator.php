<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

final class BackupComparator
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
        private readonly BackupInspector $inspector,
    ) {}

    /** @return array<string, mixed> */
    public function compare(string $leftId, string $rightId, ?string $profileName = null, ?string $diskName = null): array
    {
        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new RuntimeException("Backup profile [{$profileName}] does not exist.");
        }

        $diskName ??= (string) data_get($profile, 'storage.disk', 'local');
        foreach ([$leftId, $rightId] as $id) {
            if (! preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id)) {
                throw new RuntimeException('Invalid backup identifier.');
            }
            $verification = $this->inspector->verify($id, $profileName, $diskName);
            if (! $verification['valid']) {
                throw new RuntimeException("Backup [{$id}] failed integrity verification: ".implode(' ', $verification['errors']));
            }
        }

        $prefix = trim((string) data_get($profile, 'storage.path', 'backups'), '/');
        $disk = $this->filesystem->disk($diskName);
        $load = static function (string $id) use ($disk, $prefix): array {
            $path = ($prefix === '' ? '' : $prefix.'/').$id.'/manifest.json';
            $manifest = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! is_array($manifest['artifacts'] ?? null)) {
                throw new RuntimeException("Backup manifest [{$id}] is invalid.");
            }

            return $manifest;
        };

        $left = $load($leftId);
        $right = $load($rightId);
        $names = array_values(array_unique(array_merge(array_keys($left['artifacts']), array_keys($right['artifacts']))));
        $changes = [];
        foreach ($names as $name) {
            $a = $left['artifacts'][$name] ?? null;
            $b = $right['artifacts'][$name] ?? null;
            $status = $a === null ? 'added' : ($b === null ? 'removed' : (
                ($a['sha256'] ?? null) === ($b['sha256'] ?? null) ? 'unchanged' : 'changed'
            ));
            $changes[] = [
                'artifact' => $name,
                'status' => $status,
                'left_size' => $a['size'] ?? null,
                'right_size' => $b['size'] ?? null,
                'size_delta' => $a !== null && $b !== null ? (int) ($b['size'] ?? 0) - (int) ($a['size'] ?? 0) : null,
            ];
        }

        return [
            'left' => ['id' => $leftId, 'created_at' => $left['created_at'] ?? null],
            'right' => ['id' => $rightId, 'created_at' => $right['created_at'] ?? null],
            'disk' => $diskName,
            'artifacts' => $changes,
            'changed_count' => count(array_filter($changes, static fn (array $item): bool => $item['status'] !== 'unchanged')),
        ];
    }
}
