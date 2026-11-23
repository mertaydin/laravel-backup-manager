<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MertAydin\LaravelBackupManager\Contracts\BackupDriver driver(?string $name = null)
 * @method static \MertAydin\LaravelBackupManager\Data\BackupResult run(array $options = [], ?string $driver = null)
 * @method static \MertAydin\LaravelBackupManager\BackupManager extend(string $name, \MertAydin\LaravelBackupManager\Contracts\BackupDriver $driver)
 * @method static array availableDrivers()
 */
class Backup extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'backup-manager';
    }
}
