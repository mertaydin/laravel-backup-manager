<?php

use Illuminate\Support\Facades\Route;
use MertAydin\LaravelBackupManager\Http\Controllers\DashboardController;
use MertAydin\LaravelBackupManager\Http\Middleware\SetDashboardLocale;

Route::middleware(array_merge(config('backup-manager.ui.middleware', ['web', 'auth']), [SetDashboardLocale::class]))
    ->prefix(config('backup-manager.ui.prefix', 'backup-manager'))
    ->name('backup-manager.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/locale/{locale}', [DashboardController::class, 'setLocale'])
            ->whereIn('locale', SetDashboardLocale::SUPPORTED_LOCALES)
            ->name('locale');
        Route::post('/run', [DashboardController::class, 'run'])->name('run');
        Route::post('/{id}/verify', [DashboardController::class, 'verify'])->where('id', '[0-9]{8}_[0-9]{6}_[a-f0-9]{8}')->name('verify');
    });
