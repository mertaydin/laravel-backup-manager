<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Files;

use RuntimeException;
use ZipArchive;

final class ZipRestorer
{
    public function restore(string $archive, string $target, bool $overwrite = true): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to restore application files.');
        }

        $root = $this->prepareRoot($target);
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Unable to open the application backup ZIP archive.');
        }

        $entries = [];
        $seen = [];
        $entryTypes = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (! is_string($name) || ! is_array($stat)) {
                    throw new RuntimeException('The ZIP archive contains an unreadable entry.');
                }

                $normalized = str_replace('\\', '/', $name);
                if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1 || str_contains($normalized, "\0")) {
                    throw new RuntimeException("The ZIP archive contains an unsafe path [{$name}].");
                }

                $isDirectory = str_ends_with($normalized, '/');
                $relative = rtrim($normalized, '/');
                $segments = explode('/', $relative);
                if ($relative === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
                    throw new RuntimeException("The ZIP archive contains an unsafe path [{$name}].");
                }

                $basename = end($segments);
                if ($basename === '.env' || str_starts_with($basename, '.env.')) {
                    throw new RuntimeException('The ZIP archive contains an environment file; restore was stopped to protect application secrets.');
                }

                $operations = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operations, $attributes)
                    && $operations === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException("The ZIP archive contains a symbolic link [{$name}].");
                }

                if (isset($seen[$relative])) {
                    throw new RuntimeException("The ZIP archive contains a duplicate path [{$relative}].");
                }
                $seen[$relative] = true;
                $entryTypes[$relative] = $isDirectory ? 'directory' : 'file';

                $entries[] = [
                    'name' => $name,
                    'relative' => $relative,
                    'segments' => $segments,
                    'directory' => $isDirectory,
                    'size' => (int) ($stat['size'] ?? 0),
                ];
            }

            foreach ($entries as $entry) {
                $ancestors = [];
                for ($index = 1; $index < count($entry['segments']); $index++) {
                    $ancestors[] = implode('/', array_slice($entry['segments'], 0, $index));
                }
                foreach ($ancestors as $ancestor) {
                    if (($entryTypes[$ancestor] ?? null) === 'file') {
                        throw new RuntimeException("The ZIP archive places an entry beneath a file [{$ancestor}].");
                    }
                }

                $destination = $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $entry['segments']);
                if ($entry['directory']) {
                    $this->ensureDirectory($root, $entry['segments']);
                    continue;
                }

                $this->ensureDirectory($root, array_slice($entry['segments'], 0, -1));
                if (is_link($destination)) {
                    throw new RuntimeException("Refusing to overwrite symbolic link [{$entry['relative']}].");
                }
                if (is_dir($destination)) {
                    throw new RuntimeException("A directory already exists at file destination [{$entry['relative']}].");
                }
                if (file_exists($destination) && ! $overwrite) {
                    throw new RuntimeException("File [{$entry['relative']}] already exists in the restore target.");
                }
            }

            $restored = 0;
            foreach ($entries as $entry) {
                if ($entry['directory']) {
                    continue;
                }

                $destination = $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $entry['segments']);
                $source = $zip->getStream($entry['name']);
                if (! is_resource($source)) {
                    throw new RuntimeException("Unable to read ZIP entry [{$entry['relative']}].");
                }

                $output = fopen($destination, $overwrite ? 'wb' : 'xb');
                if ($output === false) {
                    fclose($source);
                    throw new RuntimeException("Unable to create restored file [{$entry['relative']}].");
                }

                try {
                    $copied = stream_copy_to_stream($source, $output);
                    if ($copied === false || $copied !== $entry['size']) {
                        throw new RuntimeException("Unable to restore the complete file [{$entry['relative']}].");
                    }
                    if (! fflush($output)) {
                        throw new RuntimeException("Unable to flush restored file [{$entry['relative']}].");
                    }
                } catch (\Throwable $exception) {
                    @unlink($destination);
                    throw $exception;
                } finally {
                    fclose($source);
                    fclose($output);
                }

                @chmod($destination, 0640);
                $restored++;
            }

            return $restored;
        } finally {
            $zip->close();
        }
    }

    private function prepareRoot(string $target): string
    {
        if (! is_dir($target) && ! mkdir($target, 0750, true) && ! is_dir($target)) {
            throw new RuntimeException('Unable to create the application restore target directory.');
        }

        $root = realpath($target);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('The application restore target is not a valid directory.');
        }

        return $root === DIRECTORY_SEPARATOR ? $root : rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * @param list<string> $segments
     */
    private function ensureDirectory(string $root, array $segments): void
    {
        $current = $root;

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($current)) {
                throw new RuntimeException('Refusing to extract through a symbolic link in the restore target.');
            }

            if (! is_dir($current)) {
                if (file_exists($current) || ! mkdir($current, 0750) && ! is_dir($current)) {
                    throw new RuntimeException('Unable to create a directory in the restore target.');
                }
            }

            $real = realpath($current);
            if ($real === false || ($root !== DIRECTORY_SEPARATOR && $real !== $root && ! str_starts_with($real, $root.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('A ZIP entry resolved outside the restore target.');
            }
        }
    }
}
