<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Storage;

use Aws\S3\MultipartUploader;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use MertAydin\LaravelBackupManager\Contracts\BackupStorage;
use RuntimeException;

final class LaravelFilesystemStorage implements BackupStorage
{
    public function __construct(private readonly FilesystemFactory $filesystem, private readonly string $disk = 'local') {}

    public function put(string $sourcePath, string $destination): string
    {
        if ($this->uploadMultipartWhenSupported($sourcePath, $destination)) {
            return $destination;
        }

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

    private function uploadMultipartWhenSupported(string $sourcePath, string $destination): bool
    {
        $enabled = (bool) config('backup-manager.upload.multipart.enabled', true);
        $minimumBytes = max(5 * 1024 * 1024, (int) config('backup-manager.upload.multipart.threshold_bytes', 64 * 1024 * 1024));
        $partSize = max(5 * 1024 * 1024, (int) config('backup-manager.upload.multipart.part_size_bytes', 8 * 1024 * 1024));
        if (! $enabled || ! is_file($sourcePath)) {
            return false;
        }

        $fileSize = (int) filesize($sourcePath);
        if ($fileSize < $minimumBytes) {
            return false;
        }

        $partSize = (int) (ceil(max($partSize, (int) ceil($fileSize / 10000)) / (1024 * 1024)) * 1024 * 1024);
        if ($partSize > 5 * 1024 * 1024 * 1024) {
            throw new RuntimeException('The backup artifact exceeds the maximum S3 multipart object size.');
        }

        $filesystem = $this->filesystem->disk($this->disk);
        $adapter = method_exists($filesystem, 'getAdapter') ? $filesystem->getAdapter() : null;
        $adapterClass = AwsS3V3Adapter::class;
        $uploaderClass = MultipartUploader::class;
        if (! is_object($adapter)
            || ! is_a($adapter, $adapterClass)
            || ! class_exists($uploaderClass)
            || ! method_exists($adapter, 'getClient')
            || ! method_exists($adapter, 'getBucket')) {
            return false;
        }

        $diskConfig = (array) config('filesystems.disks.'.$this->disk, []);
        $bucket = (string) $adapter->getBucket();
        $root = trim((string) ($diskConfig['root'] ?? ''), '/');
        $key = ltrim(($root === '' ? '' : $root.'/').$destination, '/');
        $uploader = new $uploaderClass($adapter->getClient(), $sourcePath, [
            'bucket' => $bucket,
            'key' => $key,
            'part_size' => $partSize,
        ]);
        $uploader->upload();

        return true;
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
