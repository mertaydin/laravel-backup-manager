<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Files\ZipRestorer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class ZipRestorerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/zip-restore-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_restores_files_under_the_selected_target(): void
    {
        $archive = $this->createArchive([
            'config/app.php' => '<?php return [];',
            'public/index.html' => '<h1>Restored</h1>',
        ]);
        $target = $this->directory.'/application';

        $restored = (new ZipRestorer())->restore($archive, $target);

        self::assertSame(2, $restored);
        self::assertSame('<?php return [];', file_get_contents($target.'/config/app.php'));
        self::assertSame('<h1>Restored</h1>', file_get_contents($target.'/public/index.html'));
    }

    public function test_it_rejects_path_traversal_before_extracting_any_files(): void
    {
        $archive = $this->createArchive([
            'safe.txt' => 'safe',
            '../escape.txt' => 'outside',
        ]);
        $target = $this->directory.'/application';

        try {
            (new ZipRestorer())->restore($archive, $target);
            self::fail('Path traversal entries must be rejected.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($target.'/safe.txt');
            self::assertFileDoesNotExist($this->directory.'/escape.txt');
        }
    }

    public function test_it_refuses_to_restore_environment_files(): void
    {
        $archive = $this->createArchive(['.env' => 'APP_KEY=secret']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('environment file');

        (new ZipRestorer())->restore($archive, $this->directory.'/application');
    }

    public function test_it_does_not_overwrite_existing_files_when_overwrite_is_disabled(): void
    {
        $archive = $this->createArchive(['config/app.php' => 'new']);
        $target = $this->directory.'/application';
        mkdir($target.'/config', 0750, true);
        file_put_contents($target.'/config/app.php', 'keep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        (new ZipRestorer())->restore($archive, $target, false);
    }

    /** @param array<string, string> $entries */
    private function createArchive(array $entries): string
    {
        $path = $this->directory.'/application.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
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
