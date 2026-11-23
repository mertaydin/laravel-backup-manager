<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class BackupReporter
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly BackupInspector $inspector,
    ) {}

    /** @return array<string, mixed> */
    public function generate(?string $profileName = null, ?string $diskName = null): array
    {
        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $diskName ??= (string) data_get($profile, 'storage.disk', 'local');
        $backups = $this->inspector->list($profileName, $diskName, PHP_INT_MAX);
        $valid = array_values(array_filter($backups, static fn (array $backup): bool => ($backup['status'] ?? null) === 'available'));
        $invalid = count($backups) - count($valid);
        $totalBytes = array_sum(array_map(static fn (array $backup): int => (int) ($backup['total_size'] ?? 0), $valid));
        $latest = $valid[0] ?? null;

        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profileName,
            'disk' => $diskName,
            'total_backups' => count($backups),
            'valid_backups' => count($valid),
            'invalid_backups' => $invalid,
            'total_bytes' => $totalBytes,
            'latest_backup' => $latest ? [
                'id' => $latest['id'],
                'created_at' => $latest['created_at'],
                'total_size' => $latest['total_size'],
            ] : null,
            'backups' => $backups,
        ];
    }
}
