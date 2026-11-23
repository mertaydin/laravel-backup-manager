<?php

use Illuminate\Support\Facades\Route;
use MertAydin\LaravelBackupManager\Http\Controllers\DashboardController;

Route::middleware(config('backup-manager.ui.middleware', ['web', 'auth']))
    ->prefix(config('backup-manager.ui.prefix', 'backup-manager'))
    ->name('backup-manager.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/run', [DashboardController::class, 'run'])->name('run');
    });
