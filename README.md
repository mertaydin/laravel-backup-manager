# Laravel Backup Manager

A Laravel package for creating compressed MySQL backups and optional application file archives, then storing the artifacts on any configured Laravel filesystem disk.

## Current status

The initial backup engine is implemented on the `feature/backup-engine-core` development branch:

- Streaming MySQL export through `mysqldump` to `database.sql.gz`.
- Optional application ZIP archive with conservative exclusions.
- Laravel filesystem storage adapter (local and any configured Flysystem disk).
- Per-artifact SHA-256 checksums and a JSON manifest.
- `backup:run` Artisan command.
- Temporary workspace cleanup and best-effort cleanup of partially uploaded artifacts.

This is an early development version. Restore, encryption, retention, scheduling, history UI, and a dedicated Google Drive adapter are not implemented yet. Encryption being enabled in a profile causes the backup to fail safely; it will not silently create an unencrypted backup.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- MySQL client tools (`mysqldump`) for database backups
- PHP `zip` extension for application file backups

## Installation

```bash
composer require mertaydin/laravel-backup-manager
php artisan vendor:publish --tag=backup-manager-config
```

Configure a Laravel filesystem disk (local, S3, or an S3-compatible endpoint such as Cloudflare R2), then set `BACKUP_MANAGER_DISK` to its name.

## Usage

```bash
php artisan backup:run
php artisan backup:run --database
php artisan backup:run --files
php artisan backup:run --profile=default --disk=s3
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

Local, Amazon S3, and Cloudflare R2 can be used through Laravel filesystem disks. Google Drive needs a separate OAuth-backed adapter and is not included in this first engine milestone.

See [docs/backup-engine.md](docs/backup-engine.md) for implementation details and security notes.

## License

MIT
