<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

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
            ->assertSee('backup-manager-theme')
            ->assertSee('dashboard-language')
            ->assertSee('Total backups');
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
