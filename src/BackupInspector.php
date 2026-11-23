<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class BackupInspector
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
        private readonly StorageSettingsRepository $settings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $profileName = null, ?string $diskName = null, int $limit = 20): array
    {
        $profileName ??= (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->profile($profileName);
        if ($diskName === null) {
            try {
                $targets = (array) ($this->settings->all()['backup_targets'] ?? []);
            } catch (Throwable) {
                $targets = [];
            }
            $diskName = (string) ($targets[0] ?? $profile['storage']['disk'] ?? 'local');
        }
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

                $protectedUntil = data_get($manifest, 'protection.protected_until');
                $isProtected = (bool) data_get($manifest, 'protection.locked', false);
                if ($protectedUntil !== null) {
                    try {
                        $isProtected = $isProtected || now()->lt(Carbon::parse($protectedUntil));
                    } catch (Throwable) {
                        $isProtected = true;
                    }
                }

                $backups[] = [
                    'id' => (string) $manifest['id'],
                    'profile' => (string) ($manifest['profile'] ?? $profileName),
                    'created_at' => (string) $manifest['created_at'],
                    'label' => (string) ($manifest['label'] ?? ''),
                    'note' => (string) ($manifest['note'] ?? ''),
                    'tags' => is_array($manifest['tags'] ?? null) ? $manifest['tags'] : [],
                    'type' => (string) ($manifest['type'] ?? 'manual'),
                    'locked' => (bool) data_get($manifest, 'protection.locked', false),
                    'protected' => $isProtected,
                    'protected_until' => $protectedUntil,
                    'protection_reason' => data_get($manifest, 'protection.reason'),
                    'status' => 'available',
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
                    'status' => 'invalid',
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
        if ($diskName === null) {
            try {
                $targets = (array) ($this->settings->all()['backup_targets'] ?? []);
            } catch (Throwable) {
                $targets = [];
            }
            $diskName = (string) ($targets[0] ?? $profile['storage']['disk'] ?? 'local');
        }
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
            $formatError = null;
            if ($checksumMatches && $sizeMatches && ! str_ends_with($name, '.enc')) {
                $formatError = $this->validateArtifactFormat($disk, $path, $name);
            }
            $valid = $checksumMatches && $sizeMatches && $formatError === null;

            $results[$name] = [
                'valid' => $valid,
                'expected_sha256' => $expectedChecksum,
                'actual_sha256' => $actualChecksum,
                'expected_size' => $expected['size'] ?? null,
                'actual_size' => $bytes,
                'format_error' => $formatError,
            ];

            if (! $valid) {
                $errors[] = "{$name}: ".(! $checksumMatches ? 'SHA-256 checksum mismatch.' : (! $sizeMatches ? 'File size mismatch.' : (string) $formatError));
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

    private function validateArtifactFormat(object $disk, string $path, string $name): ?string
    {
        if (! in_array($name, ['database.sql.gz', 'database.sqlite.gz', 'application.zip'], true)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'backup-verify-');
        if ($temporary === false) {
            return 'Unable to create a temporary file for archive validation.';
        }

        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            @unlink($temporary);

            return 'Artifact could not be reopened for archive validation.';
        }

        $target = fopen($temporary, 'wb');
        if ($target === false) {
            fclose($stream);
            @unlink($temporary);

            return 'Unable to write the temporary archive validation file.';
        }

        try {
            if (stream_copy_to_stream($stream, $target) === false) {
                return 'Unable to copy the artifact for archive validation.';
            }
        } finally {
            fclose($stream);
            fclose($target);
        }

        try {
            if (in_array($name, ['database.sql.gz', 'database.sqlite.gz'], true)) {
                $input = fopen($temporary, 'rb');
                $inflater = @inflate_init(ZLIB_ENCODING_GZIP);
                if ($input === false || $inflater === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }

                    return 'Database artifact is not a valid GZIP stream.';
                }

                try {
                    $chunk = fread($input, 16 * 1024);
                    while ($chunk !== false && $chunk !== '') {
                        $next = fread($input, 16 * 1024);
                        if ($next === false) {
                            return 'Unable to read the database GZIP stream.';
                        }

                        $flush = $next === '' ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
                        if (@inflate_add($inflater, $chunk, $flush) === false) {
                            return 'Database GZIP stream is corrupted.';
                        }

                        $chunk = $next;
                    }

                    if ($chunk === false
                        || inflate_get_status($inflater) !== ZLIB_STREAM_END
                        || inflate_get_read_len($inflater) !== (int) filesize($temporary)) {
                        return 'Database GZIP stream failed its integrity check.';
                    }
                } finally {
                    fclose($input);
                }
            } else {
                if (! class_exists(\ZipArchive::class)) {
                    return 'The PHP ZIP extension is required to validate the application archive.';
                }

                $archive = new \ZipArchive;
                if ($archive->open($temporary, \ZipArchive::CHECKCONS) !== true) {
                    return 'Application ZIP archive is invalid or corrupted.';
                }

                try {
                    for ($index = 0; $index < $archive->numFiles; $index++) {
                        $entry = $archive->getStreamIndex($index);
                        if (! is_resource($entry)) {
                            return 'Application ZIP archive contains an unreadable entry.';
                        }

                        try {
                            while (! feof($entry)) {
                                if (fread($entry, 1024 * 1024) === false) {
                                    return 'Application ZIP archive contains a corrupted entry.';
                                }
                            }
                        } finally {
                            fclose($entry);
                        }
                    }
                } finally {
                    $archive->close();
                }
            }
        } finally {
            @unlink($temporary);
        }

        return null;
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
