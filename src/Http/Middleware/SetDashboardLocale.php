<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetDashboardLocale
{
    public const SUPPORTED_LOCALES = ['en', 'tr', 'es', 'zh_CN', 'hi', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('backup_manager_locale');
        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = in_array($request->getLocale(), self::SUPPORTED_LOCALES, true)
                ? $request->getLocale()
                : 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
