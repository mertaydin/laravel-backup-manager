<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Mockery;
use Orchestra\Testbench\TestCase;

final class ScheduledBackupCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\MertAydin\LaravelBackupManager\BackupManagerServiceProvider::class];
    }

    public function test_disabled_schedule_does_not_run_a_backup(): void
    {
        $settings = Mockery::mock(StorageSettingsRepository::class);
        $settings->shouldReceive('all')->once()->andReturn(['schedule' => ['enabled' => false]]);
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldNotReceive('run');

        $this->app->instance(StorageSettingsRepository::class, $settings);
        $this->app->instance(BackupManager::class, $manager);

        $this->artisan('backup:scheduled-run')->assertExitCode(0);
    }
}
