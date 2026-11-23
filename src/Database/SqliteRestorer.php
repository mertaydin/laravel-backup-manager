<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

final class SqliteRestorer
{
    /** @param array<string, mixed> $connection */
    public function restore(string $connectionName, array $connection, string $source): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database === '' || $database === ':memory:') {
            throw new RuntimeException('SQLite restore requires a file-backed database connection.');
        }
        if (! str_starts_with($database, DIRECTORY_SEPARATOR)) {
            $database = base_path($database);
        }

        $directory = dirname($database);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The SQLite database directory is not writable.');
        }

        $temporary = tempnam($directory, '.sqlite-restore-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary SQLite restore file.');
        }
        @chmod($temporary, 0600);

        try {
            $input = gzopen($source, 'rb');
            $output = fopen($temporary, 'wb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    gzclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Unable to open SQLite restore streams.');
            }

            try {
                while (! gzeof($input)) {
                    $chunk = gzread($input, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('The compressed SQLite backup is corrupted.');
                    }
                    if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Unable to write the restored SQLite database completely.');
                    }
                }
            } finally {
                gzclose($input);
                fclose($output);
            }

            $check = new PDO('sqlite:'.$temporary, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $integrity = $check->query('PRAGMA quick_check')?->fetchColumn();
            $check = null;
            if ($integrity !== 'ok') {
                throw new RuntimeException('The restored SQLite database failed PRAGMA quick_check.');
            }

            DB::purge($connectionName);
            $previous = null;
            if (is_file($database)) {
                $previous = $database.'.backup-'.bin2hex(random_bytes(4));
                if (! @rename($database, $previous)) {
                    throw new RuntimeException('Unable to stage the existing SQLite database before restore.');
                }
            }

            if (! @rename($temporary, $database)) {
                if ($previous !== null) {
                    @rename($previous, $database);
                }
                throw new RuntimeException('Unable to atomically replace the configured SQLite database file.');
            }

            @unlink($database.'-wal');
            @unlink($database.'-shm');
            if ($previous !== null) {
                @unlink($previous);
            }
            @chmod($database, 0600);
            DB::purge($connectionName);
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }
}
