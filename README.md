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
- Built-in dark administration dashboard.
- Manual backup execution from Artisan or the dashboard.
- Temporary workspace cleanup and best-effort cleanup of partial uploads.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- MySQL client tools (`mysqldump`)
- PHP `zip` extension when application file backups are enabled

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

## Storage support

Local, Amazon S3, and Cloudflare R2 can be configured as Laravel filesystem disks. A dedicated Google Drive OAuth adapter is not included yet.

## Current limitations

Restore, encryption, retention automation, queued execution, backup download/delete actions, and a dedicated Google Drive adapter are not implemented yet. If encryption is enabled in a profile, execution fails safely rather than storing plaintext.

## Security

The dashboard is protected by `web` and `auth` middleware by default. Use an additional authorization middleware in production. Application archives exclude `.env` and hidden files by default and skip symlinks. Database output is streamed and credentials are kept out of process arguments.

## License

MIT
