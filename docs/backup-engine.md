# Backup engine

The initial engine uses Laravel filesystem disks for artifact storage and MySQL's `mysqldump` executable for consistent, streaming database exports.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- MySQL client tools (`mysqldump`)
- PHP Zip extension when application file backups are enabled
- A Laravel filesystem disk configured for the target (local, S3, or an S3-compatible endpoint such as Cloudflare R2)

## Run

```bash
php artisan backup:run
php artisan backup:run --database
php artisan backup:run --files
php artisan backup:run --profile=default --disk=s3
```

Without component flags, the selected profile's enabled components are used. When either `--database` or `--files` is supplied, the flags select exactly which components to include.

## Output

Each backup is stored under `{storage.path}/{backup-id}/`:

- `database.sql.gz` when database backup is enabled
- `application.zip` when file backup is enabled
- `checksums.sha256` with SHA-256 hashes for generated payload artifacts
- `manifest.json` with backup identity, profile, artifact sizes, and hashes

## Security and failure handling

- MySQL credentials are written only to a short-lived mode-0600 option file; passwords are not passed in process arguments.
- Database output is streamed through gzip; it is not buffered in PHP memory.
- Application archives skip symlinks and paths outside the application root.
- `.env` and hidden files are excluded by default.
- The backup is reported successful only after all storage writes succeed. Previously uploaded artifacts are deleted best-effort if a later upload fails.
- Temporary workspaces are removed after success or failure.
- If encryption is enabled before the encryption engine is implemented, backup execution fails safely instead of storing plaintext.

Google Drive requires a dedicated adapter and OAuth credential lifecycle; it is not supported by this filesystem-disk implementation yet.
