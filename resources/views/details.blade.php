<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup details · {{ $backup['id'] }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.7.0') }}">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('backup-manager.dashboard') }}"><strong>Backup Manager</strong></a>
        <a class="theme-button" href="{{ route('backup-manager.dashboard') }}">← Back to dashboard</a>
    </header>
    <section class="hero">
        <div><div class="eyebrow">Backup details</div><h1>{{ $backup['label'] ?: $backup['id'] }}</h1><p>{{ $backup['id'] }} · {{ $backup['created_at'] ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d M Y H:i:s T') : 'Date unavailable' }}</p></div>
        <div class="profile-pill">{{ $backup['disk'] }} · {{ number_format(($backup['total_size'] ?? 0) / 1048576, 2) }} MB</div>
    </section>
    <section class="panel" style="padding:24px;margin-top:24px">
        <h2>Metadata</h2>
        <div class="storage-body">
            <div class="storage-row"><span>Profile</span><strong>{{ $backup['profile'] }}</strong></div>
            <div class="storage-row"><span>Type</span><strong>{{ ucfirst($backup['type'] ?? 'manual') }}</strong></div>
            <div class="storage-row"><span>Encryption</span><strong>{{ ($backup['encrypted'] ?? false) ? 'Enabled' : 'Disabled' }}</strong></div>
            <div class="storage-row"><span>Note</span><strong>{{ $backup['note'] ?: '—' }}</strong></div>
            <div class="storage-row"><span>Tags</span><strong>{{ implode(', ', $backup['tags'] ?? []) ?: '—' }}</strong></div>
        </div>
        <h2 style="margin-top:28px">Artifacts and SHA-256 checksums</h2>
        @foreach($backup['artifacts'] as $artifact)
            <div class="storage-row" style="display:block;padding:14px 0">
                <strong>{{ $artifact['name'] }}</strong>
                <div>{{ number_format($artifact['size'] / 1048576, 2) }} MB</div>
                <code style="overflow-wrap:anywhere">{{ $artifact['sha256'] }}</code>
                <div style="margin-top:8px"><a class="action-button" href="{{ route('backup-manager.download', ['id' => $backup['id'], 'artifact' => $artifact['name']]) }}">Download artifact</a></div>
            </div>
        @endforeach
        <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
            <a class="action-button" href="{{ route('backup-manager.download', ['id' => $backup['id'], 'artifact' => 'manifest.json']) }}">Download manifest</a>
            <form method="POST" action="{{ route('backup-manager.verify', $backup['id']) }}">@csrf<button class="verify-button" type="submit">Verify integrity</button></form>
        </div>
    </section>
</div>
<script src="{{ asset('vendor/backup-manager/js/dashboard.js') }}?v={{ config('backup-manager.ui.asset_version', '0.7.0') }}" defer></script>
</body>
</html>
