<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Support\ServiceProvider;
use MertAydin\LaravelBackupManager\Console\Commands\RunBackupCommand;
use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use MertAydin\LaravelBackupManager\Drivers\LocalBackupDriver;
use MertAydin\LaravelBackupManager\Files\ZipArchiver;

class BackupManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backup-manager.php', 'backup-manager');

        $this->app->singleton(MySqlDumper::class);
        $this->app->singleton(ZipArchiver::class);
        $this->app->singleton(BackupManager::class, function ($app): BackupManager {
            $manager = new BackupManager();
            $manager->extend('local', $app->make(LocalBackupDriver::class));

            return $manager;
        });
        $this->app->alias(BackupManager::class, 'backup-manager');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/backup-manager.php' => config_path('backup-manager.php'),
        ], 'backup-manager-config');

        if ($this->app->runningInConsole()) {
            $this->commands([RunBackupCommand::class]);
        }
    }
}
