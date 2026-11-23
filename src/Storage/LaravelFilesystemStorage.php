<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Storage;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;
use MertAydin\LaravelBackupManager\Contracts\BackupStorage;
use RuntimeException;

final class LaravelFilesystemStorage implements BackupStorage
{
    public function __construct(private readonly FilesystemFactory $filesystem, private readonly string $disk = 'local')
    {
    }

    public function put(string $sourcePath, string $destination): string
    {
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open backup artifact for upload.');
        }

        try {
            $written = $this->filesystem->disk($this->disk)->writeStream($destination, $stream);
            if (! $written) {
                throw new RuntimeException('The configured backup storage disk rejected the artifact.');
            }
        } finally {
            fclose($stream);
        }

        return $destination;
    }

    public function get(string $source, string $destinationPath): string
    {
        $stream = $this->filesystem->disk($this->disk)->readStream($source);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read the requested backup artifact.');
        }

        $target = fopen($destinationPath, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create the local destination file.');
        }

        try {
            if (stream_copy_to_stream($stream, $target) === false) {
                throw new RuntimeException('Unable to download the backup artifact completely.');
            }
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $destinationPath;
    }

    public function delete(string $path): bool
    {
        return $this->filesystem->disk($this->disk)->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->disk($this->disk)->exists($path);
    }

    public function list(string $prefix = ''): Collection
    {
        return collect($this->filesystem->disk($this->disk)->allFiles($prefix));
    }
}
