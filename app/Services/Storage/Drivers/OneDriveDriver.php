<?php

namespace App\Services\Storage\Drivers;

use App\Models\StorageAccount;
use App\Values\QuotaUsage;
use App\Values\UploadResult;
use Illuminate\Support\Facades\Http;

class OneDriveDriver extends BaseCloudDriver
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const SIMPLE_UPLOAD_MAX = 4 * 1024 * 1024;
    private const SESSION_CHUNK = 10 * 1024 * 1024;

    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        $token = $this->token($account);
        $size = filesize($localFilePath);
        $mime = $this->mimeOf($localFilePath);

        if ($size <= self::SIMPLE_UPLOAD_MAX) {
            $meta = Http::withToken($token)
                ->withBody(fopen($localFilePath, 'rb'), $mime)
                ->put(self::GRAPH.'/me/drive/root:/'.rawurlencode($fileName).':/content')
                ->throw()
                ->json();

            return new UploadResult((string) $meta['id'], (int) ($meta['size'] ?? $size));
        }

        return $this->sessionUpload($token, $localFilePath, $fileName, $size, $mime);
    }

    public function download(StorageAccount $account, string $remoteRef): string
    {
        $dest = $this->newTempPath();

        Http::withToken($this->token($account))
            ->sink($dest)
            ->get(self::GRAPH."/me/drive/items/{$remoteRef}/content")
            ->throw();

        return $dest;
    }

    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        Http::withToken($this->token($account))
            ->delete(self::GRAPH."/me/drive/items/{$remoteRef}")
            ->throw();

        return true;
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        $quota = Http::withToken($this->token($account))
            ->get(self::GRAPH.'/me/drive?select=quota')
            ->throw()
            ->json('quota', []);

        $total = isset($quota['total']) ? (int) $quota['total'] : null;

        return new QuotaUsage($total, (int) ($quota['used'] ?? 0));
    }

    private function sessionUpload(string $token, string $localFilePath, string $fileName, int $size, string $mime): UploadResult
    {
        $session = Http::withToken($token)
            ->post(self::GRAPH.'/me/drive/root:/'.rawurlencode($fileName).':/createUploadSession', [
                'item' => ['@microsoft.graph.conflictBehavior' => 'rename'],
            ])
            ->throw()
            ->json('uploadUrl');

        $handle = fopen($localFilePath, 'rb');
        $offset = 0;

        $meta = [];
        while ($offset < $size) {
            $chunk = fread($handle, min(self::SESSION_CHUNK, $size - $offset));
            $end = $offset + strlen($chunk) - 1;

            $response = Http::withHeaders([
                'Content-Range' => "bytes {$offset}-{$end}/{$size}",
            ])->withBody($chunk, $mime)->put($session);

            if ($offset + strlen($chunk) >= $size) {
                // The final chunk responds with the completed item metadata.
                $response->throw();
                $meta = $response->json();
            } else {
                if ($response->status() !== 202) {
                    $response->throw();
                }
            }

            $offset += strlen($chunk);
        }
        fclose($handle);

        return new UploadResult((string) $meta['id'], (int) ($meta['size'] ?? $size));
    }
}
