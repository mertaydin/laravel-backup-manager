<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Files;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

final class ZipArchiver
{
    /** @param list<string> $paths @param list<string> $excludes */
    public function archive(string $basePath, array $paths, array $excludes, string $destination, bool $includeDotfiles = false, bool $excludeEnv = true): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to create application backups.');
        }

        $base = realpath($basePath);
        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException('The application base path does not exist.');
        }

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the application ZIP archive.');
        }

        $added = 0;
        try {
            foreach ($paths === [] ? ['.'] : $paths as $path) {
                $candidate = $base.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
                if (! file_exists($candidate)) {
                    continue;
                }

                $iterator = is_file($candidate)
                    ? new \ArrayIterator([new SplFileInfo($candidate)])
                    : new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($candidate, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );

                foreach ($iterator as $file) {
                    if (! $file->isFile() || $file->isLink()) {
                        continue;
                    }

                    $real = $file->getRealPath();
                    if ($real === false || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
                        continue;
                    }

                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($base) + 1));
                    if ($this->excluded($relative, $excludes, $includeDotfiles, $excludeEnv)) {
                        continue;
                    }

                    if ($zip->addFile($real, $relative)) {
                        $added++;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if ($added === 0) {
            throw new RuntimeException('No application files matched the backup paths and exclusion rules.');
        }
    }

    /** @param list<string> $excludes */
    private function excluded(string $path, array $excludes, bool $includeDotfiles, bool $excludeEnv): bool
    {
        $segments = explode('/', $path);
        if (! $includeDotfiles) {
            foreach ($segments as $segment) {
                if (str_starts_with($segment, '.') && $segment !== '.well-known') {
                    return true;
                }
            }
        }

        if ($excludeEnv && (basename($path) === '.env' || str_starts_with(basename($path), '.env.'))) {
            return true;
        }

        foreach ($excludes as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string) $pattern), '/');
            if ($pattern !== '' && ($path === $pattern || str_starts_with($path, $pattern.'/') || fnmatch($pattern, $path))) {
                return true;
            }
        }

        return false;
    }
}
