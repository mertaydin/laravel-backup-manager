<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Tests\Unit;

use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\Contracts\BackupDriver;
use MertAydin\LaravelBackupManager\Data\BackupResult;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    public function test_it_registers_and_resolves_a_driver(): void
    {
        $manager = new BackupManager();
        $driver = new FakeBackupDriver();

        $manager->extend('fake', $driver);

        self::assertSame($driver, $manager->driver('fake'));
        self::assertSame(['fake'], $manager->availableDrivers());
    }

    public function test_it_delegates_backup_execution_to_the_selected_driver(): void
    {
        $manager = new BackupManager();
        $manager->extend('fake', new FakeBackupDriver());

        $result = $manager->run(['database' => true], 'fake');

        self::assertTrue($result->successful);
        self::assertSame('test-backup', $result->id);
    }
}

final class FakeBackupDriver implements BackupDriver
{
    public function run(array $options = []): BackupResult
    {
        return BackupResult::success('test-backup', ['database' => 'database.sql.gz'], $options);
    }
}
