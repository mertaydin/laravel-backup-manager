<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Storage Management · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.4') }}-0.8.4 }}">

</head>
<body>
<div class="shell storage-page">
    <a class="storage-back" href="{{ route('backup-manager.dashboard') }}">← {{ __('backup-manager::dashboard.dashboard_title') }}</a>
    <h1>Storage Management</h1>
    <p>Configure and verify backup destinations. Google Drive authorization is handled directly through Google OAuth and Drive API; no third-party Google Drive Composer package is used.</p>

    @if(session('storage_success'))<div class="alert success">{{ session('storage_success') }}</div>@endif
    @if(session('storage_error'))<div class="alert error" role="alert">{{ session('storage_error') }}</div>@endif
    @if($errors->any())<div class="alert error" role="alert"><strong>{{ __('backup-manager::dashboard.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <section class="storage-card">
        <h2>Google Drive</h2>
        <p>{{ $googleConnected ? 'A Google Drive account is connected.' : 'Connect a Google account using your OAuth client credentials.' }}</p>
        <form method="POST" action="{{ route('backup-manager.storage.google.credentials') }}">
            @csrf
            <label class="storage-field"><strong>OAuth Client ID</strong><input type="text" name="client_id" value="{{ old('client_id', data_get($settings, 'disks.google-drive.client_id')) }}" required autocomplete="off"></label>
            <label class="storage-field"><strong>OAuth Client Secret</strong><input type="password" name="client_secret" value="{{ old('client_secret') }}" placeholder="{{ data_get($settings, 'disks.google-drive.client_secret') ? 'Saved securely — enter only to replace' : '' }}" {{ data_get($settings, 'disks.google-drive.client_secret') ? '' : 'required' }} autocomplete="new-password"></label>
            <p><strong>Authorized redirect URI:</strong><br><code>{{ $callbackUrl }}</code></p>
            <div class="storage-actions"><button class="primary" type="submit">Save OAuth credentials</button></div>
        </form>
        <div class="storage-actions" style="margin-top:14px">
            <form method="POST" action="{{ route('backup-manager.storage.google.connect') }}">@csrf<button class="primary" type="submit">Connect Google Drive</button></form>
            @if($googleConnected)
                <form method="POST" action="{{ route('backup-manager.storage.google.test') }}">@csrf<button type="submit">Test connection</button></form>
            @endif
        </div>
        <p>In Google Cloud Console, enable the Google Drive API, create an OAuth 2.0 Web application client, and register the redirect URI shown above. The client secret and OAuth tokens are encrypted in the package settings table.</p>
    </section>

    <section class="storage-card">
        <h2>Automatic backups</h2>
        <p>Enable or pause daily backups without changing your server configuration. The Laravel scheduler must be running every minute on the server.</p>
        <form method="POST" action="{{ route('backup-manager.storage.schedule') }}">
            @csrf
            <label class="storage-field">
                <strong>Automatic backup</strong>
                <span><input type="checkbox" name="enabled" value="1" {{ ($schedule['enabled'] ?? false) ? 'checked' : '' }}> Enable scheduled backups</span>
            </label>
            <label class="storage-field">
                <strong>Run time</strong>
                <input type="time" name="time" value="{{ old('time', $schedule['time'] ?? '02:00') }}" required>
            </label>
            <label class="storage-field">
                <strong>Timezone</strong>
                <input type="text" name="timezone" value="{{ old('timezone', $schedule['timezone'] ?? config('app.timezone', 'UTC')) }}" list="backup-timezones" required>
                <datalist id="backup-timezones">
                    <option value="Europe/Istanbul">
                    <option value="UTC">
                    <option value="Europe/London">
                    <option value="Europe/Berlin">
                    <option value="America/New_York">
                </datalist>
            </label>
            <label class="storage-field">
                <strong>Delete backups older than (days)</strong>
                <input type="number" name="retain_days" min="1" max="3650" value="{{ old('retain_days', $schedule['retain_days'] ?? 30) }}" required>
                <small>After a successful scheduled backup, backup sets older than this number of days are permanently deleted. Recent backups are retained.</small>
            </label>
            <div class="storage-actions"><button class="primary" type="submit">Save automatic backup settings</button></div>
        </form>
        <p><strong>Server cron requirement:</strong> <code>* * * * * php /path/to/your-project/artisan schedule:run</code></p>
    </section>

    <section class="storage-card">
        <h2>Laravel Filesystem disks</h2>
        <p>Existing Laravel disks such as local, S3 and Cloudflare R2 remain configured in your application's <code>config/filesystems.php</code>. Select the disk in the backup profile's <code>storage.disk</code> setting.</p>
        <ul>
            @foreach($filesystemDisks as $disk)
                <li style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                    <code>{{ $disk }}</code>
                    <form method="POST" action="{{ route('backup-manager.storage.test') }}">
                        @csrf
                        <input type="hidden" name="disk" value="{{ $disk }}">
                        <button type="submit">Test connection and permissions</button>
                    </form>
                </li>
            @endforeach
        </ul>
        <p>Each test performs a temporary write, read, listing and delete check. The temporary test file is removed after the check.</p>
        <p>Google Drive is currently available here for account connection and verification. It is not selectable as a backup destination until the native storage adapter and historical backup operations are completed.</p>
    </section>
</div>
</body>
</html>
