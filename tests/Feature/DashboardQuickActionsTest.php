<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupPreflight;
use MertAydin\LaravelBackupManager\Jobs\CreateBackupJob;
use Orchestra\Testbench\TestCase;

final class DashboardQuickActionsTest extends TestCase
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

    public function test_dashboard_can_update_backup_metadata_on_all_configured_copies(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        $manifest = ['id' => self::BACKUP_ID, 'label' => 'Old label', 'note' => 'Old note', 'tags' => ['old'], 'artifacts' => []];

        foreach (['s3', 'local'] as $disk) {
            Storage::disk($disk)->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        }

        $this->put('/backup-manager/'.self::BACKUP_ID.'/metadata', [
            'label' => 'Release backup',
            'note' => 'Before production deployment',
            'tags' => "production\nrelease, important",
        ])->assertRedirect()->assertSessionHas('backup_success');

        foreach (['s3', 'local'] as $disk) {
            $stored = json_decode(Storage::disk($disk)->get('backups/'.self::BACKUP_ID.'/manifest.json'), true);
            self::assertSame('Release backup', $stored['label']);
            self::assertSame('Before production deployment', $stored['note']);
            self::assertSame(['production', 'release', 'important'], $stored['tags']);
        }
    }

    public function test_dashboard_can_repeat_a_database_backup_with_original_options(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Storage::disk('s3')->put('backups/'.self::BACKUP_ID.'/manifest.json', json_encode([
            'id' => self::BACKUP_ID,
            'label' => 'Nightly database',
            'note' => 'Pre-release',
            'tags' => ['nightly'],
            'artifacts' => ['database.sql.gz' => ['size' => 10, 'sha256' => str_repeat('a', 64)]],
        ], JSON_THROW_ON_ERROR));

        Queue::fake();
        $preflight = \Mockery::mock(BackupPreflight::class);
        $preflight->shouldReceive('inspect')->once()->andReturn(['ready' => true, 'checks' => [], 'errors' => []]);
        $this->instance(BackupPreflight::class, $preflight);
        config(['backup-manager.queue.connection' => 'database']);

        $this->post('/backup-manager/'.self::BACKUP_ID.'/repeat')
            ->assertRedirect()
            ->assertSessionHas('backup_job_id');

        Queue::assertPushed(CreateBackupJob::class, static fn (CreateBackupJob $job): bool => $job->options['database'] === true
            && $job->options['files'] === false
            && $job->options['label'] === 'Nightly database'
            && $job->options['note'] === 'Pre-release'
            && $job->options['tags'] === ['nightly']);
    }

    public function test_bulk_delete_skips_protected_backups(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        $protectedId = '20261123_120001_a1b2c3d4';

        foreach ([$protectedId, self::BACKUP_ID] as $id) {
            $manifest = ['id' => $id, 'artifacts' => [], 'protection' => ['locked' => $id === $protectedId]];

            foreach (['s3', 'local'] as $disk) {
                Storage::disk($disk)->put('backups/'.$id.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            }
        }

        $this->post('/backup-manager/bulk', ['action' => 'delete', 'ids' => [$protectedId, self::BACKUP_ID]])
            ->assertRedirect()
            ->assertSessionHas('backup_error');

        Storage::disk('s3')->assertExists('backups/'.$protectedId.'/manifest.json');
        Storage::disk('s3')->assertMissing('backups/'.self::BACKUP_ID.'/manifest.json');
    }
}
