<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use Orchestra\Testbench\TestCase;

final class BackupConcurrencyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup-manager.default' => 'default',
            'backup-manager.profiles.default.database.enabled' => false,
            'backup-manager.profiles.default.files.enabled' => false,
            'backup-manager.profiles.default.storage.disk' => 'local',
            'backup-manager.profiles.default.storage.local_disk' => 'local',
            'backup-manager.profiles.default.storage.path' => 'backups',
            'backup-manager.temporary_directory' => storage_path('app/backup-manager-test-tmp'),
            'backup-manager.lock_seconds' => 300,
        ]);
    }

    public function test_a_second_backup_for_the_same_profile_is_rejected_while_the_lock_is_held(): void
    {
        $lock = Cache::store()->lock('backup-manager:profile:default', 300);
        self::assertTrue($lock->get());

        try {
            $result = app(BackupManager::class)->run();

            self::assertFalse($result->successful);
            self::assertStringContainsString('Another backup is already running', (string) $result->error);
        } finally {
            $lock->release();
        }
    }
}
