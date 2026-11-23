# Changelog

All notable changes to this project are documented in this file.

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

