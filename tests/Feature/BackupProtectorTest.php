<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupDeleter;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupProtector;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class BackupProtectorTest extends TestCase
{
    private const BACKUP_ID = '20261123_120000_aabbccdd';

    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup-test');
        config([
            'backup-manager.default' => 'default',
            'backup-manager.profiles.default.storage.disk' => 'backup-test',
            'backup-manager.profiles.default.storage.local_disk' => 'backup-test',
            'backup-manager.profiles.default.storage.disks' => [],
            'backup-manager.profiles.default.storage.path' => 'backups',
        ]);

        Storage::disk('backup-test')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode([
            'id' => self::BACKUP_ID,
            'created_at' => now()->toIso8601String(),
            'artifacts' => [],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_protected_backups_cannot_be_deleted_until_unlocked(): void
    {
        app(BackupProtector::class)->update(self::BACKUP_ID, locked: true, reason: 'Compliance');

        $manifest = json_decode(Storage::disk('backup-test')->get('backups/'.self::BACKUP_ID.'/manifest.json'), true);
        self::assertTrue($manifest['protection']['locked']);
        self::assertSame('Compliance', $manifest['protection']['reason']);

        try {
            app(BackupDeleter::class)->delete(self::BACKUP_ID);
            self::fail('Expected a protected backup deletion to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('protected from deletion', $exception->getMessage());
        }

        app(BackupProtector::class)->update(self::BACKUP_ID, locked: false);
        $result = app(BackupDeleter::class)->delete(self::BACKUP_ID);

        self::assertSame(['backup-test'], $result['deleted']);
        Storage::disk('backup-test')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
    }
}
