<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Storage;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Exposes Google Drive as a Laravel/Flysystem disk so the complete backup
 * lifecycle (upload, inspect, verify, download, restore and delete) uses it.
 */
final class GoogleDriveFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(
        private readonly GoogleDriveClient $client,
        private readonly string $rootFolderId = 'root',
    ) {}

    public function fileExists(string $path): bool
    {
        return $this->find($path, false) !== null;
    }

    public function directoryExists(string $path): bool
    {
        try {
            $this->folderId($path, false);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'backup-drive-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary Google Drive upload file.');
        }

        try {
            if (file_put_contents($temporary, $contents) === false) {
                throw new RuntimeException('Unable to write the temporary Google Drive upload file.');
            }
            $stream = fopen($temporary, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the temporary Google Drive upload stream.');
            }
            try {
                $this->writeStream($path, $stream, $config);
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($temporary);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw new RuntimeException('Invalid Google Drive upload stream.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'backup-drive-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary Google Drive upload file.');
        }

        $target = fopen($temporary, 'wb');
        if ($target === false) {
            @unlink($temporary);
            throw new RuntimeException('Unable to open the temporary Google Drive upload file.');
        }

        try {
            if (stream_copy_to_stream($contents, $target) === false) {
                throw new RuntimeException('Unable to stage the Google Drive upload stream.');
            }
        } finally {
            fclose($target);
        }

        try {
            $parent = $this->folderId(dirname($path), true);
            $existing = $this->find($path, false);
            if ($existing !== null) {
                $this->client->delete((string) $existing['id']);
            }
            $this->client->upload($temporary, basename($path), $parent);
        } finally {
            @unlink($temporary);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new RuntimeException('Unable to read the Google Drive backup file.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        $file = $this->find($path, false);
        if ($file === null) {
            throw new RuntimeException("Google Drive file [{$path}] was not found.");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'backup-drive-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary Google Drive download file.');
        }

        try {
            $this->client->download((string) $file['id'], $temporary);
            $stream = fopen($temporary, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the downloaded Google Drive file.');
            }
            @unlink($temporary);

            return $stream;
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    public function delete(string $path): void
    {
        $file = $this->find($path, false);
        if ($file !== null) {
            $this->client->delete((string) $file['id']);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $folder = $this->find($path, true);
        if ($folder === null) {
            return;
        }

        foreach ($this->client->list((string) $folder['id']) as $child) {
            $this->client->delete((string) $child['id']);
        }
        $this->client->delete((string) $folder['id']);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->folderId($path, true);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Drive permissions are controlled by the connected Google account.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        $file = $this->find($path, false);

        return new FileAttributes($path, null, null, isset($file['createdTime']) ? strtotime((string) $file['createdTime']) : null);
    }

    public function fileSize(string $path): FileAttributes
    {
        $file = $this->find($path, false);

        return new FileAttributes($path, isset($file['size']) ? (int) $file['size'] : null);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $folderId = $this->folderId($path, false);
        foreach ($this->client->list($folderId) as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $location = trim($path.'/'.$name, '/');
            if (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                yield new DirectoryAttributes($location);
                if ($deep) {
                    yield from $this->listContents($location, true);
                }
            } else {
                yield new FileAttributes(
                    $location,
                    isset($item['size']) ? (int) $item['size'] : null,
                    'private',
                    isset($item['createdTime']) ? strtotime((string) $item['createdTime']) : null,
                    'application/octet-stream',
                );
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $file = $this->find($source, false);
        if ($file === null) {
            throw new RuntimeException("Google Drive file [{$source}] was not found.");
        }
        $this->client->move((string) $file['id'], $this->folderId(dirname($destination), true), basename($destination));
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $file = $this->find($source, false);
        if ($file === null) {
            throw new RuntimeException("Google Drive file [{$source}] was not found.");
        }
        $this->client->copy((string) $file['id'], $this->folderId(dirname($destination), true), basename($destination));
    }

    private function find(string $path, bool $directory): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return ['id' => $this->rootFolderId, 'mimeType' => 'application/vnd.google-apps.folder'];
        }

        $parent = $this->rootFolderId;
        $parts = explode('/', $path);
        foreach ($parts as $index => $part) {
            $isDirectory = $directory || $index < count($parts) - 1;
            $matches = array_values(array_filter(
                $this->client->list($parent),
                static fn (array $item): bool => ($item['name'] ?? null) === $part
                    && (($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder') === $isDirectory,
            ));
            if ($matches === []) {
                return null;
            }
            $parent = (string) $matches[0]['id'];
            $found = $matches[0];
        }

        return $found ?? null;
    }

    private function folderId(string $path, bool $create): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || $path === '.') {
            return $this->rootFolderId;
        }

        $parent = $this->rootFolderId;
        foreach (explode('/', $path) as $part) {
            $matches = array_values(array_filter(
                $this->client->list($parent),
                static fn (array $item): bool => ($item['name'] ?? null) === $part
                    && ($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder',
            ));
            if ($matches !== []) {
                $parent = (string) $matches[0]['id'];

                continue;
            }
            if (! $create) {
                throw new RuntimeException("Google Drive folder [{$path}] was not found.");
            }
            $parent = $this->client->createFolder($part, $parent);
        }

        return $parent;
    }
}
