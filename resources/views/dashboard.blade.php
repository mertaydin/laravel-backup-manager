<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Backup Manager · {{ config('app.name') }}</title>
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('backup-manager-theme') || 'system';
                var theme = saved === 'system'
                    ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
                    : saved;
                document.documentElement.dataset.theme = theme;
            } catch (e) {}
        })();
    </script>
    <style>
        :root{color-scheme:dark;--bg:#0b1020;--panel:#121a2d;--panel2:#172239;--line:#26334d;--muted:#91a0ba;--text:#edf3ff;--blue:#6d8cff;--cyan:#5bd6cf;--green:#47d7a0;--red:#ff7b86;--shadow:#00000017}
        html[data-theme="light"]{color-scheme:light;--bg:#f2f5fb;--panel:#fff;--panel2:#f5f7fc;--line:#dfe5f0;--muted:#65738c;--text:#17233b;--blue:#536fe0;--cyan:#168e91;--green:#159b70;--red:#cf4858;--shadow:#26334d0d}
        *{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 85% -15%,#24345c 0,transparent 38%),var(--bg);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:background .2s,color .2s}
        html[data-theme="light"] body{background:radial-gradient(ellipse at 85% -15%,#dce6ff 0,transparent 40%),var(--bg)}
        a{color:inherit;text-decoration:none}.shell{max-width:1320px;margin:auto;padding:34px 28px 56px}.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:38px;gap:18px}.brand{display:flex;gap:13px;align-items:center}.logo{width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,#7795ff,#4c62c7);display:grid;place-items:center;box-shadow:0 8px 28px #536fdf45}.logo svg{width:23px}.brand strong{display:block;font-size:16px;letter-spacing:.01em}.brand span{color:var(--muted);font-size:12px}.top-actions{display:flex;align-items:center;gap:9px}.top-meta{color:var(--muted);font-size:12px;display:flex;align-items:center;gap:9px}.pulse{width:7px;height:7px;background:var(--green);border-radius:50%;box-shadow:0 0 0 4px #47d7a01a}
        .toolbar-select,.theme-button{border:1px solid var(--line);border-radius:9px;padding:8px 10px;background:var(--panel);color:var(--text);font:inherit;font-size:11px;cursor:pointer}.theme-button{font-weight:650}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:28px;margin-bottom:28px}.eyebrow{color:#829bfa;text-transform:uppercase;font-weight:700;letter-spacing:.15em;font-size:10px}.hero h1{font-size:clamp(28px,4vw,38px);letter-spacing:-.04em;margin:7px 0 8px}.hero p{color:var(--muted);margin:0;max-width:620px}.profile-pill{border:1px solid var(--line);border-radius:999px;padding:8px 13px;color:var(--text);background:var(--panel);font-size:12px;white-space:nowrap}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:22px}.stat,.panel{background:linear-gradient(145deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:17px;box-shadow:0 12px 34px var(--shadow)}.stat{padding:19px 20px}.stat-head{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12px}.stat-icon{width:30px;height:30px;display:grid;place-items:center;border-radius:10px;background:#718cff18;color:#91a8ff}.stat-value{font-size:27px;font-weight:700;letter-spacing:-.04em;margin-top:12px}.stat-note{font-size:11px;color:var(--muted);margin-top:2px}
        .content{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(300px,.85fr);gap:18px;align-items:start}.panel{overflow:hidden}.panel-head{padding:19px 21px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.panel-head h2{font-size:14px;margin:0}.panel-head p{font-size:11px;color:var(--muted);margin:3px 0 0}.count{font-size:11px;color:var(--muted);background:var(--panel2);border:1px solid var(--line);padding:5px 9px;border-radius:99px}
        .backup-list{padding:4px 20px}.backup{display:flex;align-items:center;gap:14px;padding:17px 0;border-bottom:1px solid var(--line)}.backup:last-child{border:0}.file-icon{width:39px;height:43px;border:1px solid #3b4c70;border-radius:9px;background:#202d49;display:grid;place-items:center;color:#9eb1ff;flex:none}.backup-main{min-width:0;flex:1}.backup-title{font-weight:650;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.backup-sub{font-size:11px;color:var(--muted);margin-top:4px}.artifacts{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.artifact{font-size:10px;color:var(--muted);background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:2px 6px}.backup-size{font-size:12px;color:var(--text);white-space:nowrap}.verify-form{margin-inline-start:10px}.verify-button{border:1px solid #3c527e;border-radius:8px;background:#1a2944;color:#b8caff;padding:7px 9px;font-size:10px;font-weight:700;cursor:pointer}.verify-button:hover{background:#263b61}.empty{padding:45px 22px;text-align:center}.empty-icon{width:52px;height:52px;border-radius:16px;background:#7893ff16;color:#91a8ff;display:grid;place-items:center;margin:0 auto 13px}.empty h3{margin:0 0 5px;font-size:14px}.empty p{color:var(--muted);font-size:12px;margin:0}
        .side{display:grid;gap:18px}.run-body{padding:20px}.check{display:flex;align-items:flex-start;gap:11px;padding:12px;border:1px solid var(--line);border-radius:11px;background:var(--panel2);margin-bottom:9px;cursor:pointer}.check input{accent-color:#7e96ff;margin-top:3px;width:15px;height:15px}.check strong{display:block;font-size:12px}.check small{display:block;color:var(--muted);font-size:10px;margin-top:2px}.primary{width:100%;border:0;border-radius:10px;padding:12px 15px;background:linear-gradient(100deg,#6c87f5,#6272dc);color:white;font-weight:700;font-size:12px;cursor:pointer;margin-top:8px;box-shadow:0 8px 22px #536fdf38}.primary:hover{filter:brightness(1.08)}.hint{font-size:10px;color:var(--muted);margin:12px 2px 0;line-height:1.6}
        .storage-body{padding:18px 20px}.storage-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;font-size:12px}.storage-row span:first-child{color:var(--muted)}.storage-row strong{font-weight:600}.divider{height:1px;background:var(--line);margin:6px 0}.status{display:inline-flex;align-items:center;gap:6px;color:var(--green);font-size:11px}.status:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--green)}.alert{padding:12px 14px;border-radius:10px;font-size:12px;margin-bottom:17px}.success{background:#153d35;border:1px solid #286c5c;color:#a6f2d7}.error{background:#48232e;border:1px solid #87404d;color:#ffc0c6}.footer{margin-top:22px;color:var(--muted);font-size:10px;display:flex;justify-content:space-between}
        @media(max-width:900px){.content{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.shell{padding:22px 15px 38px}.topbar{align-items:flex-start}.top-actions{flex-wrap:wrap;justify-content:flex-end}.top-meta{display:none}.hero{display:block}.profile-pill{display:inline-block;margin-top:17px}.stats{gap:9px}.stat{padding:14px}.stat-value{font-size:23px}.backup{gap:10px}.backup-size{font-size:11px}}@media(max-width:420px){.brand span:not(.logo){max-width:145px}.toolbar-select{max-width:105px}.verify-form{margin-inline-start:0}.backup{flex-wrap:wrap}.backup-main{min-width:calc(100% - 60px)}.backup-size{margin-inline-start:53px}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('backup-manager.dashboard') }}">
            <span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 5 6v5c0 4.5 2.8 7.8 7 10 4.2-2.2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>
            <span><strong>Backup Manager</strong><span>{{ __('backup-manager::dashboard.brand_subtitle') }}</span></span>
        </a>
        <div class="top-actions">
            <div class="top-meta"><i class="pulse"></i> {{ __('backup-manager::dashboard.control_panel') }} <span>·</span> {{ now()->format('d M Y') }}</div>
            <form method="GET" action="{{ route('backup-manager.locale', ['locale' => app()->getLocale()]) }}" id="language-form">
                <label for="dashboard-language" class="sr-only">{{ __('backup-manager::dashboard.language') }}</label>
                <select id="dashboard-language" class="toolbar-select" aria-label="{{ __('backup-manager::dashboard.language') }}" onchange="window.location.href=this.value">
                    @foreach(['en'=>'English','tr'=>'Türkçe','es'=>'Español','zh_CN'=>'简体中文','hi'=>'हिन्दी','ar'=>'العربية'] as $locale => $label)
                        <option value="{{ route('backup-manager.locale', ['locale' => $locale]) }}" {{ app()->getLocale() === $locale ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            <button type="button" class="theme-button" id="theme-toggle" aria-label="{{ __('backup-manager::dashboard.theme') }}">◐ <span id="theme-label">{{ __('backup-manager::dashboard.theme_dark') }}</span></button>
        </div>
    </header>

    <section class="hero">
        <div><div class="eyebrow">{{ __('backup-manager::dashboard.overview') }}</div><h1>{{ __('backup-manager::dashboard.dashboard_title') }}</h1><p>{{ __('backup-manager::dashboard.dashboard_intro') }}</p></div>
        <div class="profile-pill">◈ &nbsp; {{ $profileName }} {{ __('backup-manager::dashboard.profile') }} &nbsp;·&nbsp; {{ $diskName }}</div>
    </section>

    @if(session('backup_success'))<div class="alert success">✓ &nbsp; {{ session('backup_success') }}</div>@endif
    @if(session('backup_error'))<div class="alert error">! &nbsp; {{ session('backup_error') }}</div>@endif

    <section class="stats">
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.total_backups') }} <span class="stat-icon">▤</span></div><div class="stat-value">{{ number_format($totalBackups) }}</div><div class="stat-note">{{ __('backup-manager::dashboard.stored_sets') }}</div></article>
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.total_storage') }} <span class="stat-icon">◫</span></div><div class="stat-value">{{ number_format($totalSize / 1048576, 1) }} <small style="font-size:13px;color:var(--muted)">MB</small></div><div class="stat-note">{{ __('backup-manager::dashboard.across_artifacts') }}</div></article>
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.latest_backup') }} <span class="stat-icon">◷</span></div><div class="stat-value" style="font-size:20px">{{ $latestBackup && $latestBackup['created_at'] ? \Illuminate\Support\Carbon::parse($latestBackup['created_at'])->diffForHumans() : '—' }}</div><div class="stat-note">{{ __('backup-manager::dashboard.most_recent') }}</div></article>
        <article class="stat"><div class="stat-head">{{ __('backup-manager::dashboard.storage_status') }} <span class="stat-icon">⌁</span></div><div class="stat-value" style="font-size:20px"><span class="status">{{ __('backup-manager::dashboard.configured') }}</span></div><div class="stat-note">{{ $diskName }} {{ __('backup-manager::dashboard.configured_disk') }}</div></article>
    </section>

    <section class="content">
        <div class="panel">
            <div class="panel-head"><div><h2>{{ __('backup-manager::dashboard.recent_backups') }}</h2><p>{{ __('backup-manager::dashboard.history_subtitle') }}</p></div><span class="count">{{ $totalBackups }} {{ __('backup-manager::dashboard.records') }}</span></div>
            @forelse($backups->take(12) as $backup)
                <div class="backup-list"><article class="backup">
                    <div class="file-icon">▤</div>
                    <div class="backup-main"><div class="backup-title">{{ $backup['id'] }}</div><div class="backup-sub">{{ $backup['created_at'] ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d M Y · H:i:s') : __('backup-manager::dashboard.invalid_date') }} &nbsp;·&nbsp; {{ $backup['profile'] }}</div><div class="artifacts">@foreach($backup['artifacts'] as $artifact)<span class="artifact">{{ $artifact['name'] }}</span>@endforeach
                    @if($backup['encrypted'] ?? false)<span class="artifact">{{ __('backup-manager::dashboard.encrypted') }}</span>@endif</div></div>
                    <div class="backup-size">{{ number_format($backup['size'] / 1048576, 2) }} MB</div>
                    @if(empty($backup['error']))<form class="verify-form" method="POST" action="{{ route('backup-manager.verify', $backup['id']) }}">@csrf<button class="verify-button" type="submit">✓ {{ __('backup-manager::dashboard.verify') }}</button></form>@endif
                </article></div>
            @empty
                <div class="empty"><div class="empty-icon">▤</div><h3>{{ __('backup-manager::dashboard.no_backups') }}</h3><p>{{ __('backup-manager::dashboard.first_backup_hint') }}</p></div>
            @endforelse
        </div>

        <aside class="side">
            <div class="panel">
                <div class="panel-head"><div><h2>{{ __('backup-manager::dashboard.new_backup') }}</h2><p>{{ __('backup-manager::dashboard.manual_backup') }}</p></div><span class="count">{{ __('backup-manager::dashboard.manual') }}</span></div>
                <form class="run-body" method="POST" action="{{ route('backup-manager.run') }}">
                    @csrf
                    <label class="check"><input type="checkbox" name="database" value="1" {{ $databaseEnabled ? 'checked' : '' }}><span><strong>{{ __('backup-manager::dashboard.database_backup') }}</strong><small>{{ __('backup-manager::dashboard.mysql_compressed') }}</small></span></label>
                    <label class="check"><input type="checkbox" name="files" value="1" {{ $filesEnabled ? 'checked' : '' }}><span><strong>{{ __('backup-manager::dashboard.application_files') }}</strong><small>{{ __('backup-manager::dashboard.zip_exclusions') }}</small></span></label>
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
                    <div class="divider"></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.integrity') }}</span><strong>SHA-256</strong></div>
                    <div class="storage-row"><span>{{ __('backup-manager::dashboard.manifest') }}</span><strong>JSON</strong></div>
                </div>
            </div>
        </aside>
    </section>
    <footer class="footer"><span>Laravel Backup Manager</span><span>{{ __('backup-manager::dashboard.protect') }}</span></footer>
</div>
<script>
(function () {
    var button = document.getElementById('theme-toggle');
    var label = document.getElementById('theme-label');
    var labels = {
        dark: @json(__('backup-manager::dashboard.theme_dark')),
        light: @json(__('backup-manager::dashboard.theme_light')),
        system: @json(__('backup-manager::dashboard.theme_system'))
    };
    function currentPreference() {
        try { return localStorage.getItem('backup-manager-theme') || 'system'; }
        catch (e) { return 'system'; }
    }
    function applyTheme(preference) {
        var resolved = preference === 'system'
            ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
            : preference;
        document.documentElement.dataset.theme = resolved;
        label.textContent = labels[preference] || labels.system;
        button.setAttribute('aria-label', labels[preference] || labels.system);
    }
    var preference = currentPreference();
    applyTheme(preference);
    button.addEventListener('click', function () {
        var order = ['system', 'light', 'dark'];
        preference = order[(order.indexOf(preference) + 1) % order.length];
        try { localStorage.setItem('backup-manager-theme', preference); } catch (e) {}
        applyTheme(preference);
    });
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', function () {
            if (preference === 'system') applyTheme('system');
        });
    }
})();
</script>
</body>
</html>
