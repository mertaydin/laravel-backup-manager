<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Support\ServiceProvider;
use MertAydin\LaravelBackupManager\Console\Commands\RunBackupCommand;
use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use MertAydin\LaravelBackupManager\Drivers\LocalBackupDriver;
use MertAydin\LaravelBackupManager\Files\ZipArchiver;
use MertAydin\LaravelBackupManager\Http\Controllers\DashboardController;

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

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backup-manager');

        if ($this->app['config']->get('backup-manager.ui.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunBackupCommand::class]);
        }
    }
}
