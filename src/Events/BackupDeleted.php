<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BackupDeleted
{
    use Dispatchable;
    use SerializesModels;

    /** @param list<string> $disks */
    public function __construct(public readonly string $backupId, public readonly array $disks = []) {}
}
