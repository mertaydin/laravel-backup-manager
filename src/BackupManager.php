<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager;

use InvalidArgumentException;
use MertAydin\LaravelBackupManager\Contracts\BackupDriver;
use MertAydin\LaravelBackupManager\Data\BackupResult;

final class BackupManager
{
    /** @var array<string, BackupDriver> */
    private array $drivers = [];

    public function extend(string $name, BackupDriver $driver): self
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    public function driver(?string $name = null): BackupDriver
    {
        $name ??= (string) config('backup-manager.default_driver', 'local');

        return $this->drivers[$name]
            ?? throw new InvalidArgumentException("Backup driver [{$name}] is not registered.");
    }

    /** @return list<string> */
    public function availableDrivers(): array
    {
        return array_keys($this->drivers);
    }

    /** @param array<string, mixed> $options */
    public function run(array $options = [], ?string $driver = null): BackupResult
    {
        return $this->driver($driver)->run($options);
    }
}
