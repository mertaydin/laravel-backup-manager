<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\Database\MySqlDumper;
use MertAydin\LaravelBackupManager\Database\MySqlRestorer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlDatabaseIdentifierSecurityTest extends TestCase
{
    public function test_dump_rejects_database_names_that_could_be_parsed_as_cli_options(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid MySQL database or table identifier');

        (new MySqlDumper)->dump(
            ['database' => '--help'],
            sys_get_temp_dir().'/backup-manager-security-test.sql.gz'
        );
    }

    public function test_restore_rejects_database_names_that_could_be_parsed_as_cli_options(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid MySQL database identifier');

        (new MySqlRestorer)->restore(
            ['database' => '--help'],
            sys_get_temp_dir().'/backup-manager-security-test.sql.gz'
        );
    }
}
