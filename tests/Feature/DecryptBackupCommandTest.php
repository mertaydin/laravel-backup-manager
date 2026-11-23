<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use MertAydin\LaravelBackupManager\BackupManagerServiceProvider;
use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use Orchestra\Testbench\TestCase;

final class DecryptBackupCommandTest extends TestCase
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

        $this->directory = sys_get_temp_dir().'/backup-decrypt-test-'.bin2hex(random_bytes(6));
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
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_dir($file)) {
                foreach (glob($file.'/*') ?: [] as $nested) {
                    @unlink($nested);
                }
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_verifies_and_decrypts_an_encrypted_backup_artifact(): void
    {
        $contents = 'database dump payload';
        $encryptedPath = $this->directory.'/database.sql.gz.enc';
        file_put_contents($this->directory.'/source.sql.gz', $contents);
        (new ArtifactEncryptor)->encrypt($this->directory.'/source.sql.gz', $encryptedPath, $this->key);

        $encrypted = file_get_contents($encryptedPath);
        $this->storeEncryptedArtifact($encrypted);

        $destination = $this->directory.'/restored.sql.gz';

        $this->artisan('backup:decrypt', [
            'id' => self::BACKUP_ID,
            'artifact' => 'database.sql.gz.enc',
            '--output' => $destination,
        ])->assertExitCode(0);

        self::assertSame($contents, file_get_contents($destination));
    }

    public function test_it_does_not_overwrite_existing_output_without_force(): void
    {
        $contents = 'database dump payload';
        $encryptedPath = $this->directory.'/database.sql.gz.enc';
        file_put_contents($this->directory.'/source.sql.gz', $contents);
        (new ArtifactEncryptor)->encrypt($this->directory.'/source.sql.gz', $encryptedPath, $this->key);
        $this->storeEncryptedArtifact(file_get_contents($encryptedPath));

        $destination = $this->directory.'/existing.sql.gz';
        file_put_contents($destination, 'keep this file');

        $this->artisan('backup:decrypt', [
            'id' => self::BACKUP_ID,
            'artifact' => 'database.sql.gz.enc',
            '--output' => $destination,
        ])->assertExitCode(1);

        self::assertSame('keep this file', file_get_contents($destination));
    }

    private function storeEncryptedArtifact(string $encrypted): void
    {
        $path = 'backups/'.self::BACKUP_ID.'/database.sql.gz.enc';
        Storage::disk('backup-test')->put($path, $encrypted);
        Storage::disk('backup-test')->put(
            'backups/'.self::BACKUP_ID.'/manifest.json',
            json_encode([
                'id' => self::BACKUP_ID,
                'created_at' => '2026-11-23T12:00:00+00:00',
                'profile' => 'default',
                'encryption' => [
                    'enabled' => true,
                    'algorithm' => ArtifactEncryptor::ALGORITHM,
                    'format_version' => 1,
                ],
                'artifacts' => [
                    'database.sql.gz.enc' => [
                        'size' => strlen($encrypted),
                        'sha256' => hash('sha256', $encrypted),
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
    }
}
