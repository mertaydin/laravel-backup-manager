<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use RuntimeException;

final class PostgreSqlDumper
{
    /** @param array<string, mixed> $connection */
    public function dump(array $connection, string $destination): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('The configured PostgreSQL connection has no database name.');
        }

        $directory = dirname($destination);
        $passwordFile = tempnam($directory, '.pgpass-');
        $stderrFile = tempnam($directory, '.pg-error-');
        if ($passwordFile === false || $stderrFile === false) {
            throw new RuntimeException('Unable to create temporary PostgreSQL credential files.');
        }

        @chmod($passwordFile, 0600);
        @chmod($stderrFile, 0600);
        try {
            $line = implode(':', [
                $this->escape((string) ($connection['host'] ?? 'localhost')),
                $this->escape((string) ($connection['port'] ?? '5432')),
                $this->escape($database),
                $this->escape((string) ($connection['username'] ?? '')),
                $this->escape((string) ($connection['password'] ?? '')),
            ]).PHP_EOL;
            if (file_put_contents($passwordFile, $line, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the temporary PostgreSQL credential file.');
            }

            $command = [
                (string) ($connection['dump_binary'] ?? 'pg_dump'),
                '--no-password',
                '--no-owner',
                '--no-privileges',
                '--format=plain',
                '--host='.(string) ($connection['host'] ?? 'localhost'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? ''),
                $database,
            ];
            $environment = getenv();
            $environment = is_array($environment) ? $environment : [];
            $environment['PGPASSFILE'] = $passwordFile;
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start pg_dump.');
            }

            fclose($pipes[0]);
            $output = gzopen($destination, 'wb6');
            if ($output === false) {
                proc_terminate($process);
                fclose($pipes[1]);
                proc_close($process);
                throw new RuntimeException('Unable to open the compressed PostgreSQL backup artifact.');
            }

            try {
                while (! feof($pipes[1])) {
                    $chunk = fread($pipes[1], 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read pg_dump output.');
                    }
                    if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Unable to write the complete PostgreSQL backup chunk.');
                    }
                }
            } finally {
                fclose($pipes[1]);
                gzclose($output);
            }

            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                @unlink($destination);
                $message = trim((string) file_get_contents($stderrFile));
                throw new RuntimeException('pg_dump failed with exit code '.$exitCode.($message !== '' ? ': '.$message : '.'));
            }
        } finally {
            @unlink($passwordFile);
            @unlink($stderrFile);
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ':', "\n", "\r"], ['\\\\', '\\:', '', ''], $value);
    }
}
