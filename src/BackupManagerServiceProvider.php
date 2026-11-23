<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager;

use Illuminate\\Support\\ServiceProvider;
use MertAydin\\LaravelBackupManager\\Console\\Commands\\DecryptBackupCommand;
use MertAydin\\LaravelBackupManager\\Console\\Commands\\ListBackupsCommand;
use MertAydin\\LaravelBackupManager\\Console\\Commands\\PruneBackupsCommand;
use MertAydin\\LaravelBackupManager\\Console\\Commands\\RestoreBackupCommand;
use MertAydin\\LaravelBackupManager\\Console\\Commands\\RunBackupCommand;
use MertAydin\\LaravelBackupManager\\Console\\Commands\\VerifyBackupCommand;
use MertAydin\\LaravelBackupManager\\Database\\MySqlDumper;
use MertAydin\\LaravelBackupManager\\Database\\MySqlRestorer;
use MertAydin\\LaravelBackupManager\\Drivers\\LocalBackupDriver;
use MertAydin\\LaravelBackupManager\\Files\\ZipArchiver;
use MertAydin\\LaravelBackupManager\\Files\\ZipRestorer;
use MertAydin\\LaravelBackupManager\\Http\\Controllers\\DashboardController;
use MertAydin\\LaravelBackupManager\\Security\\ArtifactEncryptor;
use MertAydin\\LaravelBackupManager\\Services\\DashboardAuthorizer;

class BackupManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backup-manager.php', 'backup-manager');

        $this->app->singleton(MySqlDumper::class);
        $this->app->singleton(MySqlRestorer::class);
        $this->app->singleton(ZipArchiver::class);
        $this->app->singleton(ZipRestorer::class);
        $this->app->singleton(ArtifactEncryptor::class);
        $this->app->singleton(BackupInspector::class);
        $this->app->singleton(BackupDeleter::class);
        $this->app->singleton(BackupPruner::class);
        $this->app->singleton(BackupRestorer::class);
        $this->app->singleton(DashboardAuthorizer::class);
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

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/backup-manager'),
        ], 'backup-manager-assets');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backup-manager');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'backup-manager');

        if ($this->app['config']->get('backup-manager.ui.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBackupCommand::class,
                RestoreBackupCommand::class,
                DecryptBackupCommand::class,
                ListBackupsCommand::class,
                VerifyBackupCommand::class,
                PruneBackupsCommand::class,
            ]);
        }
    }
}
