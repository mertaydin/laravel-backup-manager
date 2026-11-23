<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
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
