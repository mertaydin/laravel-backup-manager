<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class BackupInspectorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    private const BACKUP_ID = '20261123_120000_aabbccdd';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup-test');
        config([
            'backup-manager.default' => 'default',
            'backup-manager.profiles.default.storage.disk' => 'backup-test',
            'backup-manager.profiles.default.storage.path' => 'backups',
        ]);
    }

    public function test_it_lists_backups_from_valid_manifests(): void
    {
        $this->storeBackup('database.sql.gz', 'database payload');

        $backups = app(BackupInspector::class)->list();

        self::assertCount(1, $backups);
        self::assertSame(self::BACKUP_ID, $backups[0]['id']);
        self::assertSame(strlen('database payload'), $backups[0]['total_size']);
        self::assertSame('database.sql.gz', $backups[0]['artifacts'][0]['name']);
    }

    public function test_it_verifies_artifact_checksums_and_sizes(): void
    {
        $this->storeBackup('database.sql.gz', 'database payload');

        $result = app(BackupInspector::class)->verify(self::BACKUP_ID);

        self::assertTrue($result['valid']);
        self::assertTrue($result['artifacts']['database.sql.gz']['valid']);
    }

    public function test_it_detects_modified_backup_artifacts(): void
    {
        $this->storeBackup('database.sql.gz', 'database payload');
        Storage::disk('backup-test')->put(
            'backups/'.self::BACKUP_ID.'/database.sql.gz',
            'tampered payload'
        );

        $result = app(BackupInspector::class)->verify(self::BACKUP_ID);

        self::assertFalse($result['valid']);
        self::assertFalse($result['artifacts']['database.sql.gz']['valid']);
        self::assertNotEmpty($result['errors']);
    }

    public function test_it_rejects_invalid_backup_identifiers(): void
    {
        $this->expectException(RuntimeException::class);

        app(BackupInspector::class)->verify('../latest');
    }

    private function storeBackup(string $name, string $contents): void
    {
        $path = 'backups/'.self::BACKUP_ID.'/'.$name;
        Storage::disk('backup-test')->put($path, $contents);

        Storage::disk('backup-test')->put(
            'backups/'.self::BACKUP_ID.'/manifest.json',
            json_encode([
                'id' => self::BACKUP_ID,
                'created_at' => '2026-11-23T12:00:00+00:00',
                'profile' => 'default',
                'artifacts' => [
                    $name => [
                        'size' => strlen($contents),
                        'sha256' => hash('sha256', $contents),
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
    }
}
