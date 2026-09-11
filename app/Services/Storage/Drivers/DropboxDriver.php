<?php

namespace App\Services\Storage\Drivers;

use App\Models\StorageAccount;
use App\Values\QuotaUsage;
use App\Values\UploadResult;
use Illuminate\Support\Facades\Http;

class DropboxDriver extends BaseCloudDriver
{
    private const CONTENT = 'https://content.dropboxapi.com/2';
    private const API = 'https://api.dropboxapi.com/2';

    // Single-request upload cap per Dropbox API docs.
    private const SIMPLE_UPLOAD_MAX = 150 * 1024 * 1024;
    private const SESSION_CHUNK = 100 * 1024 * 1024;

    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        $token = $this->token($account);
        $size = filesize($localFilePath);
        $path = '/'.$fileName;

        if ($size <= self::SIMPLE_UPLOAD_MAX) {
            $meta = $this->simpleUpload($token, $localFilePath, $path);

            return new UploadResult($meta['path_lower'], (int) $meta['size']);
        }

        return $this->sessionUpload($token, $localFilePath, $path, $size);
    }

    public function download(StorageAccount $account, string $remoteRef): string
    {
        $dest = $this->newTempPath();

        Http::withToken($this->token($account))
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $remoteRef])])
            ->sink($dest)
            ->post(self::CONTENT.'/files/download')
            ->throw();

        return $dest;
    }

    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        Http::withToken($this->token($account))
            ->post(self::API.'/files/delete_v2', ['path' => $remoteRef])
            ->throw();

        return true;
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        $json = Http::withToken($this->token($account))
            ->post(self::API.'/users/get_space_usage')
            ->throw()
            ->json();

        $allocated = $json['allocation']['allocated'] ?? null;

        return new QuotaUsage($allocated !== null ? (int) $allocated : null, (int) ($json['used'] ?? 0));
    }

    private function simpleUpload(string $token, string $localFilePath, string $path): array
    {
        return Http::withToken($token)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode(['path' => $path, 'mode' => 'overwrite']),
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody(fopen($localFilePath, 'rb'), 'application/octet-stream')
            ->post(self::CONTENT.'/files/upload')
            ->throw()
            ->json();
    }

    private function sessionUpload(string $token, string $localFilePath, string $path, int $size): UploadResult
    {
        $sessionId = Http::withToken($token)
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['close' => false]), 'Content-Type' => 'application/octet-stream'])
            ->withBody(fopen($localFilePath, 'rb'), 'application/octet-stream')
            ->post(self::CONTENT.'/files/upload_session/start')
            ->throw()
            ->json('session_id');

        // The start call already consumed the first SESSION_CHUNK bytes.
        $offset = min(self::SESSION_CHUNK, $size);

        $handle = fopen($localFilePath, 'rb');
        fseek($handle, $offset);

        while ($offset < $size) {
            $chunk = fread($handle, min(self::SESSION_CHUNK, $size - $offset));
            $last = ($offset + strlen($chunk)) >= $size;

            Http::withToken($token)
                ->withHeaders([
                    'Dropbox-API-Arg' => json_encode([
                        'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                        'close' => $last,
                    ]),
                    'Content-Type' => 'application/octet-stream',
                ])
                ->withBody($chunk, 'application/octet-stream')
                ->post(self::CONTENT.'/files/upload_session/append_v2')
                ->throw();

            $offset += strlen($chunk);
        }
        fclose($handle);

        $meta = Http::withToken($token)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => ['session_id' => $sessionId, 'offset' => $size],
                    'commit' => ['path' => $path, 'mode' => 'overwrite'],
                ]),
            ])
            ->post(self::CONTENT.'/files/upload_session/finish')
            ->throw()
            ->json();

        return new UploadResult($meta['path_lower'], (int) ($meta['size'] ?? $size));
    }
}
