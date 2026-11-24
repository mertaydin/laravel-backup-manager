<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use Orchestra\Testbench\TestCase;

final class DashboardLocalizationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('backup-manager.ui.middleware', ['web']);
    }

    public function test_dashboard_renders_translated_content_and_theme_controls(): void
    {
        $this->get('/backup-manager')
            ->assertOk()
            ->assertSee('Backup dashboard')
            ->assertSee('vendor/backup-manager/css/dashboard.css')
            ->assertSee('vendor/backup-manager/js/dashboard.js')
            ->assertSee('dashboard-language')
            ->assertSee('data-locale-switcher', false)
            ->assertDontSee('onchange=', false)
            ->assertSee('Total backups');
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

    public function test_dashboard_assets_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'backup-manager-assets', '--force' => true])
            ->assertExitCode(0);

        self::assertFileExists(public_path('vendor/backup-manager/css/dashboard.css'));
        self::assertFileExists(public_path('vendor/backup-manager/js/dashboard.js'));
    }

    public function test_user_can_change_dashboard_language_and_keep_it_in_session(): void
    {
        $this->withSession(['_previous.url' => url('/backup-manager')])
            ->get('/backup-manager/locale/tr')
            ->assertRedirect();

        $this->get('/backup-manager')
            ->assertOk()
            ->assertSee('Yedekleme paneli')
            ->assertSee('Toplam yedek');
    }

    public function test_storage_page_renders_translated_language_switcher_and_external_script(): void
    {
        if (! Schema::hasTable('backup_manager_storage_settings')) {
            $this->artisan('migrate', [
                '--path' => dirname(__DIR__, 2).'/database/migrations',
                '--realpath' => true,
            ])->assertExitCode(0);
        }

        $this->withSession(['backup_manager_locale' => 'tr'])
            ->get('/backup-manager/storage')
            ->assertOk()
            ->assertSee('Depolama Yönetimi')
            ->assertSee('data-locale-switcher', false)
            ->assertDontSee('onchange=', false)
            ->assertSee('dashboard.js?v=0.8.13-0.8.13');
    }

    public function test_language_switcher_uses_external_javascript_compatible_with_strict_csp(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2).'/resources/views/dashboard.blade.php');
        $javascript = file_get_contents(dirname(__DIR__, 2).'/resources/dist/js/dashboard.js');

        self::assertIsString($html);
        self::assertIsString($javascript);
        self::assertStringContainsString('data-locale-switcher', $html);
        self::assertStringNotContainsString('onchange=', $html);
        self::assertStringContainsString("addEventListener('change'", $javascript);
        self::assertStringContainsString('window.location.assign(select.value)', $javascript);
    }

    public function test_arabic_dashboard_uses_right_to_left_layout(): void
    {
        $this->withSession(['backup_manager_locale' => 'ar'])
            ->get('/backup-manager')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('لوحة النسخ الاحتياطي');
    }

    public function test_all_six_dashboard_locales_have_translations(): void
    {
        foreach (['en', 'tr', 'es', 'zh_CN', 'hi', 'ar'] as $locale) {
            self::assertNotSame(
                'dashboard_title',
                trans('backup-manager::dashboard.dashboard_title', [], $locale),
                "Missing dashboard title translation for [{$locale}]."
            );
        }
    }
}
