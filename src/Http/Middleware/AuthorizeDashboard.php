<?php

declare(strict_types=1);

namespace MertAydin\\LaravelBackupManager\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use MertAydin\\LaravelBackupManager\\Services\\DashboardAuthorizer;
use Symfony\\Component\\HttpFoundation\\Response;

final class AuthorizeDashboard
{
    public function __construct(private readonly DashboardAuthorizer $authorizer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->authorizer->allows($request), 403);

        return $next($request);
    }
}
