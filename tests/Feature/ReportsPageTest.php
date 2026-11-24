<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use Orchestra\Testbench\TestCase;

final class ReportsPageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('backup-manager.ui.middleware', ['web']);
    }

    public function test_reports_page_displays_inventory_summary_and_json_export(): void
    {
        $this->get('/backup-manager/reports')
            ->assertOk()
            ->assertSee('Yedekleme raporları')
            ->assertSee('Depolama hedeflerine göre özet')
            ->assertSee('Yedekleme envanteri')
            ->assertSee('JSON raporunu indir');

        $this->get('/backup-manager/reports/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8')
            ->assertSee('"summary"', false);
    }
}
