<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use MertAydin\LaravelBackupManager\Storage\GoogleDriveClient;
use MertAydin\LaravelBackupManager\Storage\GoogleDriveOAuth;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Throwable;

final class StorageManagementController extends Controller
{
    public function __construct(
        private readonly StorageSettingsRepository $settings,
        private readonly GoogleDriveOAuth $oauth,
    ) {}

    public function index(): View
    {
        $settings = $this->settings->all();
        $disks = array_keys(config('filesystems.disks', []));
        sort($disks);

        return view('backup-manager::storage', [
            'settings' => $settings,
            'filesystemDisks' => $disks,
            'googleConnected' => ! empty(data_get($settings, 'disks.google-drive.refresh_token')),
            'callbackUrl' => route('backup-manager.storage.google.callback'),
            'schedule' => $settings['schedule'] ?? ['enabled' => false, 'time' => '02:00', 'timezone' => config('app.timezone', 'UTC'), 'retain_days' => 30],
        ]);
    }

    public function saveSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'retain_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $settings = $this->settings->all();
        $settings['schedule'] = [
            'enabled' => $request->boolean('enabled'),
            'time' => $data['time'],
            'timezone' => $data['timezone'],
            'retain_days' => (int) $data['retain_days'],
        ];
        $this->settings->save($settings);

        return back()->with('storage_success', 'Automatic backup schedule saved.');
    }

    public function saveGoogleCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        $settings = $this->settings->all();
        $existing = $settings['disks']['google-drive'] ?? [];
        $settings['disks']['google-drive'] = array_merge($existing, [
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'],
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

    public function testGoogle(): RedirectResponse
    {
        try {
            $credentials = data_get($this->settings->all(), 'disks.google-drive', []);
            (new GoogleDriveClient($credentials))->testConnection();

            return back()->with('storage_success', 'Google Drive connection test succeeded.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('storage_error', 'Google Drive connection test failed. Check the application log.');
        }
    }
}
