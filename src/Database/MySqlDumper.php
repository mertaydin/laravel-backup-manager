<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Database;

use RuntimeException;

final class MySqlDumper
{
    /** @param array<string, mixed> $connection */
    public function dump(array $connection, string $destination, array $includeTables = [], array $excludeTables = []): void
    {
        $binary = (string) ($connection['dump_binary'] ?? 'mysqldump');
        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('The configured MySQL connection has no database name.');
        }
        $this->validateIdentifier($database);

        foreach (array_merge($includeTables, $excludeTables) as $table) {
            $this->validateIdentifier((string) $table);
        }

        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the database backup directory.');
        }

        $credentialsFile = tempnam($directory, '.mysql-');
        $stderrFile = tempnam($directory, '.mysql-error-');
        if ($credentialsFile === false || $stderrFile === false) {
            if (is_string($credentialsFile)) {
                @unlink($credentialsFile);
            }
            throw new RuntimeException('Unable to create temporary MySQL credential files.');
        }

        @chmod($credentialsFile, 0600);
        @chmod($stderrFile, 0600);

        try {
            if (file_put_contents($credentialsFile, $this->buildDefaultsFile($connection), LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the temporary MySQL credential file.');
            }

            $command = [
                $binary,
                '--defaults-extra-file='.$credentialsFile,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--default-character-set=utf8mb4',
            ];

            foreach (['host' => '--host=', 'port' => '--port=', 'username' => '--user='] as $key => $option) {
                if (isset($connection[$key]) && $connection[$key] !== '') {
                    $command[] = $option.$connection[$key];
                }
            }

            foreach ($excludeTables as $table) {
                if ($includeTables === []) {
                    $command[] = '--ignore-table='.$database.'.'.$table;
                }
            }

            $command[] = $database;
            foreach ($includeTables as $table) {
                $command[] = $table;
            }

            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $stderrFile, 'w'],
            ], $pipes, null, null, ['bypass_shell' => true]);

            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start mysqldump. Check that the binary is installed and executable.');
            }

            fclose($pipes[0]);
            $output = gzopen($destination, 'wb6');
            if ($output === false) {
                proc_terminate($process);
                fclose($pipes[1]);
                proc_close($process);
                throw new RuntimeException('Unable to open the compressed database backup file.');
            }

            try {
                while (! feof($pipes[1])) {
                    $chunk = fread($pipes[1], 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read mysqldump output.');
                    }
                    if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Unable to write the complete compressed database backup chunk.');
                    }
                }
            } finally {
                fclose($pipes[1]);
                gzclose($output);
            }

            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $message = trim((string) file_get_contents($stderrFile));
                @unlink($destination);
                throw new RuntimeException('mysqldump failed with exit code '.$exitCode.($message !== '' ? ': '.$message : '.'));
            }

            if (! is_file($destination) || filesize($destination) === 0) {
                @unlink($destination);
                throw new RuntimeException('mysqldump produced an empty backup.');
            }
        } finally {
            @unlink($credentialsFile);
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

    private function validateIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]*$/', $identifier)) {
            throw new RuntimeException('Invalid MySQL database or table identifier: '.$identifier);
        }
    }
}
