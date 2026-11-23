<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use RuntimeException;

final class DatabaseDumper
{
    public function __construct(
        private readonly MySqlDumper $mysql,
        private readonly PostgreSqlDumper $postgres,
        private readonly SqliteDumper $sqlite,
    ) {}

    /** @param array<string, mixed> $connection
     * @param list<string> $includeTables
     * @param list<string> $excludeTables
     */
    public function dump(array $connection, string $destination, array $includeTables = [], array $excludeTables = []): void
    {
        $driver = (string) ($connection['driver'] ?? '');
        if ($driver === 'mysql') {
            $this->mysql->dump($connection, $destination, $includeTables, $excludeTables);
            return;
        }

        if ($includeTables !== [] || $excludeTables !== []) {
            throw new RuntimeException("Table include/exclude filters are currently supported for MySQL backups only; selected driver [{$driver}].");
        }

        if ($driver === 'pgsql') {
            $this->postgres->dump($connection, $destination);
            return;
        }

        if ($driver === 'sqlite') {
            $this->sqlite->dump($connection, $destination);
            return;
        }

        throw new RuntimeException("Database driver [{$driver}] is not supported. Supported drivers: mysql, pgsql, sqlite.");
    }
}
