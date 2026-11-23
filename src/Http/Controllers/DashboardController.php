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
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\Http\Middleware\SetDashboardLocale;
use MertAydin\LaravelBackupManager\Jobs\CreateBackupJob;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly BackupInspector $inspector,
        private readonly BackupManager $backups,
        private readonly BackupDeleter $deleter,
        private readonly FilesystemFactory $filesystem,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ) {
    }

    public function index(): View
    {
        $profileName = (string) config('backup-manager.default', 'default');
        $profile = config('backup-manager.profiles.'.$profileName, []);
        $diskName = (string) data_get($profile, 'storage.disk', 'local');
        $backups = collect($this->inspector->list($profileName, $diskName, 100))
            ->map(static function (array $backup): array {
                $backup['size'] = (int) ($backup['total_size'] ?? 0);

                return $backup;
            });

        return view('backup-manager::dashboard', [
            'backups' => $backups,
            'totalBackups' => $backups->count(),
            'totalSize' => $backups->sum('size'),
            'latestBackup' => $backups->first(),
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
        ]);

        if (empty($validated['database']) && empty($validated['files'])) {
            return back()->with('backup_error', __('backup-manager::dashboard.choose_component'));
        }

        $jobId = (string) Str::uuid();
        $key = 'backup-manager:jobs:'.$jobId;
        $this->cache->put($key, ['status' => 'queued'], now()->addDay());

        $job = new CreateBackupJob($jobId, [
            'database' => (bool) ($validated['database'] ?? false),
            'files' => (bool) ($validated['files'] ?? false),
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
        $diskNames = array_values(array_unique([
            (string) ($storage['disk'] ?? 'local'),
            (string) ($storage['local_disk'] ?? 'local'),
        ]));

        $selectedDisk = null;
        $manifest = null;
        foreach ($diskNames as $diskName) {
            $disk = $this->filesystem->disk($diskName);
            if (! $disk->exists($prefix.'/manifest.json')) {
                continue;
            }

            $candidate = json_decode((string) $disk->get($prefix.'/manifest.json'), true);
            if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
                $selectedDisk = $disk;
                $manifest = $candidate;
                break;
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

    public function verify(string $id): RedirectResponse
    {
        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);

        try {
            $result = $this->inspector->verify(
                $id,
                $profileName,
                (string) data_get($profile, 'storage.disk', 'local')
            );

            if (! $result['valid']) {
                return back()->with('backup_error', __('backup-manager::dashboard.verification_failed', ['errors' => implode(' ', $result['errors'])]));
            }

            return back()->with('backup_success', __('backup-manager::dashboard.verification_success', ['id' => $id]));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('backup_error', __('backup-manager::dashboard.verification_error'));
        }
    }
}
