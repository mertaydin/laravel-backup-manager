<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Backup Manager · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.2') }}-0.8.2">
</head>
<body>
<div class="shell">
    @if(session('backup_job_id'))<div class="job-status" id="backup-job-status" role="status" data-status-url="{{ route('backup-manager.jobs.status', ['jobId' => session('backup_job_id')]) }}" data-running="{{ __('backup-manager::dashboard.backup_running') }}" data-completed="{{ __('backup-manager::dashboard.backup_completed') }}" data-failed="{{ __('backup-manager::dashboard.backup_failed') }}">{{ __('backup-manager::dashboard.backup_queued') }}</div>@endif
    <header class="topbar">
        <a class="brand" href="{{ route('backup-manager.dashboard') }}">
            <span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 5 6v5c0 4.5 2.8 7.8 7 10 4.2-2.2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>
            <span><strong>Backup Manager</strong><span>{{ __('backup-manager::dashboard.brand_subtitle') }}</span></span>
        </a>
        <div class="top-actions">
            <a class="theme-button" href="{{ route('backup-manager.storage') }}">Storage Management</a>
            <div class="top-meta"><i class="pulse"></i> {{ __('backup-manager::dashboard.control_panel') }} <span>·</span> {{ now()->format('d M Y') }}</div>
            <form method="GET" action="{{ route('backup-manager.locale', ['locale' => app()->getLocale()]) }}" id="language-form">
                <label for="dashboard-language" class="sr-only">{{ __('backup-manager::dashboard.language') }}</label>
                <select id="dashboard-language" class="toolbar-select" aria-label="{{ __('backup-manager::dashboard.language') }}" onchange="window.location.href=this.value">
                    @foreach(['en'=>'English','tr'=>'Türkçe','es'=>'Español','zh_CN'=>'简体中文','hi'=>'हिन्दी','ar'=>'العربية'] as $locale => $label)
                        <option value="{{ route('backup-manager.locale', ['locale' => $locale]) }}" {{ app()->getLocale() === $locale ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            <button type="button" class="theme-button" id="theme-toggle" aria-label="{{ __('backup-manager::dashboard.theme') }}" data-theme-dark="{{ __('backup-manager::dashboard.theme_dark') }}" data-theme-light="{{ __('backup-manager::dashboard.theme_light') }}" data-theme-system="{{ __('backup-manager::dashboard.theme_system') }}">◐ <span id="theme-label">{{ __('backup-manager::dashboard.theme_dark') }}</span></button>
        </div>
    </header>

    <section class="hero">
        <div><div class="eyebrow">{{ __('backup-manager::dashboard.overview') }}</div><h1>{{ __('backup-manager::dashboard.dashboard_title') }}</h1><p>{{ __('backup-manager::dashboard.dashboard_intro') }}</p></div>
        <div class="profile-pill">◈ &nbsp; {{ $profileName }} {{ __('backup-manager::dashboard.profile') }} &nbsp;·&nbsp; {{ $diskName }}</div>
    </section>

    @if(session('backup_success'))<div class="alert success">✓ &nbsp; {{ session('backup_success') }}</div>@endif
    @if(session('backup_error'))<div class="alert error" role="alert">! &nbsp; {{ session('backup_error') }}</div>@endif
    @if($errors->any())<div class="alert error" role="alert"><strong>{{ __('backup-manager::dashboard.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <section class="stats">
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.total_backups') }} <span class="stat-icon">▤</span></div><div class="stat-value">{{ number_format($totalBackups) }}</div><div class="stat-note">{{ __('backup-manager::dashboard.stored_sets') }}</div></article>
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.total_storage') }} <span class="stat-icon">◫</span></div><div class="stat-value">{{ number_format($totalSize / 1048576, 1) }} <small class="stat-unit">MB</small></div><div class="stat-note">{{ __('backup-manager::dashboard.across_artifacts') }}</div></article>
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.latest_backup') }} <span class="stat-icon">◷</span></div><div class="stat-value stat-value--compact">{{ $latestBackup && $latestBackup['created_at'] ? \Illuminate\Support\Carbon::parse($latestBackup['created_at'])->diffForHumans() : '—' }}</div><div class="stat-note">{{ __('backup-manager::dashboard.most_recent') }}</div></article>
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.storage_status') }} <span class="stat-icon">⌁</span></div><div class="stat-value stat-value--compact"><span class="status">{{ __('backup-manager::dashboard.configured') }}</span></div><div class="stat-note">{{ $diskName }} {{ __('backup-manager::dashboard.configured_disk') }}</div></article>
    </section>

    <section class="content">
        <div class="panel">
            <div class="panel-head"><div><h2>{{ __('backup-manager::dashboard.recent_backups') }}</h2><p>{{ __('backup-manager::dashboard.history_subtitle') }}</p></div><span class="count">{{ $totalBackups }} {{ __('backup-manager::dashboard.records') }}</span></div>
            <form method="GET" action="{{ route('backup-manager.dashboard') }}" class="backup-filters">
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search ID, label, note or tag" aria-label="Search backups">
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="From date">
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="To date">
                <select name="type" aria-label="Backup type"><option value="">All types</option><option value="manual" {{ ($filters['type'] ?? '') === 'manual' ? 'selected' : '' }}>Manual</option><option value="scheduled" {{ ($filters['type'] ?? '') === 'scheduled' ? 'selected' : '' }}>Scheduled</option></select>
                <select name="status" aria-label="Backup status"><option value="">All statuses</option><option value="available" {{ ($filters['status'] ?? '') === 'available' ? 'selected' : '' }}>Available</option><option value="invalid" {{ ($filters['status'] ?? '') === 'invalid' ? 'selected' : '' }}>Invalid manifest</option></select>
                <select name="disk" aria-label="Storage disk"><option value="">All disks</option>@foreach($availableBackupDisks as $availableDisk)<option value="{{ $availableDisk }}" {{ ($filters['disk'] ?? '') === $availableDisk ? 'selected' : '' }}>{{ $availableDisk }}</option>@endforeach</select>
                <button class="action-button" type="submit">Filter</button>
                <a class="action-button" href="{{ route('backup-manager.dashboard') }}">Clear</a>
            </form>
            <div class="backup-list">
            @forelse($backups->take(12) as $backup)
                <article class="backup">
                    <div class="file-icon">▤</div>
                    <div class="backup-main"><div class="backup-title"><a href="{{ route('backup-manager.details', $backup['id']) }}">{{ ($backup['label'] ?? '') ?: $backup['id'] }}</a></div><div class="backup-sub">{{ $backup['id'] }} &nbsp;·&nbsp; {{ $backup['created_at'] ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d M Y · H:i:s') : __('backup-manager::dashboard.invalid_date') }} &nbsp;·&nbsp; {{ $backup['profile'] }}</div><div class="artifacts">@foreach($backup['artifacts'] as $artifact)<span class="artifact">{{ $artifact['name'] }}</span>@endforeach
                    @if($backup['encrypted'] ?? false)<span class="artifact">{{ __('backup-manager::dashboard.encrypted') }}</span>@endif
                    @foreach(($backup['tags'] ?? []) as $tag)<span class="artifact">{{ $tag }}</span>@endforeach
                    @if(!empty($backup['note']))<div class="backup-sub">{{ $backup['note'] }}</div>@endif</div></div>
                    <div class="backup-size">{{ number_format($backup['size'] / 1048576, 2) }} MB</div>
                    <div class="backup-actions">
                        @foreach($backup['artifacts'] as $artifact)
                            <a class="action-button" href="{{ route('backup-manager.download', ['id' => $backup['id'], 'artifact' => $artifact['name']]) }}">↓ {{ $artifact['name'] }}</a>
                        @endforeach
                        <a class="action-button" href="{{ route('backup-manager.download', ['id' => $backup['id'], 'artifact' => 'manifest.json']) }}">↓ manifest</a>
                        @if(empty($backup['error']))
                            <form class="verify-form" method="POST" action="{{ route('backup-manager.verify', $backup['id']) }}">@csrf<button class="verify-button" type="submit">✓ {{ __('backup-manager::dashboard.verify') }}</button></form>
                        @endif
                        <form class="delete-form" method="POST" action="{{ route('backup-manager.delete', $backup['id']) }}" data-confirm="{{ __('backup-manager::dashboard.confirm_delete') }}">
                            @csrf @method('DELETE')
                            <button class="delete-button" type="submit">× {{ __('backup-manager::dashboard.delete') }}</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty"><div class="empty-icon">▤</div><h3>{{ __('backup-manager::dashboard.no_backups') }}</h3><p>{{ __('backup-manager::dashboard.first_backup_hint') }}</p></div>
            @endforelse
            </div>
        </div>

        <aside class="side">
            <div class="panel">
                <div class="panel-head"><div><h2>{{ __('backup-manager::dashboard.new_backup') }}</h2><p>{{ __('backup-manager::dashboard.manual_backup') }}</p></div><span class="count">{{ __('backup-manager::dashboard.manual') }}</span></div>
                <form class="run-body" method="POST" action="{{ route('backup-manager.run') }}">
                    @csrf
                    <label class="check"><input type="checkbox" name="database" value="1" {{ $databaseEnabled ? 'checked' : '' }}><span><strong>{{ __('backup-manager::dashboard.database_backup') }}</strong><small>{{ __('backup-manager::dashboard.mysql_compressed') }}</small></span></label>
                    <label class="check"><input type="checkbox" name="files" value="1" {{ $filesEnabled ? 'checked' : '' }}><span><strong>{{ __('backup-manager::dashboard.application_files') }}</strong><small>{{ __('backup-manager::dashboard.zip_exclusions') }}</small></span></label>
                    <label class="storage-field"><strong>Backup label</strong><input type="text" name="label" maxlength="100" placeholder="e.g. Before deployment"></label>
                    <label class="storage-field"><strong>Backup note</strong><textarea name="note" maxlength="500" rows="2" placeholder="Optional backup description"></textarea></label>
                    <label class="storage-field"><strong>Tags (one per line)</strong><textarea name="tags[]" maxlength="40" rows="2" placeholder="production"></textarea></label>
                    <button class="primary" type="submit">＋ &nbsp; {{ __('backup-manager::dashboard.start_backup') }}</button>
                    <p class="hint">{{ __('backup-manager::dashboard.backup_runtime_hint') }}</p>
                </form>
            </div>
            <div class="panel">
                <div class="panel-head"><div><h2>{{ __('backup-manager::dashboard.configuration') }}</h2><p>{{ __('backup-manager::dashboard.active_profile') }}</p></div></div>
                <div class="storage-body">
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.profile') }}</span><strong>{{ $profileName }}</strong></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.storage_disk') }}</span><strong>{{ $diskName }}</strong></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.database') }}</span><strong>{{ $databaseEnabled ? __('backup-manager::dashboard.enabled') : __('backup-manager::dashboard.disabled') }}</strong></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.application_files') }}</span><strong>{{ $filesEnabled ? __('backup-manager::dashboard.enabled') : __('backup-manager::dashboard.disabled') }}</strong></div>
                    <div class="storage-row"><span>Last backup status</span><strong>{{ is_array($lastBackupStatus) ? ucfirst($lastBackupStatus['status'] ?? 'unknown') : 'No recent run' }}</strong></div>
                    @if(is_array($lastBackupStatus) && !empty($lastBackupStatus['finished_at']))<div class="storage-row"><span>Last run finished</span><strong>{{ \Illuminate\Support\Carbon::parse($lastBackupStatus['finished_at'])->diffForHumans() }}</strong></div>@endif
                    <div class="storage-row"><span>Next scheduled backup</span><strong>{{ $nextScheduledBackup ? $nextScheduledBackup->format('d M Y H:i T') : 'Not scheduled' }}</strong></div>
                    <div class="divider"></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.integrity') }}</span><strong>SHA-256</strong></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.manifest') }}</span><strong>JSON</strong></div>
                </div>
            </div>
        </aside>
    </section>
    <footer class="footer"><span>Laravel Backup Manager</span><span>{{ __('backup-manager::dashboard.protect') }}</span></footer>
</div>
<script src="{{ asset('vendor/backup-manager/js/dashboard.js') }}?v={{ config('backup-manager.ui.asset_version', '0.8.2') }}-0.8.2" defer></script>
</body>
</html>
