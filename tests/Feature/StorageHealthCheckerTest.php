<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\Storage\StorageHealthChecker;
use Orchestra\Testbench\TestCase;

final class StorageHealthCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('backup_manager_storage_settings')) {
            $this->artisan('migrate', [
                '--path' => dirname(__DIR__, 2).'/database/migrations',
                '--realpath' => true,
            ])->assertExitCode(0);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('backup-manager.ui.middleware', ['web']);
        $app['config']->set('backup-manager.profiles.default.storage.path', 'backups');
    }

    public function test_storage_management_page_renders_with_current_stylesheet_and_controls(): void
    {
        $this->withSession(['backup_manager_locale' => 'tr'])
            ->get('/backup-manager/storage')
            ->assertOk()
            ->assertSee('Depolama Yönetimi')
            ->assertSee('Türkçe')
            ->assertSee('dashboard.css?v=0.8.8-0.8.8')
            ->assertSee('dashboard.js?v=0.8.8-0.8.8')
            ->assertSee('data-locale-switcher', false)
            ->assertDontSee('onchange=', false)
            ->assertSee('class="shell storage-page"', false)
            ->assertSee('class="storage-card"', false)
            ->assertSee('OAuth İstemci Kimliği')
            ->assertSee('Otomatik yedekleme ayarlarını kaydet')
            ->assertSee('Yedekleme hedefleri')
            ->assertSee('/backup-manager/storage/targets');

        $css = file_get_contents(dirname(__DIR__, 2).'/resources/dist/css/dashboard.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('.storage-card', $css);
        $this->assertStringContainsString('.storage-actions', $css);
        $this->assertStringContainsString('@media(max-width:650px)', $css);
    }

    public function test_it_checks_connection_listing_write_read_and_delete_permissions(): void
    {
        config(['filesystems.disks.health-check' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/health-check')]]);
        Storage::fake('health-check');

        $result = app(StorageHealthChecker::class)->check('health-check');

        $this->assertTrue($result['successful']);
        $this->assertSame([
            'connection' => true,
            'list' => true,
            'write' => true,
            'read' => true,
            'delete' => true,
        ], $result['checks']);
        $this->assertSame([], Storage::disk('health-check')->allFiles('backups/.backup-manager-health'));
    }

    public function test_dashboard_exposes_storage_health_check_endpoint(): void
    {
        Storage::fake('local');
        $this->app['config']->set('backup-manager.ui.middleware', ['web']);

        $this->post('/backup-manager/storage/test', ['disk' => 'local'])
            ->assertRedirect()
            ->assertSessionHas('storage_success');
    }
}
