<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use MertAydin\LaravelBackupManager\BackupDeleter;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\BackupPreflight;
use MertAydin\LaravelBackupManager\BackupProtector;
use MertAydin\LaravelBackupManager\Http\Middleware\SetDashboardLocale;
use MertAydin\LaravelBackupManager\Jobs\CreateBackupJob;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use RuntimeException;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly BackupInspector $inspector,
        private readonly BackupPreflight $preflight,
        private readonly BackupDeleter $deleter,
        private readonly BackupProtector $protector,
        private readonly FilesystemFactory $filesystem,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly StorageSettingsRepository $settings,
    ) {}

    public function index(): View
    {
        $profileName = (string) config('backup-manager.default', 'default');
        $profile = config('backup-manager.profiles.'.$profileName, []);
        $diskName = (string) data_get($profile, 'storage.disk', 'local');
        $availableBackupDisks = array_values(array_unique(array_filter([
            $diskName,
            (string) data_get($profile, 'storage.local_disk', 'local'),
            ...array_values(array_filter((array) data_get($profile, 'storage.disks', []), 'is_string')),
        ])));
        $allBackups = collect();
        foreach ($availableBackupDisks as $backupDisk) {
            try {
                $allBackups = $allBackups->concat($this->inspector->list($profileName, $backupDisk, 500));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $allBackups = $allBackups
            ->unique('id')
            ->values()
            ->map(static function (array $backup): array {
                $backup['size'] = (int) ($backup['total_size'] ?? 0);

                return $backup;
            });
        $schedule = [];
        try {
            $schedule = $this->settings->all()['schedule'] ?? [];
        } catch (Throwable $exception) {
            report($exception);
        }
        $nextScheduledBackup = null;
        if (($schedule['enabled'] ?? false) && ! empty($schedule['time'])) {
            try {
                $timezone = (string) ($schedule['timezone'] ?? config('app.timezone', 'UTC'));
                $nextScheduledBackup = now($timezone)->setTimeFromTimeString((string) $schedule['time']);
                if ($nextScheduledBackup->lessThanOrEqualTo(now($timezone))) {
                    $nextScheduledBackup->addDay();
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $filters = request()->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'in:manual,scheduled'],
            'status' => ['nullable', 'in:available,invalid'],
            'disk' => ['nullable', 'string', 'in:'.implode(',', $availableBackupDisks)],
        ]);
        $backups = $allBackups
            ->when($filters['q'] ?? null, static fn ($items, string $query) => $items->filter(
                static fn (array $backup): bool => str_contains(strtolower(implode(' ', [
                    $backup['id'] ?? '',
                    $backup['label'] ?? '',
                    $backup['note'] ?? '',
                    implode(' ', $backup['tags'] ?? []),
                ])), strtolower($query))
            ))
            ->when($filters['from'] ?? null, static fn ($items, string $date) => $items->filter(
                static fn (array $backup): bool => ($backup['created_at'] ?? '') >= $date
            ))
            ->when($filters['to'] ?? null, static fn ($items, string $date) => $items->filter(
                static fn (array $backup): bool => ($backup['created_at'] ?? '') <= $date.'T23:59:59'
            ))
            ->when($filters['type'] ?? null, static fn ($items, string $type) => $items->where('type', $type))
            ->when($filters['status'] ?? null, static fn ($items, string $status) => $items->where('status', $status))
            ->when($filters['disk'] ?? null, static fn ($items, string $disk) => $items->where('disk', $disk))
            ->values();

        return view('backup-manager::dashboard', [
            'backups' => $backups,
            'totalBackups' => $allBackups->count(),
            'totalSize' => $allBackups->sum('size'),
            'latestBackup' => $allBackups->first(),
            'filters' => $filters,
            'availableBackupDisks' => $availableBackupDisks,
            'nextScheduledBackup' => $nextScheduledBackup,
            'lastBackupStatus' => $this->cache->get('backup-manager:last-run'),
            'diskName' => $diskName,
            'profileName' => $profileName,
            'databaseEnabled' => (bool) data_get($profile, 'database.enabled', false),
            'filesEnabled' => (bool) data_get($profile, 'files.enabled', false),
        ]);
    }

    public function setLocale(string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetDashboardLocale::SUPPORTED_LOCALES, true), 404);
        request()->session()->put('backup_manager_locale', $locale);

        return redirect()->back();
    }

    public function run(): RedirectResponse
    {
        $validated = request()->validate([
            'database' => ['nullable', 'boolean'],
            'files' => ['nullable', 'boolean'],
            'label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['nullable', 'string', 'max:40'],
        ]);

        if (empty($validated['database']) && empty($validated['files'])) {
            return back()->with('backup_error', __('backup-manager::dashboard.choose_component'));
        }

        $preflight = $this->preflight->inspect([
            'database' => (bool) ($validated['database'] ?? false),
            'files' => (bool) ($validated['files'] ?? false),
        ]);
        if (! $preflight['ready']) {
            return back()->with('backup_error', implode(' ', $preflight['errors']));
        }

        $jobId = (string) Str::uuid();
        $key = 'backup-manager:jobs:'.$jobId;
        $this->cache->put($key, ['status' => 'queued'], now()->addDay());

        $job = new CreateBackupJob($jobId, [
            'database' => (bool) ($validated['database'] ?? false),
            'files' => (bool) ($validated['files'] ?? false),
            'label' => $validated['label'] ?? null,
            'note' => $validated['note'] ?? null,
            'tags' => array_values(array_unique(array_filter(array_map('trim', preg_split('/[\\r\\n,]+/', implode('\\n', $validated['tags'] ?? [])) ?: [])))),
            'type' => 'manual',
        ]);

        $connection = $this->config->get('backup-manager.queue.connection');
        $queue = $this->config->get('backup-manager.queue.queue');

        try {
            if (is_string($connection) && $connection !== '' && $connection !== 'sync') {
                $job->onConnection($connection);
                if (is_string($queue) && $queue !== '') {
                    $job->onQueue($queue);
                }
                dispatch($job);
            } else {
                // No worker is required: Laravel runs this after the HTTP response is sent.
                dispatch($job)->afterResponse();
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->cache->put($key, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], now()->addDay());

            return back()->with('backup_error', __('backup-manager::dashboard.backup_queue_failed'));
        }

        return back()->with('backup_job_id', $jobId)
            ->with('backup_success', __('backup-manager::dashboard.backup_started'));
    }

    public function jobStatus(string $jobId): Response
    {
        abort_unless(Str::isUuid($jobId), 404);
        $status = $this->cache->get('backup-manager:jobs:'.$jobId);

        abort_unless(is_array($status), 404);

        return response()->json($status);
    }

    public function download(string $id, string $artifact): mixed
    {
        abort_unless(preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id) === 1, 404);
        abort_unless(basename($artifact) === $artifact && preg_match('/^[A-Za-z0-9_.-]+$/', $artifact) === 1, 404);

        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        abort_unless(is_array($profile), 404);

        $storage = $profile['storage'] ?? [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/').'/'.$id;
        $diskNames = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));

        $selectedDisk = null;
        $manifest = null;
        foreach ($diskNames as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if (! $disk->exists($prefix.'/manifest.json')) {
                    continue;
                }

                $candidate = json_decode((string) $disk->get($prefix.'/manifest.json'), true);
                $allowedArtifact = $artifact === 'manifest.json'
                    || $artifact === 'checksums.sha256'
                    || (is_array($candidate) && isset($candidate['artifacts'][$artifact]));
                if (is_array($candidate)
                    && ($candidate['id'] ?? null) === $id
                    && $allowedArtifact
                    && $disk->exists($prefix.'/'.$artifact)) {
                    $selectedDisk = $disk;
                    $manifest = $candidate;
                    break;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        abort_unless($selectedDisk !== null && is_array($manifest), 404);
        $allowed = $artifact === 'manifest.json'
            || $artifact === 'checksums.sha256'
            || isset($manifest['artifacts'][$artifact]);
        abort_unless($allowed, 404);

        $path = $prefix.'/'.$artifact;
        abort_unless($selectedDisk->exists($path), 404);
        $stream = $selectedDisk->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $artifact, ['Content-Type' => 'application/octet-stream']);
    }

    public function show(string $id): View
    {
        abort_unless(preg_match('/^\\d{8}_\\d{6}_[a-f0-9]{8}$/', $id) === 1, 404);

        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $diskNames = array_values(array_unique(array_filter([
            (string) data_get($profile, 'storage.disk', 'local'),
            (string) data_get($profile, 'storage.local_disk', 'local'),
            ...array_values(array_filter((array) data_get($profile, 'storage.disks', []), 'is_string')),
        ])));
        $backup = null;
        foreach ($diskNames as $diskName) {
            try {
                $backup = collect($this->inspector->list($profileName, $diskName, 500))->firstWhere('id', $id);
                if (is_array($backup)) {
                    break;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        abort_unless(is_array($backup), 404);

        return view('backup-manager::details', ['backup' => $backup]);
    }

    public function delete(string $id): RedirectResponse
    {
        try {
            $result = $this->deleter->delete($id);
            if ($result['failed'] !== []) {
                return back()->with('backup_error', __('backup-manager::dashboard.delete_partial', [
                    'disks' => implode(', ', $result['failed']),
                ]));
            }

            return back()->with('backup_success', __('backup-manager::dashboard.backup_deleted'));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('backup_error', __('backup-manager::dashboard.backup_delete_failed'));
        }
    }

    public function protection(string $id): RedirectResponse
    {
        $validated = request()->validate([
            'unlock' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'protected_until' => ['nullable', 'date', 'after:now'],
        ]);

        $unlock = (bool) ($validated['unlock'] ?? false);
        if ($unlock && ! empty($validated['protected_until'])) {
            return back()->withErrors(['protected_until' => __('backup-manager::dashboard.protection_unlock_date_conflict')]);
        }

        try {
            $this->protector->update(
                $id,
                locked: ! $unlock,
                protectedUntil: $unlock ? null : ($validated['protected_until'] ?? null),
                reason: $unlock ? null : ($validated['reason'] ?? null),
            );

            return back()->with('backup_success', $unlock
                ? __('backup-manager::dashboard.protection_removed')
                : __('backup-manager::dashboard.protection_saved'));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('backup_error', __('backup-manager::dashboard.protection_failed'));
        }
    }

    public function copyToDisk(string $id): RedirectResponse
    {
        abort_unless(preg_match('/^\\d{8}_\\d{6}_[a-f0-9]{8}$/', $id) === 1, 404);

        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $storage = is_array($profile) ? ($profile['storage'] ?? []) : [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $backupPath = ($prefix === '' ? '' : $prefix.'/').$id;
        $diskNames = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));
        $validated = request()->validate([
            'destination' => ['required', 'string', 'in:'.implode(',', $diskNames)],
        ]);
        $destinationName = $validated['destination'];
        $source = null;

        foreach ($diskNames as $diskName) {
            if ($diskName === $destinationName) {
                continue;
            }

            try {
                $disk = $this->filesystem->disk($diskName);
                if ($disk->exists($backupPath.'/manifest.json')) {
                    $source = $disk;
                    break;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($source === null) {
            return back()->with('backup_error', 'The source backup could not be found on another configured storage target.');
        }

        try {
            $destination = $this->filesystem->disk($destinationName);
            if ($destination->allFiles($backupPath) !== []) {
                return back()->with('backup_error', 'The destination already contains files for this backup. Existing files were not overwritten.');
            }

            $files = $source->allFiles($backupPath);
            usort($files, static fn (string $a, string $b): int => (str_ends_with($a, '/manifest.json') ? 1 : 0) <=> (str_ends_with($b, '/manifest.json') ? 1 : 0));

            foreach ($files as $path) {
                $stream = $source->readStream($path);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to read source backup file: '.$path);
                }

                try {
                    if (! $destination->writeStream($path, $stream)) {
                        throw new RuntimeException('Unable to write backup file to destination: '.$path);
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable $exception) {
            try {
                $this->filesystem->disk($destinationName)->deleteDirectory($backupPath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            report($exception);

            return back()->with('backup_error', 'The backup copy failed. The incomplete destination copy was removed where possible.');
        }

        return back()->with('backup_success', 'Backup copied to '.$destinationName.'.');
    }

    public function downloadArchive(string $id): mixed
    {
        abort_unless(preg_match('/^\\d{8}_\\d{6}_[a-f0-9]{8}$/', $id) === 1, 404);
        abort_unless(class_exists(\ZipArchive::class), 501, 'The PHP zip extension is required to create a combined backup archive.');

        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $storage = is_array($profile) ? ($profile['storage'] ?? []) : [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $backupPath = ($prefix === '' ? '' : $prefix.'/').$id;
        $diskNames = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));
        $source = null;
        $manifest = null;

        foreach ($diskNames as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if (! $disk->exists($backupPath.'/manifest.json')) {
                    continue;
                }

                $candidate = json_decode((string) $disk->get($backupPath.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
                    $source = $disk;
                    $manifest = $candidate;
                    break;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        abort_unless($source !== null && is_array($manifest), 404);

        $temporaryBase = tempnam(sys_get_temp_dir(), 'backup-manager-');
        abort_unless(is_string($temporaryBase), 500, 'Unable to allocate a temporary archive file.');
        @unlink($temporaryBase);
        $archivePath = $temporaryBase.'.zip';
        $temporaryFiles = [];
        $archive = new \ZipArchive();

        try {
            if ($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the combined backup archive.');
            }

            foreach ((array) ($manifest['artifacts'] ?? []) as $name => $metadata) {
                if (! is_string($name) || basename($name) !== $name || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
                    continue;
                }

                $stream = $source->readStream($backupPath.'/'.$name);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to read backup artifact: '.$name);
                }

                $temporaryArtifact = tempnam(sys_get_temp_dir(), 'backup-artifact-');
                if (! is_string($temporaryArtifact)) {
                    fclose($stream);
                    throw new RuntimeException('Unable to allocate temporary space for backup artifact.');
                }

                $temporaryFiles[] = $temporaryArtifact;
                $target = fopen($temporaryArtifact, 'wb');
                if (! is_resource($target)) {
                    fclose($stream);
                    throw new RuntimeException('Unable to open temporary backup artifact.');
                }

                try {
                    stream_copy_to_stream($stream, $target);
                } finally {
                    fclose($stream);
                    fclose($target);
                }

                if (! $archive->addFile($temporaryArtifact, $name)) {
                    throw new RuntimeException('Unable to add backup artifact to archive: '.$name);
                }
            }

            $archive->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($source->exists($backupPath.'/checksums.sha256')) {
                $archive->addFromString('checksums.sha256', (string) $source->get($backupPath.'/checksums.sha256'));
            }

            $archive->close();
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }

            return response()->download($archivePath, $id.'.zip')->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            $archive->close();
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
            @unlink($archivePath);
            report($exception);

            return back()->with('backup_error', 'Unable to create a combined backup archive.');
        }
    }

    public function repeat(string $id): RedirectResponse
    {
        abort_unless(preg_match('/^\\d{8}_\\d{6}_[a-f0-9]{8}$/', $id) === 1, 404);

        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $storage = is_array($profile) ? ($profile['storage'] ?? []) : [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $relative = ($prefix === '' ? '' : $prefix.'/').$id.'/manifest.json';
        $disks = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));

        foreach ($disks as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if (! $disk->exists($relative)) {
                    continue;
                }

                $manifest = json_decode((string) $disk->get($relative), true, 512, JSON_THROW_ON_ERROR);
                abort_unless(is_array($manifest) && ($manifest['id'] ?? null) === $id, 404);

                $artifacts = (array) ($manifest['artifacts'] ?? []);
                request()->merge([
                    'database' => collect(array_keys($artifacts))->contains(
                        fn (string $name): bool => str_contains(strtolower($name), 'database') || str_contains(strtolower($name), 'sql')
                    ),
                    'files' => collect(array_keys($artifacts))->contains(
                        fn (string $name): bool => str_contains(strtolower($name), 'application') || str_contains(strtolower($name), 'zip')
                    ),
                    'label' => $manifest['label'] ?? null,
                    'note' => $manifest['note'] ?? null,
                    'tags' => $manifest['tags'] ?? [],
                ]);

                return $this->run();
            } catch (HttpResponseException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        abort(404, 'Backup manifest not found.');
    }

    public function updateMetadata(string $id): RedirectResponse
    {
        abort_unless(preg_match('/^\\d{8}_\\d{6}_[a-f0-9]{8}$/', $id) === 1, 404);

        $validated = request()->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $storage = is_array($profile) ? ($profile['storage'] ?? []) : [];
        $prefix = trim((string) ($storage['path'] ?? 'backups'), '/');
        $relative = ($prefix === '' ? '' : $prefix.'/').$id.'/manifest.json';
        $disks = array_values(array_unique(array_filter([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
            ...array_values(array_filter((array) ($storage['disks'] ?? []), 'is_string')),
        ])));

        $originals = [];
        foreach ($disks as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if ($disk->exists($relative)) {
                    $originals[$diskName] = (string) $disk->get($relative);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($originals === []) {
            abort(404, 'Backup manifest not found.');
        }

        $manifest = json_decode(reset($originals), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($manifest) && ($manifest['id'] ?? null) === $id, 404);

        $manifest['label'] = trim((string) ($validated['label'] ?? ''));
        $manifest['note'] = trim((string) ($validated['note'] ?? ''));
        $manifest['tags'] = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[\\r\\n,]+/', (string) ($validated['tags'] ?? '')) ?: []
        ))));

        $contents = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $updated = [];

        try {
            foreach ($originals as $diskName => $original) {
                if (! $this->filesystem->disk($diskName)->put($relative, $contents)) {
                    throw new RuntimeException("Unable to update manifest on disk [{$diskName}].");
                }

                $updated[] = $diskName;
            }
        } catch (Throwable $exception) {
            foreach ($updated as $diskName) {
                try {
                    $this->filesystem->disk($diskName)->put($relative, $originals[$diskName]);
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                }
            }

            report($exception);

            return back()->with('backup_error', 'Backup metadata could not be saved consistently across storage targets.');
        }

        return back()->with('backup_success', 'Backup label, note and tags updated.');
    }

    public function bulk(): RedirectResponse
    {
        $validated = request()->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'regex:/^\\d{8}_\\d{6}_[a-f0-9]{8}$/', 'distinct'],
            'action' => ['required', 'in:delete,protect,unlock'],
        ]);

        $succeeded = [];
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                if ($validated['action'] === 'delete') {
                    $result = $this->deleter->delete($id);
                    if ($result['failed'] !== []) {
                        throw new RuntimeException('Delete failed on: '.implode(', ', $result['failed']));
                    }
                } else {
                    $this->protector->update(
                        $id,
                        locked: $validated['action'] === 'protect',
                        reason: $validated['action'] === 'protect' ? 'Bulk protection' : null,
                    );
                }

                $succeeded[] = $id;
            } catch (Throwable $exception) {
                $failed[$id] = $exception->getMessage();
            }
        }

        if ($failed !== []) {
            return back()->with('backup_error', count($succeeded).' backup(s) processed; '.count($failed).' skipped or failed. Protected backups are never deleted by bulk delete.');
        }

        return back()->with('backup_success', count($succeeded).' backup(s) processed successfully.');
    }

    public function verify(string $id): RedirectResponse
    {
        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $storagePath = trim((string) data_get($profile, 'storage.path', 'backups'), '/');
        $backupPath = ($storagePath === '' ? '' : $storagePath.'/').$id.'/manifest.json';
        $diskNames = array_values(array_unique(array_filter([
            (string) data_get($profile, 'storage.disk', 'local'),
            (string) data_get($profile, 'storage.local_disk', 'local'),
            ...array_values(array_filter((array) data_get($profile, 'storage.disks', []), 'is_string')),
        ])));

        $verifiedDisks = [];
        $errors = [];
        foreach ($diskNames as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);
                if (! $disk->exists($backupPath)) {
                    continue;
                }

                $result = $this->inspector->verify($id, $profileName, $diskName);
                if ($result['valid']) {
                    $verifiedDisks[] = $diskName;
                } else {
                    $errors[] = $diskName.': '.implode(' ', $result['errors']);
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = $diskName.': '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            return back()->with('backup_error', __('backup-manager::dashboard.verification_failed', [
                'errors' => implode(' ', $errors),
            ]));
        }

        if ($verifiedDisks !== []) {
            return back()->with('backup_success', __('backup-manager::dashboard.verification_success', [
                'id' => $id.' ('.implode(', ', $verifiedDisks).')',
            ]));
        }

        return back()->with('backup_error', __('backup-manager::dashboard.verification_error'));
    }
}
