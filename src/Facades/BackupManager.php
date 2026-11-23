<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager\\Facades;

use Illuminate\\Support\\Facades\\Facade;
use MertAydin\\LaravelBackupManager\\Services\\DashboardAuthorizer;

/**
 * @method static void auth(callable $callback)
 *
 * @see DashboardAuthorizer
 */
class BackupManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DashboardAuthorizer::class;
    }
}
