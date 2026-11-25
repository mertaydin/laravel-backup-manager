<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupDeleter;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class BackupDeleterTest extends TestCase
{
    private const BACKUP_ID = '20261123_120000_aabbccdd';

    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup-local');
        Storage::fake('backup-r2');
        config([
            'backup-manager.default' => 'default',
            'backup-manager.profiles.default.storage.disk' => 'backup-local',
            'backup-manager.profiles.default.storage.local_disk' => 'backup-local',
            'backup-manager.profiles.default.storage.disks' => ['backup-r2'],
            'backup-manager.profiles.default.storage.path' => 'backups',
        ]);
    }

    public function test_it_deletes_backup_objects_from_local_and_r2_targets(): void
    {
        $this->storeBackup('backup-local');
        $this->storeBackup('backup-r2');

        $result = app(BackupDeleter::class)->delete(self::BACKUP_ID);

        self::assertSame(['backup-local', 'backup-r2'], $result['deleted']);
        self::assertSame([], $result['failed']);
        Storage::disk('backup-local')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
        Storage::disk('backup-r2')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
    }

    public function test_it_deletes_canonical_backup_manager_path_with_legacy_published_config(): void
    {
        $path = 'backup-manager/backups/'.self::BACKUP_ID;
        foreach (['backup-local', 'backup-r2'] as $disk) {
            Storage::disk($disk)->put($path.'/manifest.json', json_encode([
                'id' => self::BACKUP_ID,
                'protection' => [],
                'artifacts' => [],
            ], JSON_THROW_ON_ERROR));
            Storage::disk($disk)->put($path.'/database.sql.gz', 'backup');
        }

        $result = app(BackupDeleter::class)->delete(self::BACKUP_ID);

        self::assertSame(['backup-local', 'backup-r2'], $result['deleted']);
        self::assertSame([], $result['failed']);
        Storage::disk('backup-local')->assertMissing($path.'/manifest.json');
        Storage::disk('backup-r2')->assertMissing($path.'/manifest.json');
    }

    public function test_it_deletes_secondary_copy_when_primary_copy_is_already_missing(): void
    {
        $this->storeBackup('backup-r2');

        $result = app(BackupDeleter::class)->delete(self::BACKUP_ID);

        self::assertSame(['backup-r2'], $result['deleted']);
        self::assertSame([], $result['failed']);
        Storage::disk('backup-r2')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
    }

    public function test_it_does_not_delete_any_copy_when_a_replica_is_protected(): void
    {
        $this->storeBackup('backup-local');
        $this->storeBackup('backup-r2', ['locked' => true, 'reason' => 'Keep this copy']);

        try {
            app(BackupDeleter::class)->delete(self::BACKUP_ID);
            self::fail('Expected protected backup deletion to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('protected from deletion', $exception->getMessage());
        }

        Storage::disk('backup-local')->assertExists('backups/'.self::BACKUP_ID.'/manifest.json');
        Storage::disk('backup-r2')->assertExists('backups/'.self::BACKUP_ID.'/manifest.json');
    }

    private function storeBackup(string $disk, array $protection = []): void
    {
        $path = 'backups/'.self::BACKUP_ID;
        Storage::disk($disk)->put($path.'/database.sql.gz', gzencode('database payload'));
        Storage::disk($disk)->put($path.'/manifest.json', json_encode([
            'id' => self::BACKUP_ID,
            'created_at' => '2026-11-23T12:00:00+00:00',
            'protection' => $protection,
            'artifacts' => [
                'database.sql.gz' => [
                    'size' => Storage::disk($disk)->size($path.'/database.sql.gz'),
                    'sha256' => hash('sha256', gzencode('database payload')),
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
