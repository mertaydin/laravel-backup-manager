<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\BackupRestorer;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use Orchestra\Testbench\TestCase;
use ZipArchive;

final class BackupRestorerTest extends TestCase
{
    private const BACKUP_ID = '20261123_120000_aabbccdd';

    private string $directory;

    private string $key;

    protected function getPackageProviders($app): array
    {
        return [BackupManagerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/backup-restore-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->key = base64_encode(random_bytes(32));

        Storage::fake('backup-test');
        config([
            'backup-manager.default' => 'default',
            'backup-manager.temporary_directory' => $this->directory.'/tmp',
            'backup-manager.profiles.default.storage.disk' => 'backup-test',
            'backup-manager.profiles.default.storage.path' => 'backups',
            'backup-manager.profiles.default.encryption.enabled' => true,
            'backup-manager.profiles.default.encryption.key' => $this->key,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_restores_an_encrypted_application_archive_after_integrity_verification(): void
    {
        $archive = $this->createArchive();
        $encryptedPath = $archive.'.enc';
        (new ArtifactEncryptor())->encrypt($archive, $encryptedPath, $this->key);
        $encrypted = file_get_contents($encryptedPath);
        $this->storeManifest('application.zip.enc', $encrypted, true);

        $target = $this->directory.'/restored-app';
        $result = app(BackupRestorer::class)->restore(self::BACKUP_ID, [
            'component' => 'files',
            'target' => $target,
        ]);

        self::assertSame(1, $result['restored_files']);
        self::assertSame('<?php return "restored";', file_get_contents($target.'/config/app.php'));
    }

    public function test_restore_command_is_a_dry_run_without_force(): void
    {
        $archive = $this->createArchive();
        $contents = file_get_contents($archive);
        $this->storeManifest('application.zip', $contents, false);

        $target = $this->directory.'/dry-run-target';
        $this->artisan('backup:restore', [
            'id' => self::BACKUP_ID,
            '--files' => true,
            '--target' => $target,
        ])->assertExitCode(0);

        self::assertFileDoesNotExist($target.'/config/app.php');
    }

    private function createArchive(): string
    {
        $path = $this->directory.'/application.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('config/app.php', '<?php return "restored";');
        $zip->close();

        return $path;
    }

    private function storeManifest(string $artifactName, string $contents, bool $encrypted): void
    {
        Storage::disk('backup-test')->put(
            'backups/'.self::BACKUP_ID.'/'.$artifactName,
            $contents
        );

        $manifest = [
            'id' => self::BACKUP_ID,
            'created_at' => '2026-11-23T12:00:00+00:00',
            'profile' => 'default',
            'artifacts' => [
                $artifactName => [
                    'size' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                ],
            ],
        ];

        if ($encrypted) {
            $manifest['encryption'] = [
                'enabled' => true,
                'algorithm' => ArtifactEncryptor::ALGORITHM,
                'format_version' => 1,
            ];
        }

        Storage::disk('backup-test')->put(
            'backups/'.self::BACKUP_ID.'/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
