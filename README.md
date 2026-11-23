# Laravel Backup Manager

Laravel için veritabanı ve uygulama dosyası yedekleme, sıkıştırma ve uzak depolama paketi.

## Features

- Streaming MySQL backups as compressed `database.sql.gz`.
- Optional application ZIP archives with conservative default exclusions.
- Laravel Filesystem disks: local, Amazon S3 and S3-compatible services such as Cloudflare R2.
- SHA-256 checksums and JSON manifests.
- Backup history listing with `backup:list`.
- Streaming SHA-256 and file-size integrity checks with `backup:verify`.
- One-click integrity verification from the dashboard.
- Configurable retention policy with safe dry-run pruning.
- Built-in dark administration dashboard.
- Manual backup execution from Artisan or the dashboard.
- Temporary workspace cleanup and best-effort cleanup of partial uploads.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- MySQL client tools (`mysqldump`)
- PHP `zip` extension when application file backups are enabled
- PHP `sodium` extension when backup encryption is enabled

## Installation

```bash
composer require mertaydin/laravel-backup-manager
php artisan vendor:publish --tag=backup-manager-config
```

The dashboard is available at `/backup-manager`. It uses Laravel's `web` and `auth` middleware by default. Configure access in `config/backup-manager.php`:

```php
'ui' => [
    'enabled' => true,
    'prefix' => 'backup-manager',
    'middleware' => ['web', 'auth'],
],
```

For applications with a custom admin authorization middleware, replace the middleware list with your own middleware, for example `['web', 'auth', 'can:manage-backups']`. Do not expose the dashboard publicly.

## Usage

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
```

The default profile backs up the configured MySQL connection. Application files are disabled by default. Enable them in `config/backup-manager.php`:

```php
'files' => [
    'enabled' => true,
    'paths' => [], // Empty means the application root.
    'exclude' => ['vendor', 'node_modules', 'storage/logs', '.git'],
    'include_dotfiles' => false,
    'exclude_env' => true,
],
```

The database connection is read from Laravel's `database.connections` configuration. The package uses a temporary mode-0600 MySQL option file for credentials; passwords are not passed in process arguments.

### Authenticated backup encryption

Encryption is optional and disabled by default. When enabled, each backup artifact is encrypted locally before it is uploaded. The package uses libsodium XChaCha20-Poly1305 secretstream with authenticated chunks, so large files are processed without loading them fully into memory.

Generate a 32-byte key and store the base64 output in your environment or secret manager (never commit it to source control):

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Configure the profile:

```php
'encryption' => [
    'enabled' => true,
    'key' => env('BACKUP_MANAGER_ENCRYPTION_KEY'),
    'key_id' => env('BACKUP_MANAGER_ENCRYPTION_KEY_ID'),
],
```

Encrypted artifacts receive the `.enc` suffix. The manifest records the algorithm and key identifier, but never the key itself. Keep the encryption key safe and available for the lifetime of the backups. To download, verify and decrypt an artifact:

```bash
php artisan backup:verify 20261123_120000_aabbccdd
php artisan backup:decrypt 20261123_120000_aabbccdd database.sql.gz.enc
```

By default, decrypted artifacts are written under `storage/app/backup-manager/decrypted/{backup-id}` with restrictive file permissions. Use `--output=/absolute/path` to choose another destination and `--force` only when intentionally replacing an existing file.

### Retention policy

Retention is disabled by default. Enable it in the selected profile:

```php
'retention' => [
    'enabled' => true,
    'keep_days' => 30,
    'keep_last' => 10,
],
```

The pruning command is a dry run unless `--execute` is supplied. It preserves backups inside the configured age window and always protects the newest `keep_last` valid backups. Invalid manifests are skipped and never deleted. After reviewing the preview, schedule the destructive form from your Laravel application's scheduler:

```php
Schedule::command('backup:prune --execute')->daily();
```

## Storage support

Local, Amazon S3, and Cloudflare R2 can be configured as Laravel filesystem disks. A dedicated Google Drive OAuth adapter is not included yet.

## Current limitations

Full database/application restore, queued execution, backup download actions, and a dedicated Google Drive adapter are not implemented yet. If encryption is enabled in a profile, execution fails safely rather than storing plaintext.

## Security

The dashboard is protected by `web` and `auth` middleware by default. Use an additional authorization middleware in production. Application archives exclude `.env` and hidden files by default and skip symlinks. Database output is streamed and credentials are kept out of process arguments.

## License

MIT
