<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Backup Manager · {{ config('app.name') }}</title>
    <style>
        :root{color-scheme:dark;--bg:#0b1020;--panel:#121a2d;--panel2:#172239;--line:#26334d;--muted:#91a0ba;--text:#edf3ff;--blue:#6d8cff;--cyan:#5bd6cf;--green:#47d7a0;--red:#ff7b86}
        *{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 85% -15%,#24345c 0,transparent 38%),var(--bg);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        a{color:inherit;text-decoration:none}.shell{max-width:1320px;margin:auto;padding:34px 28px 56px}.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:38px}.brand{display:flex;gap:13px;align-items:center}.logo{width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,#7795ff,#4c62c7);display:grid;place-items:center;box-shadow:0 8px 28px #536fdf45}.logo svg{width:23px}.brand strong{display:block;font-size:16px;letter-spacing:.01em}.brand span{color:var(--muted);font-size:12px}.top-meta{color:var(--muted);font-size:12px;display:flex;align-items:center;gap:9px}.pulse{width:7px;height:7px;background:var(--green);border-radius:50%;box-shadow:0 0 0 4px #47d7a01a}
        .hero{display:flex;justify-content:space-between;align-items:flex-end;gap:28px;margin-bottom:28px}.eyebrow{color:#9aafff;text-transform:uppercase;font-weight:700;letter-spacing:.15em;font-size:10px}.hero h1{font-size:clamp(28px,4vw,38px);letter-spacing:-.04em;margin:7px 0 8px}.hero p{color:var(--muted);margin:0;max-width:560px}.profile-pill{border:1px solid var(--line);border-radius:999px;padding:8px 13px;color:#c5d0e5;background:#ffffff08;font-size:12px;white-space:nowrap}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:22px}.stat,.panel{background:linear-gradient(145deg,#151f34ed,#10182aee);border:1px solid var(--line);border-radius:17px;box-shadow:0 12px 34px #00000017}.stat{padding:19px 20px}.stat-head{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12px}.stat-icon{width:30px;height:30px;display:grid;place-items:center;border-radius:10px;background:#718cff18;color:#91a8ff}.stat-value{font-size:27px;font-weight:700;letter-spacing:-.04em;margin-top:12px}.stat-note{font-size:11px;color:var(--muted);margin-top:2px}
        .content{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(300px,.85fr);gap:18px;align-items:start}.panel{overflow:hidden}.panel-head{padding:19px 21px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.panel-head h2{font-size:14px;margin:0}.panel-head p{font-size:11px;color:var(--muted);margin:3px 0 0}.count{font-size:11px;color:#b8c6e1;background:#ffffff0a;border:1px solid var(--line);padding:5px 9px;border-radius:99px}
        .backup-list{padding:4px 20px}.backup{display:flex;align-items:center;gap:14px;padding:17px 0;border-bottom:1px solid #26334d99}.backup:last-child{border:0}.file-icon{width:39px;height:43px;border:1px solid #3b4c70;border-radius:9px;background:#202d49;display:grid;place-items:center;color:#9eb1ff;flex:none}.backup-main{min-width:0;flex:1}.backup-title{font-weight:650;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.backup-sub{font-size:11px;color:var(--muted);margin-top:4px}.artifacts{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.artifact{font-size:10px;color:#aebfe1;background:#ffffff08;border:1px solid #ffffff12;border-radius:5px;padding:2px 6px}.backup-size{font-size:12px;color:#c4d0e5;white-space:nowrap}.verify-form{margin-left:10px}.verify-button{border:1px solid #3c527e;border-radius:8px;background:#1a2944;color:#b8caff;padding:7px 9px;font-size:10px;font-weight:700;cursor:pointer}.verify-button:hover{background:#263b61}.empty{padding:45px 22px;text-align:center}.empty-icon{width:52px;height:52px;border-radius:16px;background:#7893ff16;color:#91a8ff;display:grid;place-items:center;margin:0 auto 13px}.empty h3{margin:0 0 5px;font-size:14px}.empty p{color:var(--muted);font-size:12px;margin:0}
        .side{display:grid;gap:18px}.run-body{padding:20px}.check{display:flex;align-items:flex-start;gap:11px;padding:12px;border:1px solid var(--line);border-radius:11px;background:#ffffff04;margin-bottom:9px;cursor:pointer}.check input{accent-color:#7e96ff;margin-top:3px;width:15px;height:15px}.check strong{display:block;font-size:12px}.check small{display:block;color:var(--muted);font-size:10px;margin-top:2px}.primary{width:100%;border:0;border-radius:10px;padding:12px 15px;background:linear-gradient(100deg,#6c87f5,#6272dc);color:white;font-weight:700;font-size:12px;cursor:pointer;margin-top:8px;box-shadow:0 8px 22px #536fdf38}.primary:hover{filter:brightness(1.08)}.hint{font-size:10px;color:var(--muted);margin:12px 2px 0;line-height:1.6}
        .storage-body{padding:18px 20px}.storage-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;font-size:12px}.storage-row span:first-child{color:var(--muted)}.storage-row strong{font-weight:600}.divider{height:1px;background:var(--line);margin:6px 0}.status{display:inline-flex;align-items:center;gap:6px;color:#72e0b5;font-size:11px}.status:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--green)}.alert{padding:12px 14px;border-radius:10px;font-size:12px;margin-bottom:17px}.success{background:#153d35;border:1px solid #286c5c;color:#a6f2d7}.error{background:#48232e;border:1px solid #87404d;color:#ffc0c6}.footer{margin-top:22px;color:#687995;font-size:10px;display:flex;justify-content:space-between}
        @media(max-width:900px){.content{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.shell{padding:22px 15px 38px}.hero{display:block}.profile-pill{display:inline-block;margin-top:17px}.stats{gap:9px}.stat{padding:14px}.stat-value{font-size:23px}.top-meta{display:none}.backup{gap:10px}.backup-size{font-size:11px}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('backup-manager.dashboard') }}">
            <span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 5 6v5c0 4.5 2.8 7.8 7 10 4.2-2.2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>
            <span><strong>Backup Manager</strong><span>Secure backup control center</span></span>
        </a>
        <div class="top-meta"><i class="pulse"></i> Control panel <span>·</span> {{ now()->format('d M Y') }}</div>
    </header>

    <section class="hero">
        <div><div class="eyebrow">Overview / Backup operations</div><h1>Backup dashboard</h1><p>Veritabanı ve uygulama yedeklerinizi tek bir yerden yönetin. Son yedekleri izleyin ve ihtiyaç duyduğunuzda yeni bir yedek oluşturun.</p></div>
        <div class="profile-pill">◈ &nbsp; {{ $profileName }} profile &nbsp;·&nbsp; {{ $diskName }}</div>
    </section>

    @if(session('backup_success'))<div class="alert success">✓ &nbsp; {{ session('backup_success') }}</div>@endif
    @if(session('backup_error'))<div class="alert error">! &nbsp; {{ session('backup_error') }}</div>@endif

    <section class="stats">
        <article class="stat"><div class="stat-head">Total backups <span class="stat-icon">▤</span></div><div class="stat-value">{{ number_format($totalBackups) }}</div><div class="stat-note">Stored backup sets</div></article>
        <article class="stat"><div class="stat-head">Total storage <span class="stat-icon">◫</span></div><div class="stat-value">{{ number_format($totalSize / 1048576, 1) }} <small style="font-size:13px;color:var(--muted)">MB</small></div><div class="stat-note">Across listed backup artifacts</div></article>
        <article class="stat"><div class="stat-head">Latest backup <span class="stat-icon">◷</span></div><div class="stat-value" style="font-size:20px">{{ $latestBackup && $latestBackup['created_at'] ? \Illuminate\Support\Carbon::parse($latestBackup['created_at'])->diffForHumans() : '—' }}</div><div class="stat-note">Most recent completed set</div></article>
        <article class="stat"><div class="stat-head">Storage status <span class="stat-icon">⌁</span></div><div class="stat-value" style="font-size:20px"><span class="status">Connected</span></div><div class="stat-note">{{ $diskName }} filesystem disk</div></article>
    </section>

    <section class="content">
        <div class="panel">
            <div class="panel-head"><div><h2>Recent backups</h2><p>Yedekleme geçmişi ve oluşturulan dosyalar</p></div><span class="count">{{ $totalBackups }} records</span></div>
            @forelse($backups->take(12) as $backup)
                <div class="backup-list"><article class="backup">
                    <div class="file-icon">▤</div>
                    <div class="backup-main"><div class="backup-title">{{ $backup['id'] }}</div><div class="backup-sub">{{ $backup['created_at'] ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d M Y · H:i:s') : 'Tarih bilgisi yok' }} &nbsp;·&nbsp; {{ $backup['profile'] }}</div><div class="artifacts">@foreach($backup['artifacts'] as $artifact)<span class="artifact">{{ $artifact['name'] }}</span>@endforeach</div></div>
                    <div class="backup-size">{{ number_format($backup['size'] / 1048576, 2) }} MB</div>
                    @if(empty($backup['error']))<form class="verify-form" method="POST" action="{{ route('backup-manager.verify', $backup['id']) }}">@csrf<button class="verify-button" type="submit" title="SHA-256 bütünlük kontrolü">✓ Verify</button></form>@endif
                </article></div>
            @empty
                <div class="empty"><div class="empty-icon">▤</div><h3>Henüz yedek bulunmuyor</h3><p>İlk yedeğinizi oluşturduğunuzda burada görünecek.</p></div>
            @endforelse
        </div>

        <aside class="side">
            <div class="panel">
                <div class="panel-head"><div><h2>New backup</h2><p>Manuel yedekleme başlat</p></div><span class="count">Manual</span></div>
                <form class="run-body" method="POST" action="{{ route('backup-manager.run') }}">
                    @csrf
                    <label class="check"><input type="checkbox" name="database" value="1" {{ $databaseEnabled ? 'checked' : '' }}><span><strong>Database backup</strong><small>MySQL dump · compressed SQL</small></span></label>
                    <label class="check"><input type="checkbox" name="files" value="1" {{ $filesEnabled ? 'checked' : '' }}><span><strong>Application files</strong><small>ZIP archive · configured exclusions</small></span></label>
                    <button class="primary" type="submit">＋ &nbsp; Start backup</button>
                    <p class="hint">Yedekleme işlemi sunucu üzerinde çalışır. Büyük veritabanlarında işlem süresi verinin boyutuna bağlıdır.</p>
                </form>
            </div>
            <div class="panel">
                <div class="panel-head"><div><h2>Configuration</h2><p>Active backup profile</p></div></div>
                <div class="storage-body">
                    <div class="storage-row"><span>Profile</span><strong>{{ $profileName }}</strong></div>
                    <div class="storage-row"><span>Storage disk</span><strong>{{ $diskName }}</strong></div>
                    <div class="storage-row"><span>Database</span><strong>{{ $databaseEnabled ? 'Enabled' : 'Disabled' }}</strong></div>
                    <div class="storage-row"><span>Application files</span><strong>{{ $filesEnabled ? 'Enabled' : 'Disabled' }}</strong></div>
                    <div class="divider"></div>
                    <div class="storage-row"><span>Integrity</span><strong>SHA-256</strong></div>
                    <div class="storage-row"><span>Manifest</span><strong>JSON</strong></div>
                </div>
            </div>
        </aside>
    </section>
    <footer class="footer"><span>Laravel Backup Manager</span><span>Protect what matters.</span></footer>
</div>
</body>
</html>
