<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Contracts;

use MertAydin\LaravelBackupManager\Data\BackupResult;

interface BackupDriver
{
    /** @param array<string, mixed> $options */
    public function run(array $options = []): BackupResult;
}
