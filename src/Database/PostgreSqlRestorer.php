<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use RuntimeException;

final class PostgreSqlRestorer
{
    /** @param array<string, mixed> $connection */
    public function restore(array $connection, string $source): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('The configured PostgreSQL connection has no database name.');
        }

        $directory = dirname($source);
        $passwordFile = tempnam($directory, '.pgpass-');
        $stderrFile = tempnam($directory, '.pg-error-');
        $stdoutFile = tempnam($directory, '.pg-output-');
        if ($passwordFile === false || $stderrFile === false || $stdoutFile === false) {
            throw new RuntimeException('Unable to create temporary PostgreSQL restore files.');
        }

        @chmod($passwordFile, 0600);
        @chmod($stderrFile, 0600);
        @chmod($stdoutFile, 0600);
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
                (string) ($connection['restore_binary'] ?? 'psql'),
                '--no-password',
                '--set=ON_ERROR_STOP=1',
                '--no-psqlrc',
                '--host='.(string) ($connection['host'] ?? 'localhost'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? ''),
                $database,
            ];
            $environment = getenv();
            $environment = is_array($environment) ? $environment : [];
            $environment['PGPASSFILE'] = $passwordFile;
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start psql.');
            }

            $input = gzopen($source, 'rb');
            if ($input === false) {
                proc_terminate($process);
                fclose($pipes[0]);
                proc_close($process);
                throw new RuntimeException('Unable to read the compressed PostgreSQL backup.');
            }

            try {
                while (! gzeof($input)) {
                    $chunk = gzread($input, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('The compressed PostgreSQL backup is corrupted.');
                    }
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = fwrite($pipes[0], substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            throw new RuntimeException('Unable to stream SQL into psql.');
                        }
                        $offset += $written;
                    }
                }
            } finally {
                gzclose($input);
                fclose($pipes[0]);
            }

            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $message = trim((string) file_get_contents($stderrFile));
                throw new RuntimeException('psql failed with exit code '.$exitCode.($message !== '' ? ': '.$message : '.'));
            }
        } finally {
            @unlink($passwordFile);
            @unlink($stderrFile);
            @unlink($stdoutFile);
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ':', "\n", "\r"], ['\\\\', '\\:', '', ''], $value);
    }
}
