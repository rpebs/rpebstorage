<?php

namespace App\Services\Storage\Drivers;

use App\Models\StorageAccount;
use App\Services\Storage\OAuth\ProviderOAuth;
use App\Services\Storage\StorageManager;
use App\Values\QuotaUsage;
use App\Values\UploadResult;
use Illuminate\Support\Facades\Http;

class GoogleDriveDriver extends BaseCloudDriver
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public function __construct(
        ProviderOAuth $oauth,
        StorageManager $manager,
    ) {
        parent::__construct($oauth, $manager);
    }

    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        $token = $this->token($account);
        $size = filesize($localFilePath);
        $mime = $this->mimeOf($localFilePath);

        // Resumable session upload: works for files of any size.
        $session = Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Type' => $mime,
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->post(self::UPLOAD.'?uploadType=resumable&fields=id,size', ['name' => $fileName])
            ->throw();

        $location = $session->header('Location');

        $uploaded = Http::withToken($token)
            ->withHeaders(['Content-Length' => (string) $size])
            ->withBody(fopen($localFilePath, 'rb'), $mime)
            ->put($location)
            ->throw()
            ->json();

        return new UploadResult((string) $uploaded['id'], (int) ($uploaded['size'] ?? $size));
    }

    public function download(StorageAccount $account, string $remoteRef): string
    {
        $dest = $this->newTempPath();

        Http::withToken($this->token($account))
            ->sink($dest)
            ->get(self::API."/files/{$remoteRef}?alt=media")
            ->throw();

        return $dest;
    }

    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        Http::withToken($this->token($account))
            ->delete(self::API."/files/{$remoteRef}")
            ->throw();

        return true;
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        $quota = Http::withToken($this->token($account))
            ->get(self::API.'/about?fields=storageQuota')
            ->throw()
            ->json('storageQuota', []);

        $total = isset($quota['limit']) ? (int) $quota['limit'] : null;
        $used = (int) ($quota['usage'] ?? 0);

        return new QuotaUsage($total, $used);
    }
}
