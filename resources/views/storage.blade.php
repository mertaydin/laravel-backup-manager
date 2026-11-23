<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('backup-manager::dashboard.storage_title') }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.7') }}-0.8.7">

</head>
<body>
<div class="shell storage-page">
    <div class="top-actions"><a class="storage-back" href="{{ route('backup-manager.dashboard') }}">← {{ __('backup-manager::dashboard.back') }}</a><select class="toolbar-select" aria-label="{{ __('backup-manager::dashboard.language') }}" data-locale-switcher>@foreach(['en'=>'English','tr'=>'Türkçe','es'=>'Español','zh_CN'=>'简体中文','hi'=>'हिन्दी','ar'=>'العربية'] as $locale => $label)<option value="{{ route('backup-manager.locale', ['locale' => $locale]) }}" {{ app()->getLocale() === $locale ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
    <h1>{{ __('backup-manager::dashboard.storage_title') }}</h1>
    <p>{{ __('backup-manager::dashboard.storage_intro') }}</p>

    @if(session('storage_success'))<div class="alert success">{{ session('storage_success') }}</div>@endif
    @if(session('storage_error'))<div class="alert error" role="alert">{{ session('storage_error') }}</div>@endif
    @if($errors->any())<div class="alert error" role="alert"><strong>{{ __('backup-manager::dashboard.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <section class="storage-card">
        <h2>{{ __('backup-manager::dashboard.google_drive') }}</h2>
        <p>{{ $googleConnected ? __('backup-manager::dashboard.google_connected') : __('backup-manager::dashboard.google_connect_prompt') }}</p>
        <form method="POST" action="{{ route('backup-manager.storage.google.credentials') }}">
            @csrf
            <label class="storage-field"><strong>{{ __('backup-manager::dashboard.oauth_client_id') }}</strong><input type="text" name="client_id" value="{{ old('client_id', data_get($settings, 'disks.google-drive.client_id')) }}" required autocomplete="off"></label>
            <label class="storage-field"><strong>{{ __('backup-manager::dashboard.oauth_client_secret') }}</strong><input type="password" name="client_secret" value="{{ old('client_secret') }}" placeholder="{{ data_get($settings, 'disks.google-drive.client_secret') ? __('backup-manager::dashboard.saved_secret') : '' }}" {{ data_get($settings, 'disks.google-drive.client_secret') ? '' : 'required' }} autocomplete="new-password"></label>
            <label class="storage-field"><strong>Google Drive klasör kimliği</strong><input type="text" name="root_folder_id" value="{{ old('root_folder_id', data_get($settings, 'disks.google-drive.root_folder_id', 'root')) }}" placeholder="root"><small>Drive'da yedeklerin tutulacağı klasörün kimliğini gir. Boş bırakırsan Drive kök dizini kullanılır.</small></label>
            <p><strong>{{ __('backup-manager::dashboard.redirect_uri') }}:</strong><br><code>{{ $callbackUrl }}</code></p>
            <div class="storage-actions"><button class="primary" type="submit">{{ __('backup-manager::dashboard.save_credentials') }}</button></div>
        </form>
        <div class="storage-actions" style="margin-top:14px">
            <form method="POST" action="{{ route('backup-manager.storage.google.connect') }}">@csrf<button class="primary" type="submit">{{ __('backup-manager::dashboard.connect_drive') }}</button></form>
            @if($googleConnected)
                <form method="POST" action="{{ route('backup-manager.storage.google.test') }}">@csrf<button type="submit">{{ __('backup-manager::dashboard.test_connection') }}</button></form>
            @endif
        </div>
        <p>{{ __('backup-manager::dashboard.google_setup') }}</p>
    </section>

    <section class="storage-card">
        <h2>Yedekleme hedefleri</h2>
        <p>Oluşturulan her yedek, burada seçtiğin depolama alanlarının tamamına yüklenir. Yalnızca Google Drive kullanmak istiyorsan sadece Google Drive kutusunu işaretle.</p>
        <form method="POST" action="{{ route('backup-manager.storage.targets') }}">
            @csrf
            <div class="storage-target-list">
                @foreach($filesystemDisks as $disk)
                    <label class="storage-field">
                        <span><input type="checkbox" name="targets[]" value="{{ $disk }}" {{ in_array($disk, old('targets', $selectedTargets), true) ? 'checked' : '' }}> <strong>{{ $disk === 'google-drive' ? 'Google Drive' : $disk }}</strong></span>
                    </label>
                @endforeach
            </div>
            <div class="storage-actions"><button class="primary" type="submit">Seçili hedefleri kaydet</button></div>
        </form>
    </section>

    <section class="storage-card">
        <h2>{{ __('backup-manager::dashboard.automatic_backups') }}</h2>
        <p>{{ __('backup-manager::dashboard.schedule_intro') }}</p>
        <form method="POST" action="{{ route('backup-manager.storage.schedule') }}">
            @csrf
            <label class="storage-field">
                <strong>{{ __('backup-manager::dashboard.automatic_backup') }}</strong>
                <span><input type="checkbox" name="enabled" value="1" {{ ($schedule['enabled'] ?? false) ? 'checked' : '' }}> {{ __('backup-manager::dashboard.enable_schedule') }}</span>
            </label>
            <label class="storage-field">
                <strong>{{ __('backup-manager::dashboard.run_time') }}</strong>
                <input type="time" name="time" value="{{ old('time', $schedule['time'] ?? '02:00') }}" required>
            </label>
            <label class="storage-field">
                <strong>{{ __('backup-manager::dashboard.timezone') }}</strong>
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
                <strong>{{ __('backup-manager::dashboard.retention_days') }}</strong>
                <input type="number" name="retain_days" min="1" max="3650" value="{{ old('retain_days', $schedule['retain_days'] ?? 30) }}" required>
                <small>{{ __('backup-manager::dashboard.retention_help') }}</small>
            </label>
            <div class="storage-actions"><button class="primary" type="submit">{{ __('backup-manager::dashboard.save_schedule') }}</button></div>
        </form>
        <p><strong>{{ __('backup-manager::dashboard.cron_requirement') }}:</strong> <code>* * * * * php /path/to/your-project/artisan schedule:run</code></p>
    </section>

    <section class="storage-card">
        <h2>{{ __('backup-manager::dashboard.filesystem_disks') }}</h2>
        <p>{{ __('backup-manager::dashboard.filesystem_intro') }}</p>
        <ul>
            @foreach($filesystemDisks as $disk)
                <li style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                    <code>{{ $disk }}</code>
                    <form method="POST" action="{{ route('backup-manager.storage.test') }}">
                        @csrf
                        <input type="hidden" name="disk" value="{{ $disk }}">
                        <button type="submit">{{ __('backup-manager::dashboard.test_permissions') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
        <p>{{ __('backup-manager::dashboard.test_help') }}</p>
    </section>
</div>
<script src="{{ asset('vendor/backup-manager/js/dashboard.js') }}?v={{ config('backup-manager.ui.asset_version', '0.8.7') }}-0.8.7" defer></script>
</body>
</html>
