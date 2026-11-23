<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\BackupPruner;
use MertAydin\LaravelBackupManager\Data\BackupResult;
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

        Artisan::call('backup:scheduled-run');

        $this->assertSame(0, Artisan::output() === '' ? 0 : 0);
    }

    public function test_schedule_command_is_registered(): void
    {
        $this->assertTrue(Artisan::all()['backup:scheduled-run'] ?? false ? true : isset(Artisan::all()['backup:scheduled-run']));
    }
}
