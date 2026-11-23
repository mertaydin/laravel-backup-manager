<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Security\ArtifactEncryptor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArtifactEncryptorTest extends TestCase
{
    private string $directory;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/backup-encryption-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->key = base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_encrypts_and_decrypts_large_artifacts(): void
    {
        $contents = random_bytes(180000);
        $source = $this->directory.'/source.bin';
        $encrypted = $this->directory.'/source.bin.enc';
        $decrypted = $this->directory.'/decrypted.bin';
        file_put_contents($source, $contents);

        $encryptor = new ArtifactEncryptor();
        $metadata = $encryptor->encrypt($source, $encrypted, $this->key);
        $encryptor->decrypt($encrypted, $decrypted, $this->key);

        self::assertSame(ArtifactEncryptor::ALGORITHM, $metadata['algorithm']);
        self::assertSame($contents, file_get_contents($decrypted));
        self::assertNotSame($contents, file_get_contents($encrypted));
    }

    public function test_it_supports_empty_artifacts(): void
    {
        $source = $this->directory.'/empty.bin';
        $encrypted = $this->directory.'/empty.bin.enc';
        $decrypted = $this->directory.'/empty-restored.bin';
        file_put_contents($source, '');

        $encryptor = new ArtifactEncryptor();
        $encryptor->encrypt($source, $encrypted, $this->key);
        $encryptor->decrypt($encrypted, $decrypted, $this->key);

        self::assertSame('', file_get_contents($decrypted));
    }

    public function test_it_rejects_an_invalid_encryption_key(): void
    {
        $source = $this->directory.'/source.bin';
        file_put_contents($source, 'secret');

        $this->expectException(RuntimeException::class);

        (new ArtifactEncryptor())->encrypt($source, $this->directory.'/encrypted.bin', 'not-a-valid-key');
    }

    public function test_it_rejects_tampered_ciphertext(): void
    {
        $source = $this->directory.'/source.bin';
        $encrypted = $this->directory.'/source.bin.enc';
        $decrypted = $this->directory.'/decrypted.bin';
        file_put_contents($source, 'sensitive backup content');

        $encryptor = new ArtifactEncryptor();
        $encryptor->encrypt($source, $encrypted, $this->key);
        $ciphertext = file_get_contents($encrypted);
        $ciphertext[strlen($ciphertext) - 1] = chr(ord($ciphertext[strlen($ciphertext) - 1]) ^ 1);
        file_put_contents($encrypted, $ciphertext);

        try {
            $encryptor->decrypt($encrypted, $decrypted, $this->key);
            self::fail('Tampered ciphertext must not be accepted.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($decrypted);
        }
    }
}
