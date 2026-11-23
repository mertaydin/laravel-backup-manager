<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MertAydin\LaravelBackupManager\Data\BackupResult;

final class BackupCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly BackupResult $result) {}
}
