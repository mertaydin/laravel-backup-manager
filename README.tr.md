# Laravel Backup Manager

[English documentation](README.md)

[![Latest Version](https://img.shields.io/packagist/v/mertaydin/laravel-backup-manager?label=Packagist)](https://packagist.org/packages/mertaydin/laravel-backup-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/mertaydin/laravel-backup-manager?label=downloads)](https://packagist.org/packages/mertaydin/laravel-backup-manager)
[![PHP](https://img.shields.io/packagist/php-v/mertaydin/laravel-backup-manager?label=PHP)](https://packagist.org/packages/mertaydin/laravel-backup-manager)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![CI](https://github.com/mertaydin/laravel-backup-manager/actions/workflows/tests.yml/badge.svg?branch=main&label=CI)](https://github.com/mertaydin/laravel-backup-manager/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Laravel uygulamaları için MySQL veritabanı ve uygulama dosyası yedekleme, sıkıştırma, şifreleme, bütünlük doğrulama, geri yükleme ve uzak depolama paketi.

## Panel önizlemeleri

**Koyu tema**

![Backup Manager koyu tema](screenshots/dashboard-dark.svg)

**Açık tema**

![Backup Manager açık tema](screenshots/dashboard-light.svg)

## Özellikler

- MySQL veritabanını akış halinde `database.sql.gz` olarak yedekleme.
- İsteğe bağlı uygulama dosyalarını ZIP arşivine alma.
- Laravel Filesystem diskleri: local, Amazon S3 ve Cloudflare R2 gibi S3 uyumlu servisler.
- SHA-256 checksum ve JSON manifest oluşturma.
- Artisan komutlarıyla yedek listeleme, doğrulama, şifre çözme ve geri yükleme.
- Yönetim panelinden yedek başlatma, durumunu takip etme ve dosyaları indirme.
- Yedek silindiğinde hem uzak depodan hem yerel kopyadan kaldırma.
- Uzak depoya gönderilen yedekleri ayrıca yerel diske kopyalama.
- Kuyruk bağlantısı tanımlandığında gerçek Laravel queue job kullanma.
- Saklama politikası ve güvenli önizleme (dry-run) ile eski yedekleri temizleme.
- Türkçe, İngilizce, İspanyolca, Basitleştirilmiş Çince, Hintçe ve Arapça panel.
- Açık, koyu ve sistem teması.
- Geçici dosyaları işlem sonunda temizleme ve başarısız yüklemelerde kısmi dosyaları silmeye çalışma.

## Gereksinimler

- PHP 8.2+
- Laravel 11, 12 veya 13
- MySQL istemci araçları (`mysqldump` ve `mysql`)
- Uygulama dosyalarını yedeklemek için PHP `zip` eklentisi
- Yedek şifreleme etkinleştirilecekse PHP `sodium` eklentisi

## Kurulum

```bash
composer require mertaydin/laravel-backup-manager:^0.5
php artisan vendor:publish --tag=backup-manager-config
php artisan vendor:publish --tag=backup-manager-assets
```

Panel varsayılan olarak `/backup-manager` adresinde açılır. Asset dosyaları `public/vendor/backup-manager` konumuna yayınlanır. Paket güncellendiğinde:

```bash
php artisan vendor:publish --tag=backup-manager-assets --force
```

Varsayılan panel middleware ayarı:

```php
'ui' => [
    'enabled' => true,
    'prefix' => 'backup-manager',
    'middleware' => ['web', 'auth'],
],
```

Panel erişimini ayrıca bir yetkilendirme callback'i ile sınırlandırabilirsiniz. Bu callback, yapılandırılmış middleware katmanına ek olarak çalışır; `auth` middleware'ini devre dışı bırakmaz.

```php
use Illuminate\\Http\\Request;
use MertAydin\\LaravelBackupManager\\Facades\\BackupManager;

BackupManager::auth(function (Request $request) {
    return $request->user()?->isSuperAdmin()
        && (int) $request->user()->id === 1;
});
```

Bu tanımı uygulamanızın `AppServiceProvider::boot()` metoduna ekleyebilirsiniz. Callback `true` döndürürse erişim verilir; `false` döndürürse HTTP 403 yanıtı verilir. Callback tanımlanmadığında mevcut middleware tabanlı davranış korunur. Paneli herkese açık bırakmayın.

## Kullanım

```bash
php artisan backup:run
php artisan backup:run --database
php artisan backup:run --files
php artisan backup:run --profile=default --disk=s3
php artisan backup:list
php artisan backup:list --disk=s3 --limit=50
php artisan backup:verify 20261123_120000_aabbccdd
php artisan backup:decrypt 20261123_120000_aabbccdd database.sql.gz.enc
php artisan backup:prune
php artisan backup:prune --execute
php artisan backup:restore 20261123_120000_aabbccdd --database
php artisan backup:restore 20261123_120000_aabbccdd --database --force
php artisan backup:restore 20261123_120000_aabbccdd --files --target=/var/www/my-app
php artisan backup:restore 20261123_120000_aabbccdd --files --target=/var/www/my-app --force
```

Varsayılan profil Laravel yapılandırmasındaki MySQL bağlantısını kullanır. Uygulama dosyası yedeklemesi varsayılan olarak kapalıdır. Örnek dosya ayarları:

```php
'files' => [
    'enabled' => true,
    'paths' => [], // Boş bırakılırsa uygulama kök dizini.
    'exclude' => ['vendor', 'node_modules', 'storage/logs', '.git'],
    'include_dotfiles' => false,
    'exclude_env' => true,
],
```

### Yerel ve uzak depolama

Yedekler `storage.disk` üzerinde saklanır; ayrıca `storage.local_disk` (varsayılan: `local`) üzerine kopyalanır. Örneğin `disk=s3` ve `local_disk=local` ayarları aynı yedek setini S3/R2'ye yükler ve yerelde de tutar. İki disk aynıysa yinelenen yazma yapılmaz. Panelden silme ve retention temizliği her iki diskteki yedek dizinini de kaldırır.

### Arka plan yedekleme ve kuyruk

Panel yedekleme isteğinden sonra HTTP yanıtını bekletmeden döner. Kuyruk bağlantısı belirtilmemişse iş Laravel'in `afterResponse` mekanizmasıyla yanıt gönderildikten sonra çalışır. Bu yöntem kuyruk worker gerektirmez ancak işlem bitene kadar bir PHP-FPM worker kullanmaya devam eder. Uzun süren üretim yedeklemelerinde gerçek bir kuyruk kullanın:

```dotenv
BACKUP_MANAGER_QUEUE_CONNECTION=database
BACKUP_MANAGER_QUEUE=backups
```

Laravel queue migration'larının kurulu olduğundan emin olun ve worker'ı başlatın:

```bash
php artisan queue:work --queue=backups
```

Panel iş durumunu sorgular ve yedek tamamlandığında listeyi yeniler.

### Şifreleme

Şifreleme isteğe bağlıdır ve varsayılan olarak kapalıdır. Etkinleştirildiğinde her dosya yüklenmeden önce XChaCha20-Poly1305 secretstream ile parçalı ve doğrulanabilir biçimde şifrelenir.

32 baytlık anahtar üretin:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Anahtarı kaynak koduna eklemeyin; ortam değişkeni veya secret manager kullanın:

```php
'encryption' => [
    'enabled' => true,
    'key' => env('BACKUP_MANAGER_ENCRYPTION_KEY'),
    'key_id' => env('BACKUP_MANAGER_ENCRYPTION_KEY_ID'),
],
```

Manifest algoritma ve anahtar kimliğini tutar; anahtarın kendisini içermez. Anahtarı yedeklerin saklama süresi boyunca güvenli biçimde muhafaza edin.

### Saklama politikası

Saklama politikası varsayılan olarak kapalıdır. Etkinleştirmek için:

```php
'retention' => [
    'enabled' => true,
    'keep_days' => 30,
    'keep_last' => 10,
],
```

`backup:prune` komutu `--execute` verilmedikçe yalnızca silinecek adayları listeler. Geçersiz manifest içeren yedekler silinmez. Önizlemeyi kontrol ettikten sonra Laravel Scheduler üzerinden çalıştırabilirsiniz:

```php
Schedule::command('backup:prune --execute')->daily();
```

### Geri yükleme

Geri yükleme öncesinde yedek setinin tamamı manifest üzerinden doğrulanır. `--force` kullanılmadığında komut yalnızca önizleme yapar. Veritabanı geri yüklemesi sıkıştırılmış SQL'i akış halinde MySQL istemcisine aktarır. Dosya geri yüklemesi path traversal, sembolik bağlantılar, yinelenen yollar ve `.env` dosyalarına karşı kontroller uygular. Var olan dosyalar yalnızca `--force` ile üzerine yazılır.

Geri yükleme işlemi transaction tabanlı değildir. Üretimde kullanmadan önce mevcut sistemin ayrı bir yedeğini alın ve geri yüklemeyi staging ortamında test edin.

## Depolama desteği

Laravel Filesystem diskleri üzerinden local, Amazon S3 ve Cloudflare R2 kullanılabilir. Pakette yerleşik Google Drive OAuth adaptörü henüz bulunmuyor; Google Drive bağlantısı Laravel Filesystem diski olarak ayrıca sağlanmalıdır.

## Güvenlik notları

- Panel varsayılan olarak `web` ve `auth` middleware'leriyle korunur; ayrıca callback ile yönetici yetkisi kontrolü ekleyin.
- Veritabanı parolası komut satırı argümanlarına yazılmaz; geçici ve kısıtlı izinli MySQL option dosyası kullanılır.
- Uygulama arşivlerinde `.env` ve gizli dosyalar varsayılan olarak dışarıda bırakılır; sembolik bağlantılar arşive alınmaz.
- Şifreleme anahtarını repository'ye veya yedek manifestine koymayın.
- Yedekleri indirme ve geri yükleme yetkisini yalnızca güvenilir yöneticilere verin.
- Geri yükleme işlemlerini önce staging ortamında doğrulayın.

## Lisans

MIT
