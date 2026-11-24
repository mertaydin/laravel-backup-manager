<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupPreflight;
use Orchestra\Testbench\TestCase;

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
        Storage::fake('local');

        $result = app(BackupPreflight::class)->inspect(['database' => false, 'files' => false]);

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['checks']['temporary_free_space']);
        $this->assertStringContainsString('at least '.PHP_INT_MAX.' bytes', implode(' ', $result['errors']));
    }
}
