<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use MertAydin\LaravelBackupManager\BackupManagerActivityLogger;
use MertAydin\LaravelBackupManager\Storage\GoogleDriveClient;
use MertAydin\LaravelBackupManager\Storage\GoogleDriveOAuth;
use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;
use MertAydin\LaravelBackupManager\BackupPruner;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Throwable;

final class StorageManagementController extends Controller
{
    public function __construct(
        private readonly StorageSettingsRepository $settings,
        private readonly GoogleDriveOAuth $oauth,
        private readonly StorageHealthChecker $healthChecker,
        private readonly BackupManagerActivityLogger $activity,
        private readonly BackupPruner $pruner,
    ) {}

    public function index(): View
    {
        $settings = $this->settings->all();
        $disks = array_keys(config('filesystems.disks', []));
        if (! empty(data_get($settings, 'disks.google-drive.refresh_token'))) {
            $disks[] = 'google-drive';
        }
        $disks = array_values(array_unique($disks));
        sort($disks);

        return view('backup-manager::storage', [
            'settings' => $settings,
            'filesystemDisks' => $disks,
            'googleConnected' => ! empty(data_get($settings, 'disks.google-drive.refresh_token')),
            'selectedTargets' => $settings['backup_targets'] ?? [data_get(config('backup-manager.profiles.'.config('backup-manager.default', 'default')), 'storage.disk', 'local')],
            'callbackUrl' => route('backup-manager.storage.google.callback'),
            'schedule' => $settings['schedule'] ?? ['enabled' => false, 'time' => '02:00', 'timezone' => config('app.timezone', 'UTC'), 'retain_days' => 30, 'warning_threshold_percent' => 85],
            'storageActivities' => $this->activity->recent(10, 'storage.test'),
            'storageCapacity' => $this->localStorageCapacity($disks, (int) data_get($settings, 'schedule.warning_threshold_percent', 85)),
        ]);
    }

    public function saveTargets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);

        $available = array_keys(config('filesystems.disks', []));
        if (! empty(data_get($this->settings->all(), 'disks.google-drive.refresh_token'))) {
            $available[] = 'google-drive';
        }
        $targets = array_values(array_unique($data['targets']));
        foreach ($targets as $target) {
            if (! in_array($target, $available, true)) {
                return back()->withErrors(['targets' => "Storage target [{$target}] is not configured or connected."]);
            }
        }

        $settings = $this->settings->all();
        $settings['backup_targets'] = $targets;
        $this->settings->save($settings);

        return back()->with('storage_success', 'Backup destinations saved. New backups will be uploaded only to the selected destinations.');
    }

    public function saveSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'retain_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'warning_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $settings = $this->settings->all();
        $settings['schedule'] = [
            'enabled' => $request->boolean('enabled'),
            'time' => $data['time'],
            'timezone' => $data['timezone'],
            'retain_days' => (int) $data['retain_days'],
            'warning_threshold_percent' => (int) ($data['warning_threshold_percent'] ?? 85),
        ];
        $this->settings->save($settings);

        return back()->with('storage_success', 'Automatic backup schedule saved.');
    }

    public function saveGoogleCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'root_folder_id' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = $this->settings->all();
        $existing = $settings['disks']['google-drive'] ?? [];
        $settings['disks']['google-drive'] = array_merge($existing, [
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'] ?: ($existing['client_secret'] ?? ''),
            'root_folder_id' => trim((string) ($data['root_folder_id'] ?? '')) ?: 'root',
        ]);
        $this->settings->save($settings);

        return back()->with('storage_success', 'Google OAuth client credentials saved encrypted.');
    }

    public function connectGoogle(Request $request): RedirectResponse
    {
        $credentials = data_get($this->settings->all(), 'disks.google-drive', []);
        abort_unless(! empty($credentials['client_id']) && ! empty($credentials['client_secret']), 422);

        $state = Str::random(64);
        $request->session()->put('backup_manager_google_oauth_state', $state);

        return redirect()->away($this->oauth->authorizationUrl(
            (string) $credentials['client_id'],
            route('backup-manager.storage.google.callback'),
            $state,
        ));
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $expected = (string) $request->session()->pull('backup_manager_google_oauth_state', '');
        $received = (string) $request->query('state', '');
        abort_unless($expected !== '' && hash_equals($expected, $received), 419);

        if ($request->filled('error')) {
            return redirect()->route('backup-manager.storage')->with('storage_error', 'Google authorization was cancelled or rejected.');
        }

        $credentials = data_get($this->settings->all(), 'disks.google-drive', []);
        abort_unless(! empty($credentials['client_id']) && ! empty($credentials['client_secret']), 422);

        try {
            $tokens = $this->oauth->exchangeCode(
                (string) $request->query('code', ''),
                (string) $credentials['client_id'],
                (string) $credentials['client_secret'],
                route('backup-manager.storage.google.callback'),
            );

            if (empty($tokens['refresh_token']) && ! empty($credentials['refresh_token'])) {
                $tokens['refresh_token'] = $credentials['refresh_token'];
            }

            $connected = array_merge($credentials, $tokens);
            (new GoogleDriveClient($connected))->testConnection();
            $this->settings->putDisk('google-drive', $connected);

            return redirect()->route('backup-manager.storage')->with('storage_success', 'Google Drive account connected and verified.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('backup-manager.storage')->with('storage_error', 'Google Drive authorization failed. Check the application log.');
        }
    }

    public function testDisk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'disk' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);
        $startedAt = now();

        try {
            $profileName = (string) config('backup-manager.default', 'default');
            $prefix = (string) data_get(config('backup-manager.profiles.'.$profileName, []), 'storage.path', 'backups');
            $result = $this->healthChecker->check($data['disk'], $prefix);
            $failed = array_keys(array_filter($result['checks'], static fn (bool $passed): bool => ! $passed));
            $this->activity->record('storage.test', $result['successful'] ? 'completed' : 'failed', disk: $data['disk'], message: $result['successful'] ? 'Storage health check passed.' : 'Failed checks: '.implode(', ', $failed), metadata: ['checks' => $result['checks']], startedAt: $startedAt->toDateTimeString(), finishedAt: now()->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds(now()));

            if ($result['successful']) {
                return back()->with('storage_success', "Storage disk [{$data['disk']}] passed connection, list, write, read and delete checks.");
            }

            return back()->with('storage_error', "Storage disk [{$data['disk']}] failed checks: ".implode(', ', $failed).'.');
        } catch (Throwable $exception) {
            $this->activity->record('storage.test', 'failed', disk: $data['disk'], message: $exception->getMessage(), startedAt: $startedAt->toDateTimeString(), finishedAt: now()->toDateTimeString(), durationSeconds: (int) $startedAt->diffInSeconds(now()));
            report($exception);

            return back()->with('storage_error', "Storage disk [{$data['disk']}] could not be checked. Review the application log.");
        }
    }

    /** @param list<string> $disks
     * @return array<string, array{free_bytes: int|null, total_bytes: int|null, used_percent: float|null, warning: bool}>
     */
    private function localStorageCapacity(array $disks, int $warningThreshold): array
    {
        $capacity = [];
        foreach ($disks as $diskName) {
            $diskConfig = config('filesystems.disks.'.$diskName, []);
            if (! is_array($diskConfig) || ($diskConfig['driver'] ?? null) !== 'local') {
                continue;
            }

            $root = (string) ($diskConfig['root'] ?? '');
            $probePath = $root;
            while ($probePath !== '' && ! is_dir($probePath) && dirname($probePath) !== $probePath) {
                $probePath = dirname($probePath);
            }
            $free = $probePath !== '' && is_dir($probePath) ? @disk_free_space($probePath) : false;
            $total = $probePath !== '' && is_dir($probePath) ? @disk_total_space($probePath) : false;
            $freeBytes = is_float($free) || is_int($free) ? (int) $free : null;
            $totalBytes = is_float($total) || is_int($total) ? (int) $total : null;
            $usedPercent = $freeBytes !== null && $totalBytes !== null && $totalBytes > 0
                ? round((1 - ($freeBytes / $totalBytes)) * 100, 1)
                : null;
            $capacity[$diskName] = [
                'free_bytes' => $freeBytes,
                'total_bytes' => $totalBytes,
                'used_percent' => $usedPercent,
                'warning' => $usedPercent !== null && $usedPercent >= $warningThreshold,
            ];
        }

        return $capacity;
    }

    public function testGoogle(): RedirectResponse
    {
        try {
            $credentials = data_get($this->settings->all(), 'disks.google-drive', []);
            (new GoogleDriveClient($credentials))->testConnection();
            $profileName = (string) config('backup-manager.default', 'default');
            $prefix = (string) data_get(config('backup-manager.profiles.'.$profileName, []), 'storage.path', 'backups');
            $result = $this->healthChecker->check('google-drive', $prefix);
            if (! $result['successful']) {
                throw new \RuntimeException('Google Drive read/write/delete verification failed.');
            }

            return back()->with('storage_success', 'Google Drive connection and read/write/delete tests succeeded.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('storage_error', 'Google Drive connection test failed. Check the application log.');
        }
    }
}
