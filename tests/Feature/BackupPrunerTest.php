<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupPruner;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class BackupPrunerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
        Storage::fake('backup-test');
        config([
            'backup-manager.default' => 'default',
            'backup-manager.profiles.default.storage.disk' => 'backup-test',
            'backup-manager.profiles.default.storage.path' => 'backups',
            'backup-manager.profiles.default.retention.enabled' => true,
            'backup-manager.profiles.default.retention.keep_days' => 30,
            'backup-manager.profiles.default.retention.keep_last' => 1,
        ]);

        $this->storeBackup('20261120_120000_aaaaaaaa', '2026-11-20T12:00:00+00:00');
        $this->storeBackup('20261010_120000_bbbbbbbb', '2026-10-10T12:00:00+00:00');
        $this->storeBackup('20260901_120000_cccccccc', '2026-09-01T12:00:00+00:00');
        Storage::disk('backup-test')->put('backups/invalid_id/manifest.json', '{invalid json');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_previews_expired_backups_without_deleting_them(): void
    {
        $result = app(BackupPruner::class)->run();

        self::assertCount(2, $result['candidates']);
        self::assertSame(1, $result['protected_count']);
        self::assertSame(1, $result['invalid_count']);
        self::assertSame('backups/20261010_120000_bbbbbbbb/manifest.json', 'backups/'.$result['candidates'][0]['id'].'/manifest.json');
        self::assertTrue(Storage::disk('backup-test')->exists('backups/20261010_120000_bbbbbbbb/manifest.json'));
        self::assertSame([], $result['deleted']);
    }

    public function test_it_deletes_only_expired_backups_when_execution_is_enabled(): void
    {
        $result = app(BackupPruner::class)->run(execute: true);

        self::assertCount(2, $result['deleted']);
        self::assertSame([], $result['failed']);
        self::assertTrue(Storage::disk('backup-test')->exists('backups/20261120_120000_aaaaaaaa/manifest.json'));
        self::assertFalse(Storage::disk('backup-test')->exists('backups/20261010_120000_bbbbbbbb/manifest.json'));
        self::assertFalse(Storage::disk('backup-test')->exists('backups/20260901_120000_cccccccc/manifest.json'));
        self::assertTrue(Storage::disk('backup-test')->exists('backups/invalid_id/manifest.json'));
    }

    public function test_it_refuses_to_run_when_retention_is_disabled(): void
    {
        config(['backup-manager.profiles.default.retention.enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(BackupPruner::class)->run();
    }

    private function storeBackup(string $id, string $createdAt): void
    {
        Storage::disk('backup-test')->put(
            'backups/'.$id.'/database.sql.gz',
            'backup-'.$id
        );
        Storage::disk('backup-test')->put(
            'backups/'.$id.'/manifest.json',
            json_encode([
                'id' => $id,
                'created_at' => $createdAt,
                'profile' => 'default',
                'artifacts' => [
                    'database.sql.gz' => [
                        'size' => strlen('backup-'.$id),
                        'sha256' => hash('sha256', 'backup-'.$id),
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
    }
}
