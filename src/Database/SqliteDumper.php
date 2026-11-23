<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use PDO;
use RuntimeException;

final class SqliteDumper
{
    /** @param array<string, mixed> $connection */
    public function dump(array $connection, string $destination): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database === '' || $database === ':memory:') {
            throw new RuntimeException('SQLite backups require a file-backed database connection.');
        }

        if (! str_starts_with($database, DIRECTORY_SEPARATOR)) {
            $database = base_path($database);
        }
        if (! is_file($database) || ! is_readable($database)) {
            throw new RuntimeException('The configured SQLite database file does not exist or is not readable.');
        }

        $snapshot = dirname($destination).DIRECTORY_SEPARATOR.'.sqlite-snapshot-'.bin2hex(random_bytes(6));
        try {
            $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('VACUUM INTO '.$pdo->quote($snapshot));
            unset($pdo);

            $input = fopen($snapshot, 'rb');
            $output = gzopen($destination, 'wb6');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    gzclose($output);
                }
                throw new RuntimeException('Unable to open SQLite snapshot streams.');
            }

            try {
                while (! feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read the SQLite snapshot.');
                    }
                    if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Unable to write the compressed SQLite snapshot.');
                    }
                }
            } finally {
                fclose($input);
                gzclose($output);
            }
        } finally {
            @unlink($snapshot);
        }
    }
}
