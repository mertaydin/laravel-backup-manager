<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use RuntimeException;

final class MySqlRestorer
{
    /** @param array<string, mixed> $connection */
    public function restore(array $connection, string $compressedDump, string $binary = 'mysql'): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('The configured MySQL connection has no database name.');
        }

        $directory = dirname($compressedDump);
        $credentialsFile = tempnam($directory, '.mysql-restore-');
        $stdoutFile = tempnam($directory, '.mysql-stdout-');
        $stderrFile = tempnam($directory, '.mysql-stderr-');

        if ($credentialsFile === false || $stdoutFile === false || $stderrFile === false) {
            foreach ([$credentialsFile, $stdoutFile, $stderrFile] as $path) {
                if (is_string($path)) {
                    @unlink($path);
                }
            }
            throw new RuntimeException('Unable to create temporary MySQL restore files.');
        }

        @chmod($credentialsFile, 0600);
        @chmod($stdoutFile, 0600);
        @chmod($stderrFile, 0600);

        try {
            if (file_put_contents($credentialsFile, $this->buildDefaultsFile($connection), LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the temporary MySQL credential file.');
            }

            $command = [
                $binary,
                '--defaults-extra-file='.$credentialsFile,
                '--default-character-set=utf8mb4',
            ];

            foreach (['host' => '--host=', 'port' => '--port=', 'username' => '--user='] as $key => $option) {
                if (isset($connection[$key]) && $connection[$key] !== '') {
                    $command[] = $option.$connection[$key];
                }
            }

            $command[] = $database;

            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ], $pipes, null, null, ['bypass_shell' => true]);

            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start the mysql client. Check that the binary is installed and executable.');
            }

            $input = gzopen($compressedDump, 'rb');
            if ($input === false) {
                proc_terminate($process);
                fclose($pipes[0]);
                proc_close($process);
                throw new RuntimeException('Unable to open the compressed SQL backup.');
            }

            $streamFailure = null;
            try {
                while (! gzeof($input)) {
                    $chunk = gzread($input, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to decompress the SQL backup.');
                    }
                    if ($chunk !== '') {
                        $this->writeAll($pipes[0], $chunk);
                    }
                }
            } catch (\\Throwable $exception) {
                $streamFailure = $exception;
                proc_terminate($process);
            } finally {
                gzclose($input);
                if (is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
            }

            $exitCode = proc_close($process);
            if ($streamFailure !== null) {
                throw $streamFailure;
            }
            if ($exitCode !== 0) {
                $message = trim((string) file_get_contents($stderrFile));
                throw new RuntimeException('mysql restore failed with exit code '.$exitCode.($message !== '' ? ': '.$message : '.'));
            }
        } finally {
            @unlink($credentialsFile);
            @unlink($stdoutFile);
            @unlink($stderrFile);
        }
    }

    /** @param array<string, mixed> $connection */
    private function buildDefaultsFile(array $connection): string
    {
        $values = [
            'user' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'host' => (string) ($connection['host'] ?? '127.0.0.1'),
            'port' => (string) ($connection['port'] ?? '3306'),
            'socket' => (string) ($connection['unix_socket'] ?? ''),
        ];

        $content = "[client]\n";
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $content .= $key.'="'.$this->escapeOptionValue($value).'"'."\n";
            }
        }

        return $content;
    }

    private function escapeOptionValue(string $value): string
    {
        return str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $value);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to stream the SQL backup to the mysql client.');
            }
            $offset += $written;
        }
    }
}
