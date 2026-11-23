<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager\\Http\\Controllers;

use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Routing\\Controller;
use Illuminate\\Support\\Str;
use Illuminate\\View\\View;
use MertAydin\\LaravelBackupManager\\Storage\\GoogleDriveClient;
use MertAydin\\LaravelBackupManager\\Storage\\GoogleDriveOAuth;
use MertAydin\\LaravelBackupManager\\Storage\\StorageSettingsRepository;
use RuntimeException;
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
        $configured = array_keys(config('filesystems.disks', []));
        sort($configured);

        return view('backup-manager::storage', [
            'settings' => $settings,
            'filesystemDisks' => $configured,
            'googleConnected' => ! empty(data_get($settings, 'disks.google-drive.refresh_token')),
            'callbackUrl' => route('backup-manager.storage.google.callback'),
        ]);
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

        return back()->with('storage_success', 'Google OAuth client credentials saved securely.');
    }

    public function connectGoogle(Request $request): RedirectResponse
    {
        $credentials = data_get($this->settings->all(), 'disks.google-drive', []);
        abort_unless(! empty($credentials['client_id']) && ! empty($credentials['client_secret']), 422, 'Save Google OAuth client credentials first.');

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
        abort_unless($expected !== '' && hash_equals($expected, $received), 419, 'Google OAuth state validation failed.');
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
            $this->settings->putDisk('google-drive', array_merge($credentials, $tokens));
            (new GoogleDriveClient(array_merge($credentials, $tokens)))->testConnection();

            return redirect()->route('backup-manager.storage')->with('storage_success', 'Google Drive account connected and verified.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('backup-manager.storage')->with('storage_error', 'Google Drive authorization could not be completed. Check the application log.');
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

            return back()->with('storage_error', 'Google Drive connection test failed. Check the credentials and application log.');
        }
    }

    public function activateFilesystemDisk(Request $request): RedirectResponse
    {
        $data = $request->validate(['disk' => ['required', 'string', 'max:100']]);
        $disks = config('filesystems.disks', []);
        abort_unless($data['disk'] === 'local' || isset($disks[$data['disk']]), 422, 'Unknown Laravel filesystem disk.');

        $profileName = (string) config('backup-manager.default', 'default');
        $profile = config('backup-manager.profiles.'.$profileName, []);
        abort_unless(is_array($profile), 422);
        $profile['storage']['disk'] = $data['disk'];
        config(['backup-manager.profiles.'.$profileName => $profile]);

        return back()->with('storage_error', 'Disk selection is not persisted yet. Configure storage.disk in backup-manager.php until persistent profile settings are available.');
    }
}
