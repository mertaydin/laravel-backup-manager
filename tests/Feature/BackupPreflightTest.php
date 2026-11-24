<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupPreflight;
use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\ExecutableFinder;

final class BackupPreflightTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('backup-manager.temporary_directory', sys_get_temp_dir());
        $app['config']->set('backup-manager.preflight.minimum_free_bytes', PHP_INT_MAX);
        $app['config']->set('backup-manager.profiles.default.storage.disk', 'local');
    }

    public function test_preflight_rejects_a_temporary_filesystem_below_the_configured_free_space_minimum(): void
    {
        $health = \Mockery::mock(StorageHealthChecker::class);
        $health->shouldReceive('check')->andReturn([
            'successful' => true,
            'checks' => ['connection' => true, 'list' => true, 'write' => true, 'read' => true, 'delete' => true],
        ]);
        $this->app->instance(StorageHealthChecker::class, $health);

        $result = app(BackupPreflight::class)->inspect(['database' => false, 'files' => false]);

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['checks']['temporary_free_space']);
        $this->assertStringContainsString('at least '.PHP_INT_MAX.' bytes', implode(' ', $result['errors']));
    }
}
