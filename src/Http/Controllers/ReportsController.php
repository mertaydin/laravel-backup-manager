<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use MertAydin\LaravelBackupManager\BackupInspector;
use Throwable;

final class ReportsController extends Controller
{
    public function __construct(
        private readonly BackupInspector $inspector,
        private readonly ConfigRepository $config,
    ) {}

    public function index(): View
    {
        $report = $this->reportData();

        return view('backup-manager::reports', $report);
    }

    public function export(): StreamedResponse
    {
        $report = $this->reportData();
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'profile' => $report['profileName'],
            'summary' => $report['summary'],
            'storage' => $report['diskSummary'],
            'backups' => $report['backups']->all(),
        ];

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, 'backup-report-'.now()->format('Ymd-His').'.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /** @return array<string, mixed> */
    private function reportData(): array
    {
        $profileName = (string) $this->config->get('backup-manager.default', 'default');
        $profile = $this->config->get('backup-manager.profiles.'.$profileName, []);
        $diskNames = array_values(array_unique(array_filter([
            (string) data_get($profile, 'storage.disk', 'local'),
            (string) data_get($profile, 'storage.local_disk', 'local'),
            ...array_values(array_filter((array) data_get($profile, 'storage.disks', []), 'is_string')),
        ])));

        $backups = collect();
        $diskSummary = [];
        foreach ($diskNames as $diskName) {
            try {
                $items = collect($this->inspector->list($profileName, $diskName, PHP_INT_MAX));
                $diskSummary[] = [
                    'disk' => $diskName,
                    'total' => $items->count(),
                    'valid' => $items->where('status', 'available')->count(),
                    'invalid' => $items->where('status', 'invalid')->count(),
                    'bytes' => (int) $items->where('status', 'available')->sum('total_size'),
                    'error' => null,
                ];
                $backups = $backups->concat($items);
            } catch (Throwable $exception) {
                report($exception);
                $diskSummary[] = [
                    'disk' => $diskName,
                    'total' => 0,
                    'valid' => 0,
                    'invalid' => 0,
                    'bytes' => 0,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $backups = $backups->sortByDesc(static fn (array $backup): string => (string) ($backup['created_at'] ?? ''))->values();
        $filters = request()->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:available,invalid'],
            'disk' => ['nullable', 'string', 'in:'.implode(',', $diskNames)],
        ]);
        $filtered = $backups
            ->when($filters['from'] ?? null, static fn (Collection $items, string $from) => $items->filter(
                static fn (array $backup): bool => (string) ($backup['created_at'] ?? '') >= $from
            ))
            ->when($filters['to'] ?? null, static fn (Collection $items, string $to) => $items->filter(
                static fn (array $backup): bool => (string) ($backup['created_at'] ?? '') <= $to.'T23:59:59'
            ))
            ->when($filters['status'] ?? null, static fn (Collection $items, string $status) => $items->where('status', $status))
            ->when($filters['disk'] ?? null, static fn (Collection $items, string $disk) => $items->where('disk', $disk))
            ->values();

        $valid = $backups->where('status', 'available');
        $summary = [
            'total' => $backups->count(),
            'valid' => $valid->count(),
            'invalid' => $backups->where('status', 'invalid')->count(),
            'bytes' => (int) $valid->sum('total_size'),
            'latest' => $valid->first(),
            'protected' => $valid->where('protected', true)->count(),
            'scheduled' => $valid->where('type', 'scheduled')->count(),
            'manual' => $valid->where('type', 'manual')->count(),
        ];

        return compact('profileName', 'diskSummary', 'summary', 'filtered', 'filters') + ['backups' => $filtered];
    }
}
