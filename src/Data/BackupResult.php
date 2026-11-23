<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Data;

final class BackupResult
{
    /** @param array<string, mixed> $artifacts
     *  @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $successful,
        public readonly array $artifacts = [],
        public readonly array $metadata = [],
        public readonly ?string $error = null,
    ) {}

    public static function success(string $id, array $artifacts = [], array $metadata = []): self
    {
        return new self($id, true, $artifacts, $metadata);
    }

    public static function failure(string $id, string $error, array $metadata = []): self
    {
        return new self($id, false, [], $metadata, $error);
    }
}
