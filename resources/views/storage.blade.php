<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('backup-manager::dashboard.storage_title') }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.12') }}-0.8.12">

</head>
<body>
<div class="shell storage-page">
    <div class="top-actions"><a class="storage-back" href="{{ route('backup-manager.dashboard') }}">← {{ __('backup-manager::dashboard.back') }}</a><select class="toolbar-select" aria-label="{{ __('backup-manager::dashboard.language') }}" data-locale-switcher>@foreach(['en'=>'English','tr'=>'Türkçe','es'=>'Español','zh_CN'=>'简体中文','hi'=>'हिन्दी','ar'=>'العربية'] as $locale => $label)<option value="{{ route('backup-manager.locale', ['locale' => $locale]) }}" {{ app()->getLocale() === $locale ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
    <h1>{{ __('backup-manager::dashboard.storage_title') }}</h1>
    <p>{{ __('backup-manager::dashboard.storage_intro') }}</p>

    @if(session('storage_success'))<div class="alert success">{{ session('storage_success') }}</div>@endif
    @if(session('storage_error'))<div class="alert error" role="alert">{{ session('storage_error') }}</div>@endif
    @if($errors->any())<div class="alert error" role="alert"><strong>{{ __('backup-manager::dashboard.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <section class="storage-card storage-card--google">
        <div class="storage-card-heading">
            <span class="storage-card-icon storage-card-icon--google" aria-hidden="true"><svg viewBox="0 0 48 48"><path fill="#00832d" d="M15.4 6h10.2L10.2 32.7H0z"/><path fill="#2684fc" d="M10.2 32.7h30.6L35.7 42H5.1z"/><path fill="#ffba00" d="M25.6 6 48 42H35.7L15.4 6z"/></svg></span>
            <div><span class="storage-card-kicker">BULUT DEPOLAMA</span><h2>{{ __('backup-manager::dashboard.google_drive') }}</h2><p>{{ $googleConnected ? __('backup-manager::dashboard.google_connected') : __('backup-manager::dashboard.google_connect_prompt') }}</p></div>
            <span class="storage-connection {{ $googleConnected ? 'is-connected' : 'is-disconnected' }}"><i></i>{{ $googleConnected ? 'Bağlı' : 'Bağlantı bekleniyor' }}</span>
        </div>
        <form method="POST" action="{{ route('backup-manager.storage.google.credentials') }}">
            @csrf
            <label class="storage-field storage-field--wide"><strong>{{ __('backup-manager::dashboard.oauth_client_id') }}</strong><input type="text" name="client_id" value="{{ old('client_id', data_get($settings, 'disks.google-drive.client_id')) }}" required autocomplete="off"></label>
            <label class="storage-field storage-field--wide"><strong>{{ __('backup-manager::dashboard.oauth_client_secret') }}</strong><input type="password" name="client_secret" value="{{ old('client_secret') }}" placeholder="{{ data_get($settings, 'disks.google-drive.client_secret') ? __('backup-manager::dashboard.saved_secret') : '' }}" {{ data_get($settings, 'disks.google-drive.client_secret') ? '' : 'required' }} autocomplete="new-password"></label>
            <label class="storage-field storage-field--wide"><strong>Google Drive klasör kimliği</strong><input type="text" name="root_folder_id" value="{{ old('root_folder_id', data_get($settings, 'disks.google-drive.root_folder_id', 'root')) }}" placeholder="root"><small>Drive'da yedeklerin tutulacağı klasörün kimliğini gir. Boş bırakırsan Drive kök dizini kullanılır.</small></label>
            <div class="storage-redirect"><strong>{{ __('backup-manager::dashboard.redirect_uri') }}</strong><code>{{ $callbackUrl }}</code></div>
            <div class="storage-actions"><button class="primary" type="submit">{{ __('backup-manager::dashboard.save_credentials') }}</button></div>
        </form>
        <div class="storage-actions storage-actions--connection">
            <form method="POST" action="{{ route('backup-manager.storage.google.connect') }}">@csrf<button class="primary" type="submit">{{ __('backup-manager::dashboard.connect_drive') }}</button></form>
            @if($googleConnected)
                <form method="POST" action="{{ route('backup-manager.storage.google.test') }}">@csrf<button type="submit">{{ __('backup-manager::dashboard.test_connection') }}</button></form>
            @endif
        </div>
        <div class="storage-help">{{ __('backup-manager::dashboard.google_setup') }}</div>
    </section>

    <section class="storage-card destination-section">
        <div class="destination-heading">
            <div class="destination-heading-copy">
                <span class="destination-eyebrow">DEPOLAMA AYARLARI</span>
                <h2>Yedekleme hedefleri</h2>
                <p>Yedeklerinin saklanacağı konumları seç. Birden fazla hedef etkinleştirerek aynı yedeği birden fazla yere gönderebilirsin.</p>
            </div>
            <div class="destination-summary" aria-live="polite">
                <span class="destination-summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12.1V21"/></svg></span>
                <span class="destination-summary-copy"><strong id="destination-selected-count">{{ count($selectedTargets) }}</strong><small>aktif hedef</small></span>
            </div>
        </div>

        <form method="POST" action="{{ route('backup-manager.storage.targets') }}" class="destination-form">
            @csrf
            <div class="destination-section-label">
                <span>YEDEKLER NEREYE GÖNDERİLSİN?</span>
                <small>İstediğin kadar hedef seçebilirsin</small>
            </div>
            <div class="destination-grid">
                @foreach($filesystemDisks as $disk)
                    @php
                        $isGoogleDrive = $disk === 'google-drive';
                        $isLocal = in_array($disk, ['local', 'public'], true);
                        $isSelected = in_array($disk, $selectedTargets, true);
                        $destinationName = $isGoogleDrive ? 'Google Drive' : ($disk === 'local' ? 'Sunucu diski' : ($disk === 'public' ? 'Public disk' : \Illuminate\Support\Str::headline($disk)));
                        $destinationDescription = $isGoogleDrive
                            ? 'Google hesabındaki depolama alanına yedek kopyası gönderir.'
                            : ($isLocal ? 'Yedekleri bu uygulamanın çalıştığı sunucuda tutar.' : 'Yapılandırılmış uzak depolama alanına yedek gönderir.');
                    @endphp
                    <label class="destination-option {{ $isSelected ? 'is-selected' : '' }}" for="backup-target-{{ $loop->index }}">
                        <input id="backup-target-{{ $loop->index }}" type="checkbox" name="targets[]" value="{{ $disk }}" {{ $isSelected ? 'checked' : '' }}>
                        <span class="destination-icon {{ $isGoogleDrive ? 'destination-icon-google' : ($isLocal ? 'destination-icon-local' : 'destination-icon-cloud') }}" aria-hidden="true">
                            @if($isGoogleDrive)
                                <svg viewBox="0 0 48 48"><path fill="#00832d" d="M15.4 6h10.2L10.2 32.7H0z"/><path fill="#2684fc" d="M10.2 32.7h30.6L35.7 42H5.1z"/><path fill="#ffba00" d="M25.6 6 48 42H35.7L15.4 6z"/></svg>
                            @elseif($isLocal)
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01M17 7h1M17 17h1"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3 3.5 7.5v9L12 21l8.5-4.5v-9L12 3Z"/><path d="m3.8 7.7 8.2 4.5 8.2-4.5M12 12.2V21"/></svg>
                            @endif
                        </span>
                        <span class="destination-copy">
                            <strong>{{ $destinationName }}</strong>
                            <small>{{ $destinationDescription }}</small>
                        </span>
                        <span class="destination-state"><span class="destination-state-dot"></span><span class="destination-state-text">{{ $isSelected ? 'Seçildi' : 'Seç' }}</span></span>
                        <span class="destination-check" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none"><path d="m4 10 4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    </label>
                @endforeach
            </div>

            <div class="destination-footer">
                <div class="destination-note">
                    <span class="destination-note-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></span>
                    <span><strong>Bilmen gereken</strong><small>Bu seçim bundan sonra oluşturulacak yedeklere uygulanır. Eski yedekler taşınmaz.</small></span>
                </div>
                <button class="primary destination-save" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h12l3 3v13H4V4h1Z"/><path d="M8 4v6h8V4M8 20v-7h8v7"/></svg> Hedefleri kaydet</button>
            </div>
        </form>
    </section>

    <section class="storage-card storage-card--schedule">
        <div class="storage-card-heading">
            <span class="storage-card-icon storage-card-icon--schedule" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span>
            <div><span class="storage-card-kicker">ZAMANLAMA VE SAKLAMA</span><h2>{{ __('backup-manager::dashboard.automatic_backups') }}</h2><p>{{ __('backup-manager::dashboard.schedule_intro') }}</p></div>
            <span class="storage-connection {{ ($schedule['enabled'] ?? false) ? 'is-connected' : 'is-disconnected' }}"><i></i>{{ ($schedule['enabled'] ?? false) ? 'Etkin' : 'Duraklatıldı' }}</span>
        </div>
        <form method="POST" action="{{ route('backup-manager.storage.schedule') }}" class="schedule-form">
            @csrf
            <label class="storage-field schedule-toggle-field">
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
            <div class="storage-actions schedule-actions"><button class="primary" type="submit">{{ __('backup-manager::dashboard.save_schedule') }}</button></div>
        </form>
        <div class="storage-cron"><span class="storage-cron-icon">⌘</span><div><strong>{{ __('backup-manager::dashboard.cron_requirement') }}</strong><small>Laravel zamanlayıcısının her dakika çalışması gerekir.</small><code>* * * * * php /path/to/your-project/artisan schedule:run</code></div></div>
    </section>

    <section class="storage-card storage-card--disks">
        <div class="storage-card-heading">
            <span class="storage-card-icon storage-card-icon--disks" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01M17 7h1M17 17h1"/></svg></span>
            <div><span class="storage-card-kicker">LARAVEL STORAGE</span><h2>{{ __('backup-manager::dashboard.filesystem_disks') }}</h2><p>{{ __('backup-manager::dashboard.filesystem_intro') }}</p></div>
        </div>
        <ul class="filesystem-disk-list">
            @foreach($filesystemDisks as $disk)
                <li class="filesystem-disk">
                    <span class="filesystem-disk-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3"/></svg></span>
                    <span class="filesystem-disk-name"><strong>{{ $disk }}</strong><small>Laravel filesystem diski</small></span>
                    <form method="POST" action="{{ route('backup-manager.storage.test') }}">
                        @csrf
                        <input type="hidden" name="disk" value="{{ $disk }}">
                        <button class="disk-test-button" type="submit"><span>✓</span>{{ __('backup-manager::dashboard.test_permissions') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
        <div class="storage-help">{{ __('backup-manager::dashboard.test_help') }}</div>
    </section>
</div>
<script src="{{ asset('vendor/backup-manager/js/dashboard.js') }}?v={{ config('backup-manager.ui.asset_version', '0.8.12') }}-0.8.12" defer></script>
</body>
</html>
