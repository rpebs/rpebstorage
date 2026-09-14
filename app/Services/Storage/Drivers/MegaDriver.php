<?php

namespace App\Services\Storage\Drivers;

use App\Contracts\StorageDriverInterface;
use App\Models\StorageAccount;
use App\Services\Storage\StorageManager;
use App\Values\QuotaUsage;
use App\Values\RemoteItem;
use App\Values\UploadResult;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Mega\Client;
use Mega\ClientFactory;
use Mega\Config;
use Mega\Entity\Node;
use Mega\Entity\Session;
use Mega\Transport\Connector;
use Psr\Log\NullLogger;
use RuntimeException;

class MegaDriver implements StorageDriverInterface
{
    public function __construct(
        protected StorageManager $manager,
    ) {}

    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        $client = $this->client($account);
        $parentHandle = $account->credentials['root_handle'] ?? $this->resolveRootHandle($account);
        $size = filesize($localFilePath);

        $node = $client->uploadFile($localFilePath, $parentHandle, $fileName, $size);

        $remoteRef = $node->getHandle().'|'.$node->getEncryptedKey();

        return new UploadResult($remoteRef, $size);
    }

    public function download(StorageAccount $account, string $remoteRef): string
    {
        $this->manager->ensureTempDir();
        $dest = $this->manager->tempPath(uniqid('mega_', true));

        $client = $this->client($account);

        if (str_contains($remoteRef, '|')) {
            [$handle, $encryptedKey] = explode('|', $remoteRef, 2);
            $node = new Node($handle, Node::TYPE_FILE, basename($dest), $encryptedKey);
        } else {
            $nodes = $client->listNodes();
            $node = null;
            foreach ($nodes as $n) {
                if ($n->getHandle() === $remoteRef) {
                    $node = $n;
                    break;
                }
            }
            if (! $node) {
                throw new RuntimeException("Berkas dengan handle {$remoteRef} tidak ditemukan di akun MEGA.");
            }
        }

        $stream = fopen($dest, 'wb');
        if ($stream === false) {
            throw new RuntimeException("Gagal membuka berkas lokal untuk menulis: {$dest}");
        }

        try {
            $client->downloadFile($node, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $dest;
    }

    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        $handle = str_contains($remoteRef, '|') ? explode('|', $remoteRef, 2)[0] : $remoteRef;
        $client = $this->client($account);
        $client->deleteNode($handle);

        return true;
    }

    public function getRemainingQuota(StorageAccount $account): ?int
    {
        $usage = $this->getQuotaUsage($account);

        return $usage->total === null ? null : max(0, $usage->total - $usage->used);
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        $connector = $this->connector($account);
        $response = $connector->send([
            'a' => 'uq',
            'strg' => 1,
        ]);

        $total = isset($response['mstrg']) ? (int) $response['mstrg'] : null;
        $used = isset($response['cstrg']) ? (int) $response['cstrg'] : 0;

        return new QuotaUsage($total, $used);
    }

    public function listFiles(StorageAccount $account): iterable
    {
        $client = $this->client($account);
        $rootHandle = $account->credentials['root_handle'] ?? $this->resolveRootHandle($account);
        $nodes = $client->listNodes();

        foreach ($nodes as $node) {
            $type = $node->getType();
            $handle = $node->getHandle();

            if ($handle === $rootHandle) {
                continue;
            }

            if ($type === Node::TYPE_FOLDER) {
                $parentHandle = $node->getParentHandle();
                yield new RemoteItem(
                    id: $handle,
                    name: $node->getName(),
                    isFolder: true,
                    parentId: $parentHandle === $rootHandle ? null : $parentHandle,
                );
            } elseif ($type === Node::TYPE_FILE) {
                $parentHandle = $node->getParentHandle();
                $remoteRef = $handle.'|'.$node->getEncryptedKey();

                yield new RemoteItem(
                    id: $remoteRef,
                    name: $node->getName(),
                    isFolder: false,
                    size: $node->getSize(),
                    mimeType: null,
                    parentId: $parentHandle === $rootHandle ? null : $parentHandle,
                );
            }
        }
    }

    public function resolveRootHandle(StorageAccount $account): string
    {
        $connector = $this->connector($account);
        $response = $connector->send([
            'a' => 'f',
            'c' => 1,
        ]);

        foreach ($response['f'] ?? [] as $raw) {
            if (($raw['t'] ?? -1) === 2) {
                $rootHandle = (string) ($raw['h'] ?? '');
                if ($rootHandle !== '') {
                    $creds = $account->credentials;
                    $creds['root_handle'] = $rootHandle;
                    $account->credentials = $creds;
                    $account->save();

                    return $rootHandle;
                }
            }
        }

        throw new RuntimeException('Tidak dapat menemukan direktori utama (Root Cloud Drive) di akun MEGA.');
    }

    public function client(StorageAccount $account): Client
    {
        $creds = $account->credentials;
        if (! is_array($creds) || empty($creds['session_id']) || empty($creds['master_key'])) {
            throw new RuntimeException('Kredensial akun MEGA tidak lengkap atau rusak.');
        }

        $session = new Session(
            $creds['master_key'],
            $creds['session_id'],
            $creds['private_key'] ?? []
        );

        $client = (new ClientFactory)->create();
        $client->restoreSession($session);

        return $client;
    }

    public function connector(StorageAccount $account): Connector
    {
        $creds = $account->credentials;
        $httpClient = Psr18ClientDiscovery::find();
        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        $connector = new Connector(
            Config::SERVER_GLOBAL,
            $httpClient,
            $requestFactory,
            $streamFactory,
            new NullLogger
        );

        if (! empty($creds['session_id'])) {
            $connector->setSessionId($creds['session_id']);
        }

        return $connector;
    }
}
