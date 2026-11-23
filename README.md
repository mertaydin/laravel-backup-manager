# Laravel Backup Manager

A Laravel package for creating, validating, storing, and restoring application backups.

## Planned capabilities

- MySQL database backups with streaming compression.
- Optional application file archives (ZIP).
- Local, Amazon S3, Cloudflare R2, and Google Drive storage adapters.
- Queue- and scheduler-friendly execution.
- Backup manifests, SHA-256 checksums, retention policies, and optional encryption.
- Backup history and guarded restore workflows.

## Development status

The package is under active initial development. The first milestone focuses on the backup core, MySQL export, application archive creation, and Laravel filesystem storage.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- MySQL 8.0+ (for the initial database driver)

## License

MIT
