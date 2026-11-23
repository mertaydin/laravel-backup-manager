<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('backup-manager::dashboard.details_title') }} · {{ $backup['id'] }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.7') }}-0.8.7">
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
<script src="{{ asset('vendor/backup-manager/js/dashboard.js') }}?v={{ config('backup-manager.ui.asset_version', '0.8.7') }}-0.8.7" defer></script>
</body>
</html>
