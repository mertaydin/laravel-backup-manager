<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager\\Services;

use Closure;
use Illuminate\\Http\\Request;

final class DashboardAuthorizer
{
    private ?Closure $callback = null;

    public function auth(callable $callback): void
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function allows(Request $request): bool
    {
        // Keep the existing auth-middleware behavior unless a custom policy is registered.
        return $this->callback === null || (bool) ($this->callback)($request);
    }
}
