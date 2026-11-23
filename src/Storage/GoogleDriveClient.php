<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Storage;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleDriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';

    /** @var array<string, mixed> */
    private array $credentials;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    public function testConnection(): void
    {
        $response = Http::withToken($this->accessToken())
            ->get(self::API.'/about', ['fields' => 'user(displayName,emailAddress)']);

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive connection test failed: '.$response->body());
        }
    }

    /**
     * Uploads a local file using Google's resumable upload endpoint.
     * The file is streamed and is never loaded into PHP memory in full.
     */
    public function upload(string $localPath, string $name, ?string $folderId = null): string
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new RuntimeException('The local backup artifact cannot be read.');
        }

        $metadata = ['name' => $name];
        if ($folderId ! == null && $folderId ! == '') {
            $metadata['parents'] = [$folderId];
        }

        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException('Unable to determine the backup artifact size.');
        }

        $session = Http::withToken($this->accessToken())
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->post(self::UPLOAD_API.'/files?uploadType=resumable&fields=id,name,size', $metadata);

        if (! $session->successful() || ! $session->header('Location')) {
            throw new RuntimeException('Google Drive did not create an upload session: '.$session->body());
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the backup artifact for upload.');
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->withHeaders(['Content-Type' => 'application/octet-stream'])
                ->withOptions(['body' => $stream])
                ->put($session->header('Location'));
        } finally {
            fclose($stream);
        }

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new RuntimeException('Google Drive upload failed: '.$response->body());
        }

        return $response->json('id');
    }

    /** @return array<int, array<string, mixed>> */
    public function list(string $folderId): array
    {
        $response = Http::withToken($this->accessToken())->get(self::API.'/files', [
            'q' => '\''.$this->escapeQuery($folderId).'\' in parents and trashed = false',
            'fields' => 'files(id,name,mimeType,size,createdTime)',
            'pageSize' => 1000,
            'orderBy' => 'createdTime desc',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to list Google Drive backups: '.$response->body());
        }

        return $response->json('files', []);
    }

    public function delete(string $fileId): void
    {
        $response = Http::withToken($this->accessToken())->delete(self::API.'/files/'.rawurlencode($fileId));
        if (! $response->successful() && $response->status() ! == 404) {
            throw new RuntimeException('Unable to delete the Google Drive backup: '.$response->body());
        }
    }

    public function download(string $fileId, string $destination): void
    {
        $response = Http::withToken($this->accessToken())->withOptions(['sink' => $destination])
            ->get(self::API.'/files/'.rawurlencode($fileId), ['alt' => 'media']);

        if (! $response->successful()) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw new RuntimeException('Unable to download the Google Drive backup: '.$response->body());
        }
    }

    public function createFolder(string $name, string $parentId): string
    {
        $response = Http::withToken($this->accessToken())->post(self::API.'/files?fields=id', [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId],
        ]);

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new RuntimeException('Unable to create a Google Drive folder: '.$response->body());
        }

        return $response->json('id');
    }

    public function move(string $fileId, string $parentId, string $name): void
    {
        $file = Http::withToken($this->accessToken())->get(self::API.'/files/'.rawurlencode($fileId), ['fields' => 'parents']);
        if (! $file->successful()) {
            throw new RuntimeException('Unable to inspect the Google Drive file before moving it.');
        }

        $parents = implode(',', (array) $file->json('parents', []));
        $response = Http::withToken($this->accessToken())->patch(self::API.'/files/'.rawurlencode($fileId), [
            'name' => $name,
            'addParents' => $parentId,
            'removeParents' => $parents,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to move the Google Drive file: '.$response->body());
        }
    }

    public function copy(string $fileId, string $parentId, string $name): void
    {
        $response = Http::withToken($this->accessToken())->post(self::API.'/files/'.rawurlencode($fileId).'/copy', [
            'name' => $name,
            'parents' => [$parentId],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to copy the Google Drive file: '.$response->body());
        }
    }

    private function accessToken(): string
    {
        $token = (string) ($this->credentials['access_token'] ?? '');
        $expiresAt = (int) ($this->credentials['expires_at'] ?? 0);

        if ($token ! == '' && $expiresAt > time() + 60) {
            return $token;
        }

        $refreshToken = (string) ($this->credentials['refresh_token'] ?? '');
        $clientId = (string) ($this->credentials['client_id'] ?? '');
        $clientSecret = (string) ($this->credentials['client_secret'] ?? '');

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Google Drive OAuth credentials are incomplete. Reconnect the Google account from Storage Management.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google Drive OAuth token refresh failed: '.$response->body());
        }

        $this->credentials['access_token'] = $response->json('access_token');
        $this->credentials['expires_at'] = time() + (int) $response->json('expires_in', 3600);

        return $this->credentials['access_token'];
    }

    private function escapeQuery(string $value): string
    {
        return addcslashes($value, chr(92).chr(39));
    }
}
