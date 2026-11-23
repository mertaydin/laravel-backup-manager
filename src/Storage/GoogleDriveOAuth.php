<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Storage;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleDriveOAuth
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function authorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        if ($clientId === '' || $redirectUri === '' || $state === '') {
            throw new RuntimeException('Google OAuth client ID, callback URL and state are required.');
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code, string $clientId, string $clientSecret, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google OAuth authorization failed: '.$response->body());
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => time() + (int) $response->json('expires_in', 3600),
            'scope' => $response->json('scope'),
            'token_type' => $response->json('token_type', 'Bearer'),
        ];
    }
}
