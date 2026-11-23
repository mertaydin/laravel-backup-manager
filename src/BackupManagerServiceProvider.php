<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Support\ServiceProvider;

class BackupManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backup-manager.php', 'backup-manager');

        $this->app->singleton(BackupManager::class);
        $this->app->alias(BackupManager::class, 'backup-manager');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/backup-manager.php' => config_path('backup-manager.php'),
        ], 'backup-manager-config');
    }
}
