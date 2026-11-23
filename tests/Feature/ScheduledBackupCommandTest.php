<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Orchestra\Testbench\TestCase;

final class ScheduledBackupCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    public function test_disabled_schedule_does_not_run_a_backup(): void
    {
        Schema::create('backup_manager_storage_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->longText('value');
            $table->timestamps();
        });

        app(StorageSettingsRepository::class)->save([
            'active_disk' => 'local',
            'disks' => [],
            'schedule' => ['enabled' => false],
        ]);

        $this->artisan('backup:scheduled-run')->assertExitCode(0);
    }
}
