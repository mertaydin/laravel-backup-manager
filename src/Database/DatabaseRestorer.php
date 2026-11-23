<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use RuntimeException;

final class DatabaseRestorer
{
    public function __construct(
        private readonly MySqlRestorer $mysql,
        private readonly PostgreSqlRestorer $postgres,
        private readonly SqliteRestorer $sqlite,
    ) {}

    /** @param array<string, mixed> $connection */
    public function restore(string $connectionName, array $connection, string $source, string $mysqlBinary = 'mysql'): void
    {
        $driver = (string) ($connection['driver'] ?? '');
        if ($driver === 'mysql') {
            $this->mysql->restore($connection, $source, $mysqlBinary);
            return;
        }
        if ($driver === 'pgsql') {
            $this->postgres->restore($connection, $source);
            return;
        }
        if ($driver === 'sqlite') {
            $this->sqlite->restore($connectionName, $connection, $source);
            return;
        }

        throw new RuntimeException("Database driver [{$driver}] is not supported. Supported drivers: mysql, pgsql, sqlite.");
    }
}
