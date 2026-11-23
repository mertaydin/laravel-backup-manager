# Architecture

## Core boundaries

- **BackupManager** resolves named backup drivers and exposes the public API.
- **BackupDriver** coordinates a complete backup profile.
- **BackupStorage** abstracts persistence independently from archive creation.
- **BackupResult** is the immutable result returned by a backup run.

## Backup pipeline

1. Resolve and validate the selected profile.
2. Acquire a cache-backed per-profile lock to prevent overlapping runs.
3. Export MySQL/PostgreSQL SQL or create a consistent SQLite snapshot.
4. Optionally create an application ZIP archive using include/exclude rules.
5. Build a manifest and SHA-256 checksums for each payload artifact.
6. Optionally encrypt artifacts before upload.
7. Upload artifacts to the configured Laravel Filesystem disks, including a local mirror when configured.
8. Verify uploaded artifact sizes, checksums, and supported archive formats.
9. Publish lifecycle events, update the last-run cache status, release temporary files and the profile lock.
10. Apply retention cleanup through the pruning service.

## Safety requirements

- Never include .env by default.
- Never log database passwords, cloud credentials, or encryption keys.
- Never mark a partial upload as a successful backup.
- Restore operations must require explicit target confirmation and must not run automatically as part of a normal backup.
- Database exports and archive creation must avoid loading complete payloads into PHP memory.
- Temporary artifacts must be created with restrictive permissions and cleaned up after success or failure.
