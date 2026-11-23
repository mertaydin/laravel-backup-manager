<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BackupRestored
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly string $backupId, public readonly string $component) {}
}
