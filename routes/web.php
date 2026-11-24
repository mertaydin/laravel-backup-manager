<?php

use Illuminate\Support\Facades\Route;
use MertAydin\LaravelBackupManager\Http\Controllers\DashboardController;
use MertAydin\LaravelBackupManager\Http\Controllers\ReportsController;
use MertAydin\LaravelBackupManager\Http\Controllers\StorageManagementController;
use MertAydin\LaravelBackupManager\Http\Middleware\AuthorizeDashboard;
use MertAydin\LaravelBackupManager\Http\Middleware\SetDashboardLocale;

Route::middleware(array_merge(
    config('backup-manager.ui.middleware', ['web', 'auth']),
    [AuthorizeDashboard::class, SetDashboardLocale::class],
))
    ->prefix(config('backup-manager.ui.prefix', 'backup-manager'))
    ->name('backup-manager.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/storage', [StorageManagementController::class, 'index'])->name('storage');
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::get('/reports/export.csv', [ReportsController::class, 'exportCsv'])->name('reports.export.csv');
        Route::post('/storage/schedule', [StorageManagementController::class, 'saveSchedule'])->name('storage.schedule');
        Route::post('/storage/targets', [StorageManagementController::class, 'saveTargets'])->name('storage.targets');
        Route::post('/storage/test', [StorageManagementController::class, 'testDisk'])->name('storage.test');
        Route::post('/storage/retention-preview', [StorageManagementController::class, 'previewRetention'])->name('storage.retention-preview');
        Route::post('/storage/google/credentials', [StorageManagementController::class, 'saveGoogleCredentials'])->name('storage.google.credentials');
        Route::post('/storage/google/connect', [StorageManagementController::class, 'connectGoogle'])->name('storage.google.connect');
        Route::get('/storage/google/callback', [StorageManagementController::class, 'googleCallback'])->name('storage.google.callback');
        Route::post('/storage/google/test', [StorageManagementController::class, 'testGoogle'])->name('storage.google.test');
        Route::get('/locale/{locale}', [DashboardController::class, 'setLocale'])
            ->whereIn('locale', SetDashboardLocale::SUPPORTED_LOCALES)
            ->name('locale');
        Route::post('/run', [DashboardController::class, 'run'])->name('run');
        Route::post('/bulk', [DashboardController::class, 'bulk'])->name('bulk');
        Route::post('/retry-last', [DashboardController::class, 'retryLastFailed'])->name('retry-last');
        Route::post('/{id}/copy', [DashboardController::class, 'copyToDisk'])->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')->name('copy');
        Route::post('/{id}/repeat', [DashboardController::class, 'repeat'])->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')->name('repeat');
        Route::put('/{id}/metadata', [DashboardController::class, 'updateMetadata'])->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')->name('metadata');
        Route::get('/{id}/download.zip', [DashboardController::class, 'downloadArchive'])->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')->name('download.archive');
        Route::get('/{id}', [DashboardController::class, 'show'])
            ->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')
            ->name('details');
        Route::get('/jobs/{jobId}', [DashboardController::class, 'jobStatus'])->name('jobs.status');
        Route::get('/{id}/download/{artifact}', [DashboardController::class, 'download'])
            ->where(['id' => '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}', 'artifact' => '[A-Za-z0-9_.-]+'])
            ->name('download');
        Route::delete('/{id}', [DashboardController::class, 'delete'])
            ->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')
            ->name('delete');
        Route::post('/{id}/verify', [DashboardController::class, 'verify'])
            ->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')
            ->name('verify');
        Route::post('/{id}/protection', [DashboardController::class, 'protection'])
            ->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')
            ->name('protection');
    });
