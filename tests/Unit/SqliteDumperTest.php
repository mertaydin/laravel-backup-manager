<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Database\SqliteDumper;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteDumperTest extends TestCase
{
    public function test_it_creates_a_consistent_compressed_sqlite_snapshot(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not installed.');
        }

        $directory = sys_get_temp_dir().'/backup-sqlite-test-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $source = $directory.'/source.sqlite';
        $destination = $directory.'/database.sqlite.gz';

        try {
            $database = new PDO('sqlite:'.$source);
            $database->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
            $database->exec("INSERT INTO messages (body) VALUES ('snapshot')");
            $database = null;

            (new SqliteDumper)->dump(['driver' => 'sqlite', 'database' => $source], $destination);

            $contents = gzdecode((string) file_get_contents($destination));
            self::assertIsString($contents);

            $snapshot = $directory.'/snapshot.sqlite';
            file_put_contents($snapshot, $contents);
            $restored = new PDO('sqlite:'.$snapshot);
            self::assertSame('snapshot', $restored->query('SELECT body FROM messages')->fetchColumn());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
