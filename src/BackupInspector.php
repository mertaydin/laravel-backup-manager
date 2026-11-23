<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;
use Throwable;

final class BackupInspector
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $profileName = null, ?string $diskName = null, int $limit = 20): array
    {
        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->profile($profileName);
        $diskName ??= (string) ($profile['storage']['disk'] ?? 'local');
        $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/');

        $disk = $this->filesystem->disk($diskName);
        $backups = [];

        foreach ($disk->allFiles($prefix) as $path) {
            if (! str_ends_with($path, '/manifest.json')) {
                continue;
            }

            try {
                $manifest = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($manifest) || ! isset($manifest['id'], $manifest['created_at'], $manifest['artifacts'])) {
                    throw new RuntimeException('Manifest is missing required fields.');
                }

                $backups[] = [
                    'id' => (string) $manifest['id'],
                    'profile' => (string) ($manifest['profile'] ?? $profileName),
                    'created_at' => (string) $manifest['created_at'],
                    'encrypted' => (bool) (data_get($manifest, 'encryption.enabled', false)),
                    'artifacts' => collect($manifest['artifacts'])
                        ->filter(static fn (mixed $artifact): bool => is_array($artifact))
                        ->map(static fn (array $artifact, string $name): array => [
                            'name' => $name,
                            'size' => (int) ($artifact['size'] ?? 0),
                            'sha256' => (string) ($artifact['sha256'] ?? ''),
                        ])->values()->all(),
                    'manifest_path' => $path,
                    'disk' => $diskName,
                    'total_size' => array_sum(array_map(
                        static fn (array $artifact): int => (int) ($artifact['size'] ?? 0),
                        array_filter($manifest['artifacts'], 'is_array')
                    )),
                ];
            } catch (Throwable $exception) {
                $backups[] = [
                    'id' => basename(dirname($path)),
                    'profile' => $profileName,
                    'created_at' => null,
                    'artifacts' => [],
                    'manifest_path' => $path,
                    'disk' => $diskName,
                    'total_size' => 0,
                    'error' => 'Invalid manifest: '.$exception->getMessage(),
                ];
            }
        }

        usort($backups, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($backups, 0, max(1, $limit));
    }

    /**
     * @return array{valid: bool, id: string, disk: string, artifacts: array<string, array<string, mixed>>, errors: list<string>}
     */
    public function verify(string $id, ?string $profileName = null, ?string $diskName = null): array
    {
        if (! preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id)) {
            throw new RuntimeException('Invalid backup identifier.');
        }

        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->profile($profileName);
        $diskName ??= (string) ($profile['storage']['disk'] ?? 'local');
        $prefix = trim((string) ($profile['storage']['path'] ?? 'backups'), '/').'/'.$id;
        $disk = $this->filesystem->disk($diskName);
        $manifestPath = $prefix.'/manifest.json';

        if (! $disk->exists($manifestPath)) {
            throw new RuntimeException("Backup manifest [{$id}] was not found on disk [{$diskName}].");
        }

        $manifest = json_decode((string) $disk->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['id'] ?? null) !== $id || ! is_array($manifest['artifacts'] ?? null)) {
            throw new RuntimeException('Backup manifest is invalid or does not match the requested backup.');
        }

        $results = [];
        $errors = [];

        foreach ($manifest['artifacts'] as $name => $expected) {
            if (! is_string($name) || basename($name) !== $name || $name === '' || ! is_array($expected)) {
                $errors[] = 'Manifest contains an invalid artifact entry.';
                continue;
            }

            $path = $prefix.'/'.$name;
            if (! $disk->exists($path)) {
                $results[$name] = ['valid' => false, 'error' => 'Artifact is missing.'];
                $errors[] = "{$name}: artifact is missing.";
                continue;
            }

            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                $results[$name] = ['valid' => false, 'error' => 'Artifact could not be read.'];
                $errors[] = "{$name}: artifact could not be read.";
                continue;
            }

            $context = hash_init('sha256');
            try {
                $bytes = hash_update_stream($context, $stream);
                if ($bytes === false) {
                    throw new RuntimeException("Unable to hash artifact [{$name}].");
                }
            } finally {
                fclose($stream);
            }

            $actualChecksum = hash_final($context);
            $expectedChecksum = (string) ($expected['sha256'] ?? '');
            $checksumMatches = preg_match('/^[a-f0-9]{64}$/', $expectedChecksum) === 1
                && hash_equals($expectedChecksum, $actualChecksum);
            $sizeMatches = isset($expected['size']) && (int) $expected['size'] === $bytes;
            $valid = $checksumMatches && $sizeMatches;

            $results[$name] = [
                'valid' => $valid,
                'expected_sha256' => $expectedChecksum,
                'actual_sha256' => $actualChecksum,
                'expected_size' => $expected['size'] ?? null,
                'actual_size' => $bytes,
            ];

            if (! $valid) {
                $errors[] = "{$name}: ".(! $checksumMatches ? 'SHA-256 checksum mismatch.' : 'File size mismatch.');
            }
        }

        if ($results === []) {
            $errors[] = 'Manifest does not contain any verifiable artifacts.';
        }

        return [
            'valid' => $errors === [],
            'id' => $id,
            'disk' => $diskName,
            'artifacts' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $profileName): array
    {
        $profile = $this->config->get('backup-manager.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new RuntimeException("Backup profile [{$profileName}] does not exist.");
        }

        return $profile;
    }
}
