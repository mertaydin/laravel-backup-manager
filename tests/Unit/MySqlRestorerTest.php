<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Database\MySqlRestorer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlRestorerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/mysql-restore-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_streams_decompressed_sql_to_the_mysql_client(): void
    {
        $sql = "CREATE TABLE restored (id INT);\nINSERT INTO restored VALUES (1);";
        $dump = $this->directory.'/database.sql.gz';
        $output = $this->directory.'/received.sql';
        $this->writeGzip($dump, $sql);
        $binary = $this->createClient("cat > ".escapeshellarg($output));

        (new MySqlRestorer())->restore([
            'database' => 'application',
            'username' => 'backup-user',
            'password' => 'secret',
        ], $dump, $binary);

        self::assertSame($sql, file_get_contents($output));
        self::assertSame([], glob($this->directory.'/.mysql-*') ?: []);
    }

    public function test_it_reports_mysql_client_failures(): void
    {
        $dump = $this->directory.'/database.sql.gz';
        $this->writeGzip($dump, 'SELECT 1;');
        $binary = $this->createClient("cat >/dev/null; echo 'restore failed' >&2; exit 7");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restore failed');

        (new MySqlRestorer())->restore(['database' => 'application'], $dump, $binary);
    }

    public function test_it_requires_a_database_name(): void
    {
        $this->expectException(RuntimeException::class);

        (new MySqlRestorer())->restore([], $this->directory.'/missing.sql.gz');
    }

    private function writeGzip(string $path, string $contents): void
    {
        $stream = gzopen($path, 'wb6');
        self::assertNotFalse($stream);
        gzwrite($stream, $contents);
        gzclose($stream);
    }

    private function createClient(string $body): string
    {
        $path = $this->directory.'/mysql-client';
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0700);

        return $path;
    }
}
