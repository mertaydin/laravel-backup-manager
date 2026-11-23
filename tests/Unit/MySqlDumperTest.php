<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlDumperTest extends TestCase
{
    public function test_it_rejects_invalid_table_identifiers(): void
    {
        $this->expectException(RuntimeException::class);
        (new MySqlDumper())->dump(['database' => 'app'], sys_get_temp_dir().'/never-created.sql.gz', ['users; DROP TABLE users'], []);
    }

    public function test_it_requires_a_database_name(): void
    {
        $this->expectException(RuntimeException::class);
        (new MySqlDumper())->dump([], sys_get_temp_dir().'/never-created.sql.gz');
    }
}
