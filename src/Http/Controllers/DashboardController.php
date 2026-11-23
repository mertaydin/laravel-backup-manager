<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use MertAydin\LaravelBackupManager\BackupManager;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly FilesystemFactory $filesystem,
        private readonly BackupManager $backups,
    ) {
    }

    public function index(): View
    {
        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $diskName = (string) data_get($profile, 'storage.disk', 'local');
        $prefix = trim((string) data_get($profile, 'storage.path', 'backups'), '/');
        $disk = $this->filesystem->disk($diskName);
        $files = collect($disk->allFiles($prefix));
        $groups = $files
            ->filter(fn (string $path): bool => str_ends_with($path, '/manifest.json'))
            ->map(function (string $manifestPath) use ($disk, $diskName): array {
                $manifest = json_decode((string) $disk->get($manifestPath), true) ?: [];
                $directory = dirname($manifestPath);
                $artifacts = collect($disk->allFiles($directory))->reject(fn (string $path): bool => str_ends_with($path, '/manifest.json'));

                return [
                    'id' => $manifest['id'] ?? basename($directory),
                    'created_at' => $manifest['created_at'] ?? null,
                    'profile' => $manifest['profile'] ?? 'default',
                    'artifacts' => $artifacts->map(fn (string $path): array => [
                        'name' => basename($path),
                        'path' => $path,
                        'size' => $disk->size($path),
                    ])->values(),
                    'size' => $artifacts->sum(fn (string $path): int => (int) $disk->size($path)),
                    'disk' => $diskName,
                    'manifest' => $manifestPath,
                ];
            })
            ->sortByDesc('created_at')
            ->values();

        return view('backup-manager::dashboard', [
            'backups' => $groups,
            'totalBackups' => $groups->count(),
            'totalSize' => $groups->sum('size'),
            'latestBackup' => $groups->first(),
            'diskName' => $diskName,
            'profileName' => $profileName,
            'databaseEnabled' => (bool) data_get($profile, 'database.enabled', false),
            'filesEnabled' => (bool) data_get($profile, 'files.enabled', false),
        ]);
    }

    public function run(): RedirectResponse
    {
        $validated = request()->validate([
            'database' => ['nullable', 'boolean'],
            'files' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['database']) && empty($validated['files'])) {
            return back()->with('backup_error', 'En az bir yedekleme bileşeni seçmelisiniz.');
        }

        try {
            $result = $this->backups->run([
                'database' => (bool) ($validated['database'] ?? false),
                'files' => (bool) ($validated['files'] ?? false),
            ]);

            if (! $result->successful) {
                return back()->with('backup_error', $result->error ?? 'Yedekleme başarısız oldu.');
            }

            return back()->with('backup_success', 'Yedek başarıyla oluşturuldu: '.$result->id);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('backup_error', 'Yedekleme sırasında beklenmeyen bir hata oluştu. Ayrıntılar uygulama loglarına kaydedildi.');
        }
    }
}
