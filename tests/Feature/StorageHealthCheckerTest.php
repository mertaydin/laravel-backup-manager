<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;
use Orchestra\Testbench\TestCase;

final class StorageHealthCheckerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    public function test_it_checks_connection_listing_write_read_and_delete_permissions(): void
    {
        Storage::fake('health-check');

        $result = app(StorageHealthChecker::class)->check('health-check');

        $this->assertTrue($result['successful']);
        $this->assertSame([
            'connection' => true,
            'list' => true,
            'write' => true,
            'read' => true,
            'delete' => true,
        ], $result['checks']);
        $this->assertSame([], Storage::disk('health-check')->allFiles('.backup-manager-health'));
    }

    public function test_dashboard_exposes_storage_health_check_endpoint(): void
    {
        Storage::fake('local');
        $this->app['config']->set('backup-manager.ui.middleware', ['web']);

        $this->post('/backup-manager/storage/test', ['disk' => 'local'])
            ->assertRedirect()
            ->assertSessionHas('storage_success');
    }
}
