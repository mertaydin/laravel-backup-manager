# Architecture

## Core boundaries

- **BackupManager** resolves named backup drivers and exposes the public API.
- **BackupDriver** coordinates a complete backup profile.
- **BackupStorage** abstracts persistence independently from archive creation.
- **BackupResult** is the immutable result returned by a backup run.

## Planned pipeline

1. Resolve and validate a named profile.
2. Acquire a per-profile lock to prevent overlapping runs.
3. Export the selected database connection to a temporary stream/file.
4. Optionally create an application ZIP archive using include/exclude rules.
5. Build a manifest and SHA-256 checksums for every artifact.
6. Optionally encrypt artifacts before upload.
7. Upload artifacts to the configured storage adapter.
8. Verify remote object size and checksum where the provider supports it.
9. Persist history, release temporary files, and apply retention rules.

## Safety requirements

- Never include .env by default.
- Never log database passwords, cloud credentials, or encryption keys.
- Never mark a partial upload as a successful backup.
- Restore operations must require explicit target confirmation and must not run automatically as part of a normal backup.
- Database exports and archive creation must avoid loading complete payloads into PHP memory.
- Temporary artifacts must be created with restrictive permissions and cleaned up after success or failure.
