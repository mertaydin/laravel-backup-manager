<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use MertAydin\LaravelBackupManager\BackupInspector;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\Http\Middleware\SetDashboardLocale;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly BackupInspector $inspector,
        private readonly BackupManager $backups,
    ) {
    }

    public function index(): View
    {
        $profileName = (string) config('backup-manager.default', 'default');
        $profile = config('backup-manager.profiles.'.$profileName, []);
        $diskName = (string) data_get($profile, 'storage.disk', 'local');
        $backups = collect($this->inspector->list($profileName, $diskName, 100))
            ->map(static function (array $backup): array {
                $backup['size'] = (int) ($backup['total_size'] ?? 0);

                return $backup;
            });

        return view('backup-manager::dashboard', [
            'backups' => $backups,
            'totalBackups' => $backups->count(),
            'totalSize' => $backups->sum('size'),
            'latestBackup' => $backups->first(),
            'diskName' => $diskName,
            'profileName' => $profileName,
            'databaseEnabled' => (bool) data_get($profile, 'database.enabled', false),
            'filesEnabled' => (bool) data_get($profile, 'files.enabled', false),
        ]);
    }


    public function setLocale(string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetDashboardLocale::SUPPORTED_LOCALES, true), 404);

        request()->session()->put('backup_manager_locale', $locale);

        return redirect()->back();
    }

    public function run(): RedirectResponse
    {
        $validated = request()->validate([
            'database' => ['nullable', 'boolean'],
            'files' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['database']) && empty($validated['files'])) {
            return back()->with('backup_error', __('backup-manager::dashboard.choose_component'));
        }

        try {
            $result = $this->backups->run([
                'database' => (bool) ($validated['database'] ?? false),
                'files' => (bool) ($validated['files'] ?? false),
            ]);

            if (! $result->successful) {
                return back()->with('backup_error', $result->error ?? __('backup-manager::dashboard.backup_failed'));
            }

            return back()->with('backup_success', __('backup-manager::dashboard.backup_created', ['id' => $result->id]));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('backup_error', __('backup-manager::dashboard.backup_unexpected'));
        }
    }

    public function verify(string $id): RedirectResponse
    {
        $profileName = (string) config('backup-manager.default', 'default');
        $profile = config('backup-manager.profiles.'.$profileName, []);

        try {
            $result = $this->inspector->verify(
                $id,
                $profileName,
                (string) data_get($profile, 'storage.disk', 'local')
            );

            if (! $result['valid']) {
                return back()->with('backup_error', __('backup-manager::dashboard.verification_failed', ['errors' => implode(' ', $result['errors'])]));
            }

            return back()->with('backup_success', __('backup-manager::dashboard.verification_success', ['id' => $id]));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('backup_error', __('backup-manager::dashboard.verification_error'));
        }
    }
}
