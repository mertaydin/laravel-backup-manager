<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('backup-manager::dashboard.details_title') }} · {{ $backup['id'] }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.17') }}-0.8.17">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('backup-manager.dashboard') }}"><strong>Backup Manager</strong></a>
        <div class="top-actions"><select class="toolbar-select" aria-label="{{ __('backup-manager::dashboard.language') }}" data-locale-switcher>@foreach(['en'=>'English','tr'=>'Türkçe','es'=>'Español','zh_CN'=>'简体中文','hi'=>'हिन्दी','ar'=>'العربية'] as $locale => $label)<option value="{{ route('backup-manager.locale', ['locale' => $locale]) }}" {{ app()->getLocale() === $locale ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select><a class="theme-button" href="{{ route('backup-manager.dashboard') }}">← {{ __('backup-manager::dashboard.back') }}</a></div>
    </header>
    <section class="hero">
        <div><div class="eyebrow">{{ __('backup-manager::dashboard.details_title') }}</div><h1>{{ ($backup['label'] ?? '') ?: $backup['id'] }}</h1><p>{{ $backup['id'] }} · {{ $backup['created_at'] ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d M Y H:i:s T') : __('backup-manager::dashboard.date_unavailable') }}</p></div>
        <div class="profile-pill">{{ $backup['disk'] }} · {{ number_format(($backup['total_size'] ?? 0) / 1048576, 2) }} MB</div>
    </section>
    <section class="panel details-panel">
        <h2>{{ __('backup-manager::dashboard.metadata') }}</h2>
        <div class="storage-body">
            <div class="storage-row"><span>{{ __('backup-manager::dashboard.profile') }}</span><strong>{{ $backup['profile'] }}</strong></div>
            <div class="storage-row"><span>{{ __('backup-manager::dashboard.type') }}</span><strong>{{ ucfirst($backup['type'] ?? 'manual') }}</strong></div>
            <div class="storage-row"><span>{{ __('backup-manager::dashboard.encryption') }}</span><strong>{{ ($backup['encrypted'] ?? false) ? 'Enabled' : 'Disabled' }}</strong></div>
            <div class="storage-row"><span>{{ __('backup-manager::dashboard.note') }}</span><strong>{{ $backup['note'] ?: '—' }}</strong></div>
            <div class="storage-row"><span>{{ __('backup-manager::dashboard.tags') }}</span><strong>{{ implode(', ', $backup['tags'] ?? []) ?: '—' }}</strong></div>
            <div class="storage-row"><span>{{ __('backup-manager::dashboard.protection_status') }}</span><strong>{{ ($backup['protected'] ?? false) ? __('backup-manager::dashboard.protected') : __('backup-manager::dashboard.unprotected') }}</strong></div>
            @if(!empty($backup['protection_reason']))<div class="storage-row"><span>{{ __('backup-manager::dashboard.protection_reason') }}</span><strong>{{ $backup['protection_reason'] }}</strong></div>@endif
            @if(!empty($backup['protected_until']))<div class="storage-row"><span>{{ __('backup-manager::dashboard.protection_until') }}</span><strong>{{ $backup['protected_until'] }}</strong></div>@endif
        </div>
        <div class="details-actions">
            @if($backup['protected'] ?? false)
                <form method="POST" action="{{ route('backup-manager.protection', $backup['id']) }}">@csrf<input type="hidden" name="unlock" value="1"><button class="protection-button protection-button--unlock" type="submit">{{ __('backup-manager::dashboard.unlock_backup') }}</button></form>
            @else
                <form class="protection-form" method="POST" action="{{ route('backup-manager.protection', $backup['id']) }}">
                    @csrf
                    <input class="protection-input" type="text" name="reason" maxlength="255" placeholder="{{ __('backup-manager::dashboard.protection_reason_placeholder') }}" aria-label="{{ __('backup-manager::dashboard.protection_reason') }}">
                    <input class="protection-input" type="datetime-local" name="protected_until" aria-label="{{ __('backup-manager::dashboard.protection_until_optional') }}" title="{{ __('backup-manager::dashboard.protection_until_optional') }}">
                    <button class="protection-button" type="submit">{{ __('backup-manager::dashboard.protect_backup') }}</button>
                </form>
            @endif
        </div>
        <h2 class="details-section-title">{{ __('backup-manager::dashboard.artifacts') }}</h2>
        @foreach($backup['artifacts'] as $artifact)
            <div class="storage-row details-artifact">
                <strong>{{ $artifact['name'] }}</strong>
                <div>{{ number_format($artifact['size'] / 1048576, 2) }} MB</div>
                <code class="details-checksum">{{ $artifact['sha256'] }}</code>
                <div class="details-download"><a class="action-button" href="{{ route('backup-manager.download', ['id' => $backup['id'], 'artifact' => $artifact['name']]) }}">{{ __('backup-manager::dashboard.download_artifact') }}</a></div>
            </div>
        @endforeach
        <div class="details-actions">
            <a class="action-button" href="{{ route('backup-manager.download', ['id' => $backup['id'], 'artifact' => 'manifest.json']) }}">{{ __('backup-manager::dashboard.download_manifest') }}</a>
            <form method="POST" action="{{ route('backup-manager.verify', $backup['id']) }}">@csrf<button class="verify-button" type="submit">{{ __('backup-manager::dashboard.verify_integrity') }}</button></form>
        </div>
    </section>
</div>
<script src="{{ asset('vendor/backup-manager/js/dashboard.js') }}?v={{ config('backup-manager.ui.asset_version', '0.8.17') }}-0.8.17" defer></script>

<div class="backup-manager-loading" id="backup-manager-loading" role="status" aria-live="polite" aria-hidden="true" data-default-title="İşlem gerçekleştiriliyor" data-default-help="Lütfen işlem tamamlanana kadar bekleyin.">
    <div class="backup-manager-loading__dialog">
        <span class="backup-manager-loading__spinner" aria-hidden="true"></span>
        <h2 data-loading-title>İşlem gerçekleştiriliyor</h2>
        <p data-loading-help>Lütfen işlem tamamlanana kadar bekleyin.</p>
        <div class="backup-manager-loading__track" aria-hidden="true"><span></span></div>
    </div>
</div>

<style>
.backup-manager-loading{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(3,8,20,.76);backdrop-filter:blur(5px);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,visibility .18s ease}
.backup-manager-loading.is-visible{opacity:1;visibility:visible;pointer-events:auto}
.backup-manager-loading__dialog{width:min(430px,100%);padding:30px 28px;text-align:center;background:var(--panel,#172033);border:1px solid var(--line,#344054);border-radius:17px;box-shadow:0 24px 80px #0008}
.backup-manager-loading__dialog h2{margin:17px 0 6px;font-size:17px;color:var(--text,#fff)}
.backup-manager-loading__dialog p{margin:0;color:var(--muted,#aab4c5);font-size:12px;line-height:1.6}
.backup-manager-loading__spinner{display:block;width:39px;height:39px;margin:0 auto;border:3px solid var(--line,#344054);border-top-color:var(--blue,#5488ff);border-radius:50%;animation:backup-manager-spin .8s linear infinite}
.backup-manager-loading__track{height:5px;margin-top:23px;overflow:hidden;border-radius:5px;background:var(--line,#344054)}
.backup-manager-loading__track span{display:block;width:38%;height:100%;border-radius:5px;background:var(--blue,#5488ff);animation:backup-manager-progress 1.25s ease-in-out infinite alternate}
@keyframes backup-manager-spin{to{transform:rotate(360deg)}}
@keyframes backup-manager-progress{from{transform:translateX(-105%)}to{transform:translateX(270%)}}
@media(prefers-reduced-motion:reduce){.backup-manager-loading__spinner,.backup-manager-loading__track span{animation-duration:2.5s}}
</style>
<script>
(function () {
    var overlay = document.getElementById('backup-manager-loading');
    if (!overlay) return;
    var title = overlay.querySelector('[data-loading-title]');
    var help = overlay.querySelector('[data-loading-help]');
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || (form.method || 'get').toLowerCase() !== 'post') return;
        if (event.defaultPrevented) return;
        if (form.dataset.loading === 'true') { event.preventDefault(); return; }
        form.dataset.loading = 'true';
        if (title) title.textContent = form.getAttribute('data-loading-title') || overlay.dataset.defaultTitle;
        if (help) help.textContent = form.getAttribute('data-loading-help') || overlay.dataset.defaultHelp;
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
    });
})();
</script>
</body>
</html>
