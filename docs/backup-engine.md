# Backup engine

The package creates database and optional application-file backups, stores them on Laravel Filesystem disks, verifies the uploaded artifacts, and can restore verified backup sets.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- MySQL client tools (mysqldump and mysql) for MySQL connections
- PostgreSQL client tools (pg_dump and psql) for PostgreSQL connections
- PDO SQLite and a file-backed SQLite database for SQLite connections
- PHP ZIP extension when application file backups are enabled
- PHP sodium extension when encryption is enabled

## Run

```bash
php artisan backup:run
php artisan backup:run --database
php artisan backup:run --files
php artisan backup:run --profile=default --disk=s3
php artisan backup:list
php artisan backup:verify 20261123_120000_aabbccdd
php artisan backup:protect 20261123_120000_aabbccdd --reason="Compliance"
php artisan backup:protect 20261123_120000_aabbccdd --unlock
php artisan backup:prune
php artisan backup:prune --execute
```

Without component flags, the selected profile's enabled components are used. When either component flag is supplied, the flags select which components to include.

## Database and application artifacts

- MySQL and PostgreSQL dumps are streamed through GZIP compression.
- SQLite uses a consistent snapshot created with VACUUM INTO, then compressed.
- Application files are archived as ZIP, with configured include/exclude rules.
- Dotfiles and .env files are excluded by default. Symlinks and paths outside the application root are not archived.

## Storage

The backup engine uses configured Laravel Filesystem disks. Local, Amazon S3, and S3-compatible services such as Cloudflare R2 can be used when their Laravel/Flysystem adapters are installed and configured. A local mirror and additional target disks can be configured in the backup profile. The default package path is `backup-manager/backups/{backup-id}`. If an S3-compatible disk (such as Cloudflare R2) has `root => anibox`, the resulting object key is `anibox/backup-manager/backups/{backup-id}/{artifact}`. The profile's `storage.path` can override the package-relative path.

Run `php artisan migrate` before opening Storage Management; the package migration creates the encrypted `backup_manager_storage_settings` table used for OAuth credentials, folder settings, schedules, and destination selections. Google Drive is registered as a native Laravel Filesystem disk after the account is connected in Storage Management. In the dashboard, open Storage Management, connect Google, enter the destination folder ID (or leave `root`), then select `google-drive` under Backup destinations. Select only Google Drive to avoid a local mirror, or select multiple disks for copies. Upload, inventory, checksum verification, download, restore, and deletion all use the selected Drive destination. The Drive connection test performs listing, write, read, and delete checks.

Large S3 objects use multipart upload when the AWS SDK S3 adapter is available and multipart uploads are enabled.

## Integrity and encryption

Each backup set contains a manifest with artifact sizes and SHA-256 hashes, plus a checksums.sha256 file. After upload, the package can read back each configured destination and verify the artifact size, SHA-256 hash, and supported GZIP/ZIP structure. This behavior is enabled by default and can be disabled with BACKUP_MANAGER_VERIFY_AFTER_UPLOAD.

When encryption is enabled, artifacts are encrypted locally using libsodium XChaCha20-Poly1305 secretstream before upload. The manifest records the algorithm and key identifier, not the secret key. Encrypted artifacts are checksum-verified as stored; archive-format validation is performed after decryption during restore.

## Concurrency, retries, and failure handling

A cache-backed lock prevents overlapping backup runs for the same profile. Configure its lifetime with BACKUP_MANAGER_LOCK_SECONDS (default 21600 seconds). Multi-server deployments must use a shared cache backend so all workers coordinate through the same lock store.

Manual and scheduled Artisan commands support configured retry attempts. Dashboard jobs use Laravel queue retries when a queue connection is configured; otherwise Laravel dispatches the job after the HTTP response. Failed and completed lifecycle events can trigger optional email and Slack notifications.

A backup is not reported as successful until all configured storage writes and enabled post-upload verification checks succeed. Previously uploaded objects are cleaned up on a best-effort basis if a later upload fails. Temporary workspaces are removed after success or failure.

## Deletion protection and retention

Backups can be protected permanently or until a specified date. Protection metadata is written to the manifests on configured destinations. Manual deletion and retention pruning skip protected backups. Use backup:protect with --unlock to remove protection.

Retention is disabled by default. backup:prune previews candidates unless --execute is supplied. Invalid manifests are skipped rather than deleted.

## Restore safety

Restore verifies the backup set before processing. The command is a dry run unless --force is supplied. Database restore streams decompressed SQL to the configured client. Application restore rejects path traversal, symbolic links, duplicate paths, and .env files, and refuses to extract through symbolic links already present in the target directory.

Restore is not transactional for MySQL or PostgreSQL. Keep a separate current backup and test restores in a staging environment before production use.
