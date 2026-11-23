# Changelog

## [Unreleased]

## [0.8.7] - 2026-11-23

### Added
- Dashboard and backup-details controls for permanent or time-limited deletion protection, with reason, status, and unlock actions in all six locales.
- Per-profile cache locks that reject overlapping backup runs; configurable lock lifetime through BACKUP_MANAGER_LOCK_SECONDS.

### Improved
- Dashboard history, downloads, details, and integrity verification now include all configured backup destination disks.
- Downloads fall back to another configured copy when the selected disk is missing the requested artifact.
- Dashboard asset cache version defaults to 0.8.7.

### Existing backup engine features
- Manifest-based deletion protection honored by manual deletion and retention pruning.
- Configurable retry attempts for manual and scheduled backup commands.
- Multiple additional Laravel Filesystem destinations with preflight checks.
- Optional email and Slack notifications for successful and failed backups.
- Verified backup manifest comparison through `backup:compare`.
- Backup inventory and storage usage reports through `backup:report`.
- GZIP and ZIP structure validation during backup verification.
- S3 multipart upload for large artifacts when supported by the AWS SDK adapter.
- PostgreSQL and SQLite database dump/restore support alongside MySQL.
- Dashboard queue retries and the `backup:protect` command for permanent or time-limited deletion protection.

## [0.7.0] - 2026-11-23

### Added
- Storage health checks for connection, listing, write, read, and delete permissions.
- Backup preflight validation for storage access, MySQL tooling, required PHP extensions, and temporary workspace permissions.
- Backup labels, notes, tags, and configurable naming templates.
- Backup history filtering by search text, date range, storage disk, backup type, and manifest status.
- A backup details page showing artifact sizes and SHA-256 checksums.
- Automatic post-upload SHA-256 and file-size verification, configurable through `BACKUP_MANAGER_VERIFY_AFTER_UPLOAD`.
- Lifecycle events: `BackupCompleted`, `BackupFailed`, `BackupDeleted`, and `BackupRestored`.
- Dashboard display of the next scheduled backup and total stored artifact size.
- Interactive confirmation before destructive restore operations.

### Improved
- Backup history includes the configured remote disk and local mirror without duplicate backup entries.
- Backup labels are generated from a configurable template when no custom label is supplied.
- Dashboard asset cache version defaults to `0.7.0`.

### Configuration
- `BACKUP_MANAGER_NAME_TEMPLATE` supports `:app`, `:date`, `:time`, and `:id` placeholders.
- `BACKUP_MANAGER_VERIFY_AFTER_UPLOAD` enables or disables post-upload verification.

## [0.6.3] - 2026-11-23

### Documentation
- Document the required `php artisan migrate` step and the `backup_manager_storage_settings` table used by Storage Management.

Release: v0.6.3

### Added
- Dashboard controls for enabling or pausing daily automatic backups, configuring the execution time and timezone, and setting age-based backup retention.
- Package scheduler command with overlap protection and retention cleanup after a successful scheduled backup.
- Encrypted persistent storage settings and initial native Google OAuth 2.0 / Drive API connection management without an external Google Drive Composer package. Google Drive is not yet a selectable backup destination in this release.

### Notes
- The host application must run Laravel's scheduler every minute for dashboard-configured automatic backups.


## [0.6.2] - 2026-11-23

Release: v0.6.2

- Fix Recent Backups list markup so all records share one list container and separators render correctly.
- Rebuild backup rows as a consistent responsive grid with metadata, size, and actions on dedicated rows.
- Improve narrow-screen wrapping for long artifact names and action buttons.


All notable changes to this project are documented in this file.

## [0.6.1] - 2026-11-23

### Fixed
- Rework backup list action controls into a responsive, wrapping grid row so artifact downloads, verification, and deletion remain consistently styled and usable.
- Add explicit light- and dark-theme styles for verify and delete actions.

### Improved
- Append a configurable version query parameter to dashboard CSS and JavaScript URLs to invalidate stale browser caches.
- Add feature coverage for versioned dashboard asset URLs.

## [0.6.0] - 2026-11-23

### Added
- Turkish README documentation with the same installation, configuration, usage, storage, queue, encryption, restore, and security guidance as the English README.
- Request-based dashboard authorization callback through the `BackupManager` facade.
- Laravel Pint configuration using the Laravel preset.
- Barryvdh Laravel IDE Helper as a development dependency, with Composer generation scripts.
- Composer dependency audit in CI.
- Regression tests for dashboard authorization and MySQL database identifier validation.

### Security
- Reject MySQL database identifiers that could be interpreted as command-line options by `mysqldump` or `mysql`.

## [0.5.0] - 2026-11-23

### Added
- Dashboard downloads for backup artifacts and manifests.
- Backup deletion across the configured storage disk and local mirror.
- Automatic local mirroring when backups are uploaded to remote disks.
- Background dashboard backup jobs with status polling.
- Optional Laravel queue connection and queue name configuration.

### Improved
- Retention pruning removes local and remote copies together.
- Backup operation messages are translated in all six dashboard languages.

## [0.3.0] - 2026-11-23

### Added
- Six dashboard languages: English, Turkish, Spanish, Simplified Chinese, Hindi, and Arabic.
- Persistent language selection using the Laravel session.
- Right-to-left dashboard layout for Arabic.
- Light, dark, and system theme preferences persisted in the browser.
- Standard Packagist, downloads, PHP, Laravel, CI, and license badges.

### Improved
- Dashboard interface now uses translation resources instead of hard-coded mixed-language labels.
- Backup action and verification feedback is translated.
- Package documentation and summary are fully in English.
- Laravel 13 compatibility is declared alongside Laravel 11 and 12.

