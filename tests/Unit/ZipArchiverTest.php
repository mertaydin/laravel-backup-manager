<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Files\ZipArchiver;
use PHPUnit\Framework\TestCase;

final class ZipArchiverTest extends TestCase
{
    public function test_it_excludes_environment_files_and_dotfiles_by_default(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('The zip extension is not installed.');
        }

        $root = sys_get_temp_dir().'/backup-archive-'.bin2hex(random_bytes(4));
        mkdir($root);
        mkdir($root.'/config');
        file_put_contents($root.'/config/app.php', '<?php return [];');
        file_put_contents($root.'/.env', 'SECRET=never');
        file_put_contents($root.'/.hidden', 'hidden');
        $archive = $root.'/result.zip';

        try {
            (new ZipArchiver())->archive($root, ['config', '.env', '.hidden'], [], $archive);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($archive));
            self::assertSame(1, $zip->numFiles);
            self::assertSame('config/app.php', $zip->getNameIndex(0));
            $zip->close();
        } finally {
            @unlink($archive);
            @unlink($root.'/config/app.php');
            @rmdir($root.'/config');
            @unlink($root.'/.env');
            @unlink($root.'/.hidden');
            @rmdir($root);
        }
    }
}
