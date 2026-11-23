<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Illuminate\Support\ServiceProvider;
use MertAydin\LaravelBackupManager\Console\Commands\CompareBackupsCommand;
use MertAydin\LaravelBackupManager\Console\Commands\DecryptBackupCommand;
use MertAydin\LaravelBackupManager\Console\Commands\ListBackupsCommand;
use MertAydin\LaravelBackupManager\Console\Commands\ProtectBackupCommand;
use MertAydin\LaravelBackupManager\Console\Commands\PruneBackupsCommand;
use MertAydin\LaravelBackupManager\Console\Commands\ReportBackupsCommand;
use MertAydin\LaravelBackupManager\Console\Commands\RestoreBackupCommand;
use MertAydin\LaravelBackupManager\Console\Commands\RunBackupCommand;
use MertAydin\LaravelBackupManager\Console\Commands\ScheduledBackupCommand;
use MertAydin\LaravelBackupManager\Console\Commands\VerifyBackupCommand;
use MertAydin\LaravelBackupManager\Database\DatabaseDumper;
use MertAydin\LaravelBackupManager\Database\DatabaseRestorer;
use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use MertAydin\LaravelBackupManager\Database\MySqlRestorer;
use MertAydin\LaravelBackupManager\Database\PostgreSqlDumper;
use MertAydin\LaravelBackupManager\Database\PostgreSqlRestorer;
use MertAydin\LaravelBackupManager\Database\SqliteDumper;
use MertAydin\LaravelBackupManager\Database\SqliteRestorer;
use MertAydin\LaravelBackupManager\Drivers\LocalBackupDriver;
use MertAydin\LaravelBackupManager\Events\BackupCompleted;
use MertAydin\LaravelBackupManager\Events\BackupFailed;
use MertAydin\LaravelBackupManager\Files\ZipArchiver;
use MertAydin\LaravelBackupManager\Files\ZipRestorer;
use MertAydin\LaravelBackupManager\Listeners\SendBackupNotifications;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use MertAydin\LaravelBackupManager\Services\DashboardAuthorizer;
use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;

class BackupManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backup-manager.php', 'backup-manager');

        $this->app->singleton(MySqlDumper::class);
        $this->app->singleton(MySqlRestorer::class);
        $this->app->singleton(PostgreSqlDumper::class);
        $this->app->singleton(PostgreSqlRestorer::class);
        $this->app->singleton(SqliteDumper::class);
        $this->app->singleton(SqliteRestorer::class);
        $this->app->singleton(DatabaseDumper::class);
        $this->app->singleton(DatabaseRestorer::class);
        $this->app->singleton(ZipArchiver::class);
        $this->app->singleton(ZipRestorer::class);
        $this->app->singleton(ArtifactEncryptor::class);
        $this->app->singleton(BackupInspector::class);
        $this->app->singleton(BackupProtector::class);
        $this->app->singleton(BackupComparator::class);
        $this->app->singleton(BackupReporter::class);
        $this->app->singleton(BackupPreflight::class);
        $this->app->singleton(BackupDeleter::class);
        $this->app->singleton(BackupPruner::class);
        $this->app->singleton(BackupRestorer::class);
        $this->app->singleton(DashboardAuthorizer::class);
        $this->app->singleton(StorageSettingsRepository::class);
        $this->app->singleton(StorageHealthChecker::class);
        $this->app->singleton(BackupManager::class, function ($app): BackupManager {
            $manager = new BackupManager;
            $manager->extend('local', $app->make(LocalBackupDriver::class));

            return $manager;
        });
        $this->app->alias(BackupManager::class, 'backup-manager');
    }

    public function boot(): void
    {
        Storage::extend('google-drive', function ($app, array $config): FilesystemAdapter {
            $credentials = $app->make(StorageSettingsRepository::class)->all()['disks']['google-drive'] ?? [];
            foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
                if (empty($credentials[$key])) {
                    throw new \RuntimeException('Google Drive is not connected. Configure and connect it from Backup Manager storage settings.');
                }
            }

            $client = new \\Google\\Client();
            $client->setApplicationName((string) config('app.name', 'Laravel Backup Manager'));
            $client->setClientId((string) $credentials['client_id']);
            $client->setClientSecret((string) $credentials['client_secret']);
            $client->setAccessType('offline');
            $client->setScopes(['https://www.googleapis.com/auth/drive']);
            $client->fetchAccessTokenWithRefreshToken((string) $credentials['refresh_token']);

            $service = new \\Google\\Service\\Drive($client);
            $adapter = new \\Masbug\\Flysystem\\GoogleDriveAdapter(
                $service,
                (string) ($credentials['root_folder_id'] ?? 'root'),
                ['useDisplayPaths' => true],
            );

            return new FilesystemAdapter(new Flysystem($adapter), $adapter);
        });

        Event::listen([BackupCompleted::class, BackupFailed::class], [SendBackupNotifications::class, 'handle']);
        $this->publishes([
            __DIR__.'/../config/backup-manager.php' => config_path('backup-manager.php'),
        ], 'backup-manager-config');

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/backup-manager'),
        ], 'backup-manager-assets');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backup-manager');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'backup-manager');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('backup:scheduled-run')
                ->everyMinute()
                ->withoutOverlapping();
        });

        if ($this->app['config']->get('backup-manager.ui.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBackupCommand::class,
                CompareBackupsCommand::class,
                ReportBackupsCommand::class,
                ScheduledBackupCommand::class,
                RestoreBackupCommand::class,
                DecryptBackupCommand::class,
                ListBackupsCommand::class,
                VerifyBackupCommand::class,
                PruneBackupsCommand::class,
                ProtectBackupCommand::class,
            ]);
        }
    }
}
