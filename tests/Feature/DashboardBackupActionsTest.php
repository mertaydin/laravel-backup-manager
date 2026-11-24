<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupPreflight;
use MertAydin\LaravelBackupManager\Facades\BackupManager as BackupManagerFacade;
use MertAydin\LaravelBackupManager\Jobs\CreateBackupJob;
use MertAydin\LaravelBackupManager\Storage\StorageSettingsRepository;
use Orchestra\Testbench\TestCase;

final class DashboardBackupActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BACKUP_ID = '20261123_120000_a1b2c3d4';

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
        $app['config']->set('backup-manager.profiles.default.storage.disk', 's3');
        $app['config']->set('backup-manager.profiles.default.storage.local_disk', 'local');
        $app['config']->set('backup-manager.profiles.default.storage.path', 'backups');
    }

    public function test_dashboard_authorization_callback_can_deny_access(): void
    {
        BackupManagerFacade::auth(static fn (Request $request): bool => false);

        $this->get('/backup-manager')
            ->assertForbidden();
    }

    public function test_dashboard_authorization_callback_receives_the_request(): void
    {
        Storage::fake('s3');
        BackupManagerFacade::auth(static fn (Request $request): bool => $request->path() === 'backup-manager');

        $this->get('/backup-manager')
            ->assertOk()
            ->assertSee('dashboard.css?v=0.8.11-0.8.8')
            ->assertSee('dashboard.js?v=0.8.11-0.8.8');
    }

    public function test_dashboard_locale_selection_persists_for_every_supported_language(): void
    {
        $locales = [
            'en' => 'en',
            'tr' => 'tr',
            'es' => 'es',
            'zh_CN' => 'zh-CN',
            'hi' => 'hi',
            'ar' => 'ar',
        ];

        foreach ($locales as $locale => $htmlLocale) {
            $this->withHeaders(['referer' => url('/backup-manager')])
                ->get('/backup-manager/locale/'.$locale)
                ->assertRedirect()
                ->assertSessionHas('backup_manager_locale', $locale);

            $this->get('/backup-manager')
                ->assertOk()
                ->assertSee('<html lang="'.$htmlLocale.'"', false)
                ->assertSee(Lang::get('backup-manager::dashboard.dashboard_title', [], $locale));
        }

        $this->get('/backup-manager/locale/unsupported')
            ->assertNotFound();
    }

    public function test_delete_removes_backup_from_remote_and_local_disks(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        foreach (['s3', 'local'] as $disk) {
            Storage::disk($disk)->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode(['id' => self::BACKUP_ID]));
            Storage::disk($disk)->put('backups/'.self::BACKUP_ID.'/database.sql.gz', 'backup');
        }

        $this->delete('/backup-manager/'.self::BACKUP_ID)
            ->assertRedirect();

        Storage::disk('s3')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
        Storage::disk('local')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
    }

    public function test_download_streams_only_an_artifact_listed_in_manifest(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        $manifest = [
            'id' => self::BACKUP_ID,
            'artifacts' => ['database.sql.gz' => ['size' => 6, 'sha256' => hash('sha256', 'backup')]],
        ];
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode($manifest));
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/database.sql.gz', 'backup');

        $this->get('/backup-manager/'.self::BACKUP_ID.'/download/database.sql.gz')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_dashboard_dispatches_backup_job_to_configured_queue(): void
    {
        Queue::fake();
        $this->instance(BackupPreflight::class, new class extends BackupPreflight
        {
            public function __construct() {}

            public function inspect(array $options = []): array
            {
                return ['ready' => true, 'checks' => [], 'errors' => []];
            }
        });
        config(['backup-manager.queue.connection' => 'database']);

        $this->withSession([])
            ->post('/backup-manager/run', ['database' => 1])
            ->assertRedirect()
            ->assertSessionHas('backup_job_id');

        Queue::assertPushed(CreateBackupJob::class);
    }

    public function test_dashboard_accepts_backup_without_optional_metadata(): void
    {
        Queue::fake();
        $this->instance(BackupPreflight::class, new class extends BackupPreflight
        {
            public function __construct() {}

            public function inspect(array $options = []): array
            {
                return ['ready' => true, 'checks' => [], 'errors' => []];
            }
        });
        config(['backup-manager.queue.connection' => 'database']);

        $this->post('/backup-manager/run', [
            'database' => 1,
            'label' => '',
            'note' => '',
            'tags' => [''],
        ])->assertRedirect()
            ->assertSessionHas('backup_job_id')
            ->assertSessionHasNoErrors();

        Queue::assertPushed(CreateBackupJob::class, static fn (CreateBackupJob $job): bool => ($job->options['label'] ?? null) === null
            && ($job->options['note'] ?? null) === null
            && ($job->options['tags'] ?? []) === []);
    }

    public function test_backup_details_page_displays_manifest_metadata_and_checksums(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        $checksum = hash('sha256', 'backup');
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode([
            'id' => self::BACKUP_ID,
            'created_at' => '2026-11-23T12:00:00+00:00',
            'profile' => 'default',
            'label' => 'Before deployment',
            'note' => 'Release preparation',
            'tags' => ['production', 'release'],
            'type' => 'manual',
            'artifacts' => ['database.sql.gz' => ['size' => 6, 'sha256' => $checksum]],
        ]));
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/database.sql.gz', 'backup');

        $this->withSession(['backup_manager_locale' => 'tr'])
            ->get('/backup-manager/'.self::BACKUP_ID)
            ->assertOk()
            ->assertSee('Yedek ayrıntıları')
            ->assertSee('Türkçe')
            ->assertSee('data-locale-switcher', false)
            ->assertDontSee('onchange=', false)
            ->assertSee('dashboard.css?v=0.8.11-0.8.8')
            ->assertSee('details-panel')
            ->assertSee('details-checksum')
            ->assertSee('Before deployment')
            ->assertSee('Release preparation')
            ->assertSee('production, release')
            ->assertSee($checksum);
    }

    public function test_download_falls_back_to_local_mirror_when_remote_copy_is_missing(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        $manifest = [
            'id' => self::BACKUP_ID,
            'artifacts' => ['database.sql.gz' => ['size' => 6, 'sha256' => hash('sha256', 'backup')]],
        ];
        Storage::disk('local')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode($manifest));
        Storage::disk('local')->put('backups/'.self::BACKUP_ID.'/database.sql.gz', 'backup');

        $this->get('/backup-manager/'.self::BACKUP_ID.'/download/database.sql.gz')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_download_rejects_artifacts_not_in_manifest(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode([
            'id' => self::BACKUP_ID,
            'artifacts' => [],
        ]));

        $this->get('/backup-manager/'.self::BACKUP_ID.'/download/.env')
            ->assertNotFound();
    }

    public function test_dashboard_can_lock_and_unlock_a_backup_from_all_configured_disks(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        $manifest = [
            'id' => self::BACKUP_ID,
            'created_at' => now()->toIso8601String(),
            'artifacts' => [],
        ];
        foreach (['s3', 'local'] as $disk) {
            Storage::disk($disk)->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        }

        $this->post('/backup-manager/'.self::BACKUP_ID.'/protection', [
            'reason' => 'Legal retention',
        ])->assertRedirect()
            ->assertSessionHas('backup_success');

        foreach (['s3', 'local'] as $disk) {
            $stored = json_decode(Storage::disk($disk)->get('backups/'.self::BACKUP_ID.'/manifest.json'), true);
            self::assertTrue($stored['protection']['locked']);
            self::assertSame('Legal retention', $stored['protection']['reason']);
        }

        $this->delete('/backup-manager/'.self::BACKUP_ID)
            ->assertRedirect()
            ->assertSessionHas('backup_error');
        Storage::disk('s3')->assertExists('backups/'.self::BACKUP_ID.'/manifest.json');
        Storage::disk('local')->assertExists('backups/'.self::BACKUP_ID.'/manifest.json');

        $this->post('/backup-manager/'.self::BACKUP_ID.'/protection', ['unlock' => 1])
            ->assertRedirect()
            ->assertSessionHas('backup_success');

        foreach (['s3', 'local'] as $disk) {
            $stored = json_decode(Storage::disk($disk)->get('backups/'.self::BACKUP_ID.'/manifest.json'), true);
            self::assertFalse($stored['protection']['locked']);
        }

        $this->delete('/backup-manager/'.self::BACKUP_ID)
            ->assertRedirect()
            ->assertSessionHas('backup_success');
        Storage::disk('s3')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
        Storage::disk('local')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
    }

    public function test_dashboard_renders_backup_protection_controls_and_status(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode([
            'id' => self::BACKUP_ID,
            'created_at' => now()->toIso8601String(),
            'profile' => 'default',
            'protection' => ['locked' => true, 'reason' => 'Compliance'],
            'artifacts' => [],
        ], JSON_THROW_ON_ERROR));

        $this->get('/backup-manager')
            ->assertOk()
            ->assertSee('Protected')
            ->assertSee('Compliance')
            ->assertSee('/backup-manager/'.self::BACKUP_ID.'/protection');
    }

    public function test_dashboard_can_select_google_drive_as_the_only_backup_destination(): void
    {
        $settings = app(StorageSettingsRepository::class);
        $settings->putDisk('google-drive', [
            'refresh_token' => 'refresh-token',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);

        $this->post('/backup-manager/storage/targets', [
            'targets' => ['google-drive'],
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('storage_success');

        self::assertSame(['google-drive'], $settings->all()['backup_targets']);
    }
}
