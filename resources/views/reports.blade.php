<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Yedekleme Raporları · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/backup-manager/css/dashboard.css') }}?v={{ config('backup-manager.ui.asset_version', '0.8.12') }}-0.8.13">
</head>
<body>
<div class="shell reports-page">
    <header class="topbar">
        <a class="brand" href="{{ route('backup-manager.dashboard') }}"><span class="logo">▤</span><span><strong>Backup Manager</strong><span>Raporlama merkezi</span></span></a>
        <div class="top-actions">
            <a class="theme-button" href="{{ route('backup-manager.dashboard') }}">← Panele dön</a>
            <a class="theme-button" href="{{ route('backup-manager.storage') }}">Depolama yönetimi</a>
            <a class="theme-button reports-export" href="{{ route('backup-manager.reports.export', request()->query()) }}">↓ JSON raporunu indir</a>
            <a class="theme-button reports-export" href="{{ route('backup-manager.reports.export.csv', request()->query()) }}">↓ CSV indir</a>
        </div>
    </header>

    <section class="hero reports-hero">
        <div><div class="eyebrow">ANALİZ VE DENETİM</div><h1>Yedekleme raporları</h1><p>Yedek envanterini, depolama kullanımını, bütünlük durumunu ve saklama korumalarını tek ekrandan inceleyin.</p></div>
        <div class="profile-pill">◈ &nbsp; {{ $profileName }} profili</div>
    </section>

    <section class="report-stat-grid">
        <article class="report-stat"><span>Toplam yedek kopyası</span><strong>{{ number_format($summary['total']) }}</strong><small>Tüm yapılandırılmış hedefler</small></article>
        <article class="report-stat"><span>Geçerli yedek</span><strong class="report-good">{{ number_format($summary['valid']) }}</strong><small>Manifest okunabilir</small></article>
        <article class="report-stat"><span>Hatalı manifest</span><strong class="{{ $summary['invalid'] ? 'report-bad' : '' }}">{{ number_format($summary['invalid']) }}</strong><small>İnceleme gerektiren kayıtlar</small></article>
        <article class="report-stat"><span>Toplam depolama</span><strong>{{ number_format($summary['bytes'] / 1048576, 1) }} <small>MB</small></strong><small>Geçerli yedeklerin manifest boyutu</small></article>
        <article class="report-stat"><span>Korumalı yedek</span><strong>{{ number_format($summary['protected']) }}</strong><small>Silinmeye karşı kilitli</small></article>
        <article class="report-stat"><span>Son başarılı yedek</span><strong class="report-stat-date">{{ $summary['latest'] && $summary['latest']['created_at'] ? \Illuminate\Support\Carbon::parse($summary['latest']['created_at'])->format('d.m.Y H:i') : '—' }}</strong><small>{{ $summary['latest']['id'] ?? 'Henüz yedek yok' }}</small></article>
    </section>

    <section class="report-type-strip">
        <div><span class="report-type-dot report-type-dot--manual"></span><span>Manuel</span><strong>{{ number_format($summary['manual']) }}</strong></div>
        <div><span class="report-type-dot report-type-dot--scheduled"></span><span>Zamanlanmış</span><strong>{{ number_format($summary['scheduled']) }}</strong></div>
        <div class="report-generated">Rapor oluşturulma: {{ now()->format('d.m.Y H:i:s') }}</div>
    </section>

    <section class="panel report-panel">
        <div class="panel-head"><div><h2>Depolama hedeflerine göre özet</h2><p>Her diskte bulunan yedek kopyalarının ve kullanımın dağılımı</p></div><span class="count">{{ count($diskSummary) }} hedef</span></div>
        <div class="report-disk-grid">
            @foreach($diskSummary as $disk)
                <article class="report-disk-card">
                    <div class="report-disk-title"><span class="report-disk-icon">▤</span><strong>{{ $disk['disk'] }}</strong><span class="report-disk-status {{ $disk['error'] ? 'is-error' : 'is-ok' }}">{{ $disk['error'] ? 'Erişim hatası' : 'Erişilebilir' }}</span></div>
                    <div class="report-disk-numbers"><div><strong>{{ number_format($disk['total']) }}</strong><small>Toplam</small></div><div><strong>{{ number_format($disk['valid']) }}</strong><small>Geçerli</small></div><div><strong>{{ number_format($disk['invalid']) }}</strong><small>Hatalı</small></div></div>
                    <div class="report-disk-usage"><span>Depolama kullanımı</span><strong>{{ number_format($disk['bytes'] / 1048576, 2) }} MB</strong></div>
                    @if($disk['error'])<p class="report-disk-error">{{ $disk['error'] }}</p>@endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="panel report-panel">
        <div class="panel-head"><div><h2>Yedekleme envanteri</h2><p>Durum, hedef, boyut ve tarih bazında ayrıntılı kayıtlar</p></div><span class="count">{{ $backups->count() }} kayıt</span></div>
        <form method="GET" action="{{ route('backup-manager.reports') }}" class="report-filters">
            <label><span>Başlangıç</span><input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label><span>Bitiş</span><input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            <label><span>Durum</span><select name="status"><option value="">Tüm durumlar</option><option value="available" {{ ($filters['status'] ?? '') === 'available' ? 'selected' : '' }}>Geçerli</option><option value="invalid" {{ ($filters['status'] ?? '') === 'invalid' ? 'selected' : '' }}>Hatalı</option></select></label>
            <label><span>Depolama hedefi</span><select name="disk"><option value="">Tüm hedefler</option>@foreach($diskSummary as $disk)<option value="{{ $disk['disk'] }}" {{ ($filters['disk'] ?? '') === $disk['disk'] ? 'selected' : '' }}>{{ $disk['disk'] }}</option>@endforeach</select></label>
            <button class="action-button" type="submit">Raporu filtrele</button>
            <a class="action-button" href="{{ route('backup-manager.reports') }}">Temizle</a>
        </form>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>Yedek</th><th>Tarih</th><th>Tür</th><th>Hedef</th><th>Boyut</th><th>Durum</th><th>Koruma</th></tr></thead>
                <tbody>
                @forelse($backups as $backup)
                    <tr>
                        <td><a href="{{ route('backup-manager.details', ['id' => $backup['id']]) }}"><strong>{{ $backup['label'] ?? $backup['id'] }}</strong></a><small>{{ $backup['id'] }}</small></td>
                        <td>{{ !empty($backup['created_at']) ? \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d.m.Y H:i') : '—' }}</td>
                        <td>{{ ($backup['type'] ?? '') === 'scheduled' ? 'Zamanlanmış' : 'Manuel' }}</td>
                        <td>{{ $backup['disk'] }}</td>
                        <td>{{ number_format(((int) ($backup['total_size'] ?? 0)) / 1048576, 2) }} MB</td>
                        <td><span class="report-pill {{ ($backup['status'] ?? '') === 'available' ? 'is-good' : 'is-bad' }}">{{ ($backup['status'] ?? '') === 'available' ? 'Geçerli' : 'Hatalı' }}</span></td>
                        <td>{{ ($backup['protected'] ?? false) ? 'Korumalı' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="report-empty">Seçilen filtrelerde yedek kaydı bulunamadı.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <p class="report-footnote">Not: Bu ekran mevcut manifest dosyalarından envanter ve depolama raporu üretir. Geçerli durumu, manifestin okunabildiğini ifade eder; SHA-256 ve arşiv bütünlüğü için yedek ayrıntılarından ayrıca doğrulama çalıştırın.</p>
</div>
</body>
</html>
