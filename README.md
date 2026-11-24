# Laravel Backup Manager

[Türkçe dokümantasyon için tıklayın](README.tr.md)

[![Latest Version](https://img.shields.io/packagist/v/mertaydin/laravel-backup-manager?label=Packagist)](https://packagist.org/packages/mertaydin/laravel-backup-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/mertaydin/laravel-backup-manager?label=downloads)](https://packagist.org/packages/mertaydin/laravel-backup-manager)
[![PHP](https://img.shields.io/packagist/php-v/mertaydin/laravel-backup-manager?label=PHP)](https://packagist.org/packages/mertaydin/laravel-backup-manager)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![CI](https://github.com/mertaydin/laravel-backup-manager/actions/workflows/tests.yml/badge.svg?branch=main&label=CI)](https://github.com/mertaydin/laravel-backup-manager/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A Laravel package for database and application file backups, compression, encryption, integrity verification, restoration, and remote storage.

## Dashboard previews

**Dark theme**

![Backup Manager dashboard — dark theme](screenshots/dashboard-dark.svg)

**Light theme**

![Backup Manager dashboard — light theme](screenshots/dashboard-light.svg)

## Features

- Streaming MySQL and PostgreSQL backups as compressed SQL archives; consistent SQLite snapshots as compressed database files.
- Optional application ZIP archives with conservative default exclusions.
- Multiple Laravel Filesystem destinations, including local, Amazon S3, S3-compatible services such as Cloudflare R2, and native Google Drive OAuth storage.
- Dashboard destination selection supports Google Drive-only backups (without a local mirror), multiple destination copies, and a configurable Drive folder.
- Automatic S3 multipart uploads for large artifacts when the AWS SDK adapter is available.
- SHA-256 checksums, JSON manifests, and GZIP/ZIP structure validation.
- Backup history listing with `backup:list`.
- Streaming SHA-256 and file-size integrity checks with `backup:verify`.
- Verified MySQL, PostgreSQL, SQLite and application ZIP restore with `backup:restore`.
- One-click integrity verification from the dashboard.
- Repeat an existing backup from the dashboard using its original artifact selection and metadata.
- Bulk protect, unlock, or delete backups; protected backups are skipped by bulk deletion.
- Show database and application archive sizes separately in backup history.
- Download a complete backup set as a single ZIP archive.
- Copy an existing backup to another configured storage destination without overwriting existing copies.
- Edit backup labels, notes, and tags from the dashboard; update remote and local manifest copies consistently.
- Export filtered backup reports as CSV or JSON.
- Download individual backup artifacts and manifests directly from the dashboard.
- Delete backup sets from both the configured remote disk and the local mirror.
- Protect important backups permanently or until a selected date; protected backups cannot be manually deleted or pruned until unlocked or expired.
- Per-profile concurrency locks prevent overlapping backup runs.
- Mirror each backup to a configurable local filesystem disk when remote storage is used.
- Background dashboard backups with job status polling; use a Laravel queue worker for fully independent execution.
- Configurable retention policy with safe dry-run pruning.
- Built-in responsive administration dashboard with six languages (English, Turkish, Spanish, Simplified Chinese, Hindi, and Arabic).
- Light, dark, and system theme preferences, remembered in the browser.
- Manual backup execution from Artisan or the dashboard.
- Temporary workspace cleanup and best-effort cleanup of partial uploads.
- Storage health checks for connectivity, listing, write, read, and delete permissions.
- Preflight checks for MySQL tools, PHP extensions, temporary directory permissions, and storage access.
- Backup labels, notes, tags, and configurable automatic naming templates.
- Backup history filters for date, type, storage disk, and manifest status.
- Detailed backup inspection with artifact sizes and SHA-256 checksums.
- Automatic post-upload integrity verification (configurable).
- Backup lifecycle events: `BackupCompleted`, `BackupFailed`, `BackupDeleted`, and `BackupRestored`.
- Dashboard summary of the next scheduled backup and aggregate storage usage.
- Explicit confirmation before destructive restore operations.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- MySQL client tools (`mysqldump` and `mysql`) for MySQL connections
- PostgreSQL client tools (`pg_dump` and `psql`) for PostgreSQL connections
- PHP `pdo_sqlite` extension and a file-backed SQLite database for SQLite connections
- PHP `zip` extension when application file backups are enabled
- PHP `sodium` extension when backup encryption is enabled

## Installation

```bash
composer require mertaydin/laravel-backup-manager:^0.8
php artisan vendor:publish --tag=backup-manager-config
php artisan vendor:publish --tag=backup-manager-assets
```

The dashboard assets are published to `public/vendor/backup-manager`; republish them after package updates with `php artisan vendor:publish --tag=backup-manager-assets --force
php artisan optimize:clear`. Republish assets after package updates to receive the latest dashboard interface. CSS and JavaScript URLs include the configurable `backup-manager.ui.asset_version` query parameter (default `0.8.7`) to prevent stale browser caches. You can override it with `BACKUP_MANAGER_ASSET_VERSION`. The dashboard is available at `/backup-manager`. It supports English, Turkish, Spanish, Simplified Chinese, Hindi, and Arabic. Users can switch between light, dark, and system themes; the selected language is saved in the session and the theme preference is saved in the browser. It uses Laravel's `web` and `auth` middleware by default. Configure access in `config/backup-manager.php`:

```php
'ui' => [
    'enabled' => true,
    'prefix' => 'backup-manager',
    'middleware' => ['web', 'auth'],
],
```

For applications with a custom admin authorization middleware, replace the middleware list with your own middleware, for example `['web', 'auth', 'can:manage-backups']`. Do not expose the dashboard publicly.

You can also register a request-based authorization callback using the package facade. This callback is an additional authorization layer; the configured route middleware (including `auth`) still runs first. If no callback is registered, the existing middleware-only behavior is preserved.

```php
use Illuminate\Http\Request;
use MertAydin\LaravelBackupManager\Facades\BackupManager;

BackupManager::auth(function (Request $request) {
    return $request->user()?->isSuperAdmin()
        && (int) $request->user()->id === 1;
});
```

Register the callback in your application's `AppServiceProvider::boot()` method or another provider that boots before the dashboard request. Return `true` to allow access and `false` to respond with HTTP 403.

## Database migrations

**Run the package migration after installing or upgrading to v0.6.3:**

```bash
php artisan migrate
```

The package automatically registers its migrations through its service provider; you do not need to publish the migration manually. This creates the `backup_manager_storage_settings` table, which is required by Storage Management to persist encrypted storage credentials and automatic-backup settings. Without it, the dashboard may report MySQL error `1146` for the missing table.

You can confirm whether Laravel has run the migration with:

```bash
php artisan migrate:status
```

If the migration is still pending, run `php artisan migrate` using the same environment and database connection used by the application. Back up your database before running migrations in production.

## Usage

```bash
php artisan backup:run
php artisan backup:run --database
php artisan backup:run --files
php artisan backup:run --profile=default --disk=s3
php artisan backup:list
php artisan backup:list --disk=s3 --limit=50
php artisan backup:verify 20261123_120000_aabbccdd
php artisan backup:compare 20261123_120000_aabbccdd 20261124_120000_ccddeeff
php artisan backup:report --json
php artisan backup:protect 20261123_120000_aabbccdd --reason="Compliance retention"
php artisan backup:protect 20261123_120000_aabbccdd --until="2027-01-01T00:00:00Z"
php artisan backup:protect 20261123_120000_aabbccdd --unlock
php artisan backup:decrypt 20261123_120000_aabbccdd database.sql.gz.enc
php artisan backup:prune
php artisan backup:prune --execute
php artisan backup:restore 20261123_120000_aabbccdd --database
php artisan backup:restore 20261123_120000_aabbccdd --database --force
php artisan backup:restore 20261123_120000_aabbccdd --files --target=/var/www/my-app
php artisan backup:restore 20261123_120000_aabbccdd --files --target=/var/www/my-app --force
```

The default profile backs up the configured MySQL connection. Application files are disabled by default. When the configured backup disk is remote, each completed backup is also mirrored to `storage.local_disk` (default: `local`). Configure both destinations in `config/backup-manager.php`:

```php
'files' => [
    'enabled' => true,
    'paths' => [], // Empty means the application root.
    'exclude' => ['vendor', 'node_modules', 'storage/logs', '.git'],
    'include_dotfiles' => false,
    'exclude_env' => true,
],
```

For example, set `storage.disk` to `s3` and `storage.local_disk` to `local` to keep a local copy and upload the same backup set to S3/R2. Set both to `local` to avoid duplicate writes. Deleting a backup from the dashboard and running retention pruning remove its directory from both disks.

The dashboard returns immediately after scheduling a backup. By default, when `BACKUP_MANAGER_QUEUE_CONNECTION` is not set, Laravel runs the job after sending the HTTP response; this does not require a queue worker, but it still uses a PHP-FPM worker while the backup finishes. For long-running production backups, configure a real queue connection and run a worker:

```dotenv
BACKUP_MANAGER_QUEUE_CONNECTION=database
BACKUP_MANAGER_QUEUE=backups
```

Ensure the Laravel queue migration is installed and run `php artisan queue:work --queue=backups`. The dashboard polls the job status and refreshes when the backup completes.

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

### Deletion protection

Protect an important backup from both manual deletion and retention pruning. In the dashboard, use **Lock backup**, optionally enter a reason and expiry date, or leave the expiry empty for permanent protection. Use **Unlock backup** to remove the protection. The delete action is hidden while protection is active.

The equivalent Artisan commands are:

```bash
php artisan backup:protect 20261123_120000_aabbccdd --reason="Compliance retention"
php artisan backup:protect 20261123_120000_aabbccdd --until="2027-01-01T00:00:00+03:00" --reason="Legal hold"
php artisan backup:protect 20261123_120000_aabbccdd --unlock
```

Each profile also has a cache-backed concurrency lock. A second backup for the same profile is rejected while one is running. Configure the lock lifetime with BACKUP_MANAGER_LOCK_SECONDS (default: 21600 seconds); use a shared cache backend when multiple application servers can start backups.

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

### Dashboard-managed automatic backups

The dashboard includes an automatic backup switch, daily run time, timezone, and age-based cleanup. Save these settings under **Backup Manager → Storage Management → Automatic backups**. The cleanup runs only after a successful scheduled backup and permanently deletes backup sets older than the configured number of days.

The host must invoke Laravel's scheduler every minute (add this once to the server crontab):

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

Disabling automatic backups in the dashboard pauses package-created scheduled runs; manual backups remain available. The default schedule is disabled.

### Restore

The restore command always verifies the complete backup set against its manifest before proceeding. It is a dry run unless `--force` is supplied:

```bash
# Verify and preview only
php artisan backup:restore 20261123_120000_aabbccdd --database

# Restore into the database connection selected by the backup profile
php artisan backup:restore 20261123_120000_aabbccdd --database --force

# Preview or restore application files into an explicit target directory
php artisan backup:restore 20261123_120000_aabbccdd --files --target=/var/www/my-app
php artisan backup:restore 20261123_120000_aabbccdd --files --target=/var/www/my-app --force
```

Select exactly one component per command. Database restore streams the decompressed SQL into the configured `mysql` client and validates the gzip stream before executing SQL. File restore rejects path traversal, symbolic links, duplicate paths and environment files, and refuses to extract through symbolic links already present in the target. Existing files are overwritten only when `--force` is explicitly supplied. Restore is not transactional: keep a separate current backup and test restores in a staging environment before production use.

## Backup metadata, naming, and events

Dashboard-created backups can include a human-readable label, a note, and up to ten tags. These values are stored in the backup manifest and are searchable from the dashboard history.

When no custom label is supplied, the package generates one using `BACKUP_MANAGER_NAME_TEMPLATE`. The default is `:app-:date-:time`. Supported placeholders are:

- `:app` — slugged Laravel application name.
- `:date` — backup date in `YYYYMMDD` format.
- `:time` — backup time in `HHMMSS` format.
- `:id` — complete backup identifier.

The package dispatches Laravel events after backup lifecycle operations:

- `MertAydin\LaravelBackupManager\Events\BackupCompleted`
- `MertAydin\LaravelBackupManager\Events\BackupFailed`
- `MertAydin\LaravelBackupManager\Events\BackupDeleted`
- `MertAydin\LaravelBackupManager\Events\BackupRestored`

Listen to these events using Laravel's standard event/listener registration. The backup result is available as `$event->result` for completion and failure events; deletion and restore events expose `backupId`, with the restored component on `BackupRestored`.

## Storage health and preflight checks

Use **Storage Management → Laravel Filesystem disks → Test connection and permissions** to verify a disk. The check performs temporary listing, write, read, and delete operations under the configured backup path and removes its probe file afterward.

Before a dashboard backup is queued, the package checks the temporary workspace, every configured storage disk, the selected MySQL/PostgreSQL/SQLite connection and its dump tooling, and the ZIP or sodium PHP extensions when those features are selected.

## Automatic integrity verification

After uploading a backup, the package verifies artifact SHA-256 checksums and file sizes by default. Disable this extra read pass when necessary:

```dotenv
BACKUP_MANAGER_VERIFY_AFTER_UPLOAD=false
```

## Storage support

Local, Amazon S3, and Cloudflare R2 can be configured as Laravel filesystem disks. A dedicated Google Drive OAuth adapter is not included yet.

## Current limitations

A dedicated Google Drive OAuth adapter is not included yet. Google Drive integrations must be provided through a Laravel filesystem disk.

## Security

The dashboard is protected by `web` and `auth` middleware by default. Use an additional authorization middleware in production. Application archives exclude `.env` and hidden files by default and skip symlinks. Database output is streamed and credentials are kept out of process arguments.

## Development

Install the development dependencies and run the quality checks:

```bash
composer install
composer format
composer format:test
composer test
composer audit
```

Generate IDE autocomplete metadata with Barryvdh Laravel IDE Helper:

```bash
composer ide-helper:generate
composer ide-helper:meta
```

Generated IDE Helper files are excluded from version control.

## License

MIT


### Backup protection, retry, destinations, reports and comparison

A backup can be protected from manual deletion and retention pruning by passing protection metadata to the backup manager:

```php
app(\MertAydin\LaravelBackupManager\BackupManager::class)->run([
    'locked' => true,
    'protection_reason' => 'Monthly compliance snapshot',
]);
```

A time-limited protection can be set with `protected_until` (an ISO-8601 date/time). Both the delete action and retention pruning respect these manifest values.

Retry behavior is configurable. The attempt count includes the initial attempt:

```dotenv
BACKUP_MANAGER_RETRY_ATTEMPTS=3
BACKUP_MANAGER_RETRY_DELAY=30
```

To send each backup set to more than one additional Laravel Filesystem disk, configure a comma-separated list:

```dotenv
BACKUP_MANAGER_TARGET_DISKS=s3,r2
```

The configured primary disk and local mirror are still included; duplicate disk names are removed. All destinations are checked during preflight and post-upload integrity verification. S3-compatible disks use multipart upload for large artifacts when the Flysystem AWS S3 adapter and AWS SDK multipart uploader are installed. The default threshold is 64 MiB and the default part size is 8 MiB.

Email and Slack notifications are opt-in:

```dotenv
BACKUP_MANAGER_NOTIFICATIONS_ENABLED=true
BACKUP_MANAGER_NOTIFICATION_EMAILS=ops@example.com,admin@example.com
BACKUP_MANAGER_SLACK_WEBHOOK=https://hooks.slack.com/services/REDACTED
```

Configure a real Slack incoming webhook URL in your secret manager; never commit it to source control. Notifications are sent for completed and failed backups.

For MySQL, table include/exclude filters are supported. PostgreSQL and SQLite currently back up the complete configured database. SQLite `:memory:` connections are not supported because they do not have a persistent database file.

Protect a backup permanently or until a specified date. Protected backups are excluded from manual deletion and retention cleanup:

```bash
php artisan backup:protect BACKUP_ID --reason="Compliance retention"
php artisan backup:protect BACKUP_ID --until="2027-01-01T00:00:00Z"
php artisan backup:protect BACKUP_ID --unlock
```

Compare two verified backup manifests and their artifact sizes:

```bash
php artisan backup:compare BACKUP_ID_1 BACKUP_ID_2 --disk=s3
```

Generate an inventory report, including invalid manifests and total verified artifact bytes:

```bash
php artisan backup:report --json
```


### Exporting backup reports

The Reports page supports JSON and CSV downloads. Both exports respect the active date, status, and storage-disk filters. CSV files include backup metadata and separate database/application artifact sizes, and text cells are guarded against spreadsheet formula injection.
