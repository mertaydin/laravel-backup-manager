<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupComparator;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupReporter;
use Orchestra\Testbench\TestCase;

final class BackupReportingTest extends TestCase
{
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
            'backup-manager.profiles.default.storage.path' => 'backups',
        ]);
    }

    public function test_it_compares_verified_backup_manifests(): void
    {
        $this->storeBackup('20261123_120000_aaaaaaaa', 'first');
        $this->storeBackup('20261123_130000_bbbbbbbb', 'second');

        $result = app(BackupComparator::class)->compare(
            '20261123_120000_aaaaaaaa',
            '20261123_130000_bbbbbbbb',
        );

        self::assertSame(1, $result['changed_count']);
        self::assertSame('changed', $result['artifacts'][0]['status']);
        self::assertSame(-1, $result['artifacts'][0]['size_delta']);
    }

    public function test_it_generates_a_backup_inventory_report(): void
    {
        $this->storeBackup('20261123_120000_aaaaaaaa', 'first');

        $report = app(BackupReporter::class)->generate();

        self::assertSame(1, $report['total_backups']);
        self::assertSame(1, $report['valid_backups']);
        self::assertSame(0, $report['invalid_backups']);
        self::assertSame('20261123_120000_aaaaaaaa', $report['latest_backup']['id']);
    }

    private function storeBackup(string $id, string $contents): void
    {
        Storage::disk('backup-test')->put('backups/'.$id.'/snapshot.bin', $contents);
        Storage::disk('backup-test')->put('backups/'.$id.'/manifest.json', json_encode([
            'id' => $id,
            'created_at' => $id === '20261123_120000_aaaaaaaa' ? '2026-11-23T12:00:00+00:00' : '2026-11-23T13:00:00+00:00',
            'artifacts' => [
                'snapshot.bin' => ['size' => strlen($contents), 'sha256' => hash('sha256', $contents)],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
