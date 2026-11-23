<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Contracts;

use Illuminate\Support\Collection;

interface BackupStorage
{
    public function put(string $sourcePath, string $destination): string;

    public function get(string $source, string $destinationPath): string;

    public function delete(string $path): bool;

    public function exists(string $path): bool;

    /** @return Collection<int, array<string, mixed>> */
    public function list(string $prefix = ''): Collection;
}
