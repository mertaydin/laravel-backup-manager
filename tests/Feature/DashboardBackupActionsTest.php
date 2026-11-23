<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupPreflight;
use MertAydin\LaravelBackupManager\Facades\BackupManager as BackupManagerFacade;
use MertAydin\LaravelBackupManager\Jobs\CreateBackupJob;
use Orchestra\Testbench\TestCase;

final class DashboardBackupActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BACKUP_ID = '20261123_120000_a1b2c3d4';

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
            ->assertSee('dashboard.css?v=0.7.0')
            ->assertSee('dashboard.js?v=0.7.0');
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

        $this->get('/backup-manager/'.self::BACKUP_ID)
            ->assertOk()
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
}
