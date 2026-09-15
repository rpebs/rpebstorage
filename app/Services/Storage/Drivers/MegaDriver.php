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
use Mega\Crypto\A32;
use Mega\Crypto\Attr;
use Mega\Crypto\Base64Url;
use Mega\Crypto\NodeKey;
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
        $creds = $this->getCredentials($account);
        $parentHandle = $creds['root_handle'] ?? $this->resolveRootHandle($account);

        $size = filesize($localFilePath);
        if ($size === false) {
            throw new RuntimeException("Tidak dapat membaca ukuran berkas lokal: {$localFilePath}");
        }

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
        $creds = $this->getCredentials($account);
        $connector = $this->connector($account);

        $response = $connector->send([
            'a' => 'f',
            'c' => 1,
        ]);

        $rawNodes = $response['f'] ?? [];
        if (! is_array($rawNodes)) {
            return;
        }

        $rootHandle = $creds['root_handle'] ?? null;
        if (empty($rootHandle)) {
            foreach ($rawNodes as $raw) {
                if (($raw['t'] ?? -1) === 2) {
                    $rootHandle = (string) ($raw['h'] ?? '');
                    if ($rootHandle !== '') {
                        $creds['root_handle'] = $rootHandle;
                        $account->forceFill(['credentials' => $creds])->save();
                        break;
                    }
                }
            }
        }

        if (empty($rootHandle)) {
            throw new RuntimeException('Tidak dapat menemukan direktori utama (Root Cloud Drive) di akun MEGA.');
        }

        $masterKey = $creds['master_key'];
        if (is_string($masterKey)) {
            $decoded = json_decode($masterKey, true);
            if (is_array($decoded)) {
                $masterKey = $decoded;
            }
        }
        $masterKeyStr = is_array($masterKey) ? A32::toString($masterKey) : (string) $masterKey;

        // Build parent lookup map for fast ancestor checking:
        $parentMap = [];
        foreach ($rawNodes as $raw) {
            $h = (string) ($raw['h'] ?? '');
            $p = (string) ($raw['p'] ?? '');
            if ($h !== '') {
                $parentMap[$h] = $p;
            }
        }

        // Memoized ancestor check to verify if a handle is inside Cloud Drive ($rootHandle)
        $isDescendantOfRootMemo = [$rootHandle => true];
        $isDescendantOfRoot = function (string $handle) use (&$parentMap, &$isDescendantOfRootMemo): bool {
            if (isset($isDescendantOfRootMemo[$handle])) {
                return $isDescendantOfRootMemo[$handle];
            }

            $visited = [$handle => true];
            $curr = $handle;

            while (isset($parentMap[$curr]) && $parentMap[$curr] !== '') {
                $parent = $parentMap[$curr];
                if (isset($isDescendantOfRootMemo[$parent])) {
                    $result = $isDescendantOfRootMemo[$parent];
                    foreach (array_keys($visited) as $nodeHandle) {
                        $isDescendantOfRootMemo[$nodeHandle] = $result;
                    }

                    return $result;
                }

                if (isset($visited[$parent])) {
                    break;
                }

                $visited[$parent] = true;
                $curr = $parent;
            }

            foreach (array_keys($visited) as $nodeHandle) {
                $isDescendantOfRootMemo[$nodeHandle] = false;
            }

            return false;
        };

        foreach ($rawNodes as $raw) {
            $type = array_key_exists('t', $raw) ? (int) $raw['t'] : -1;
            if ($type !== Node::TYPE_FILE && $type !== Node::TYPE_FOLDER) {
                continue;
            }

            $handle = (string) ($raw['h'] ?? '');
            $parentHandle = (string) ($raw['p'] ?? '');
            $rawKey = (string) ($raw['k'] ?? '');

            if ($handle === '' || $handle === $rootHandle || $rawKey === '') {
                continue;
            }

            // Only include items that are within Root Cloud Drive
            if ($parentHandle !== $rootHandle && ! $isDescendantOfRoot($parentHandle)) {
                continue;
            }

            try {
                $nodeKey = NodeKey::decryptNodeKey($rawKey, $masterKeyStr);
            } catch (\Throwable) {
                continue;
            }

            $name = '';
            if (! empty($raw['a'])) {
                try {
                    $attrCiphertext = Base64Url::decode((string) $raw['a']);
                    $attrs = Attr::decrypt($attrCiphertext, $nodeKey);
                    $name = (string) ($attrs['n'] ?? '');
                } catch (\Throwable) {
                    // Name decryption failed
                }
            }

            if ($name === '') {
                $name = 'Untitled';
            }

            $parentId = ($parentHandle === $rootHandle || $parentHandle === '') ? null : $parentHandle;

            if ($type === Node::TYPE_FOLDER) {
                yield new RemoteItem(
                    id: $handle,
                    name: $name,
                    isFolder: true,
                    parentId: $parentId,
                );
            } else {
                $size = isset($raw['s']) ? (int) $raw['s'] : 0;
                $remoteRef = $handle.'|'.$rawKey;

                yield new RemoteItem(
                    id: $remoteRef,
                    name: $name,
                    isFolder: false,
                    size: $size,
                    mimeType: null,
                    parentId: $parentId,
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
                    $creds = $this->getCredentials($account);
                    $creds['root_handle'] = $rootHandle;
                    $account->forceFill(['credentials' => $creds])->save();

                    return $rootHandle;
                }
            }
        }

        throw new RuntimeException('Tidak dapat menemukan direktori utama (Root Cloud Drive) di akun MEGA.');
    }

    public function client(StorageAccount $account): Client
    {
        $creds = $this->getCredentials($account);

        $masterKey = $creds['master_key'];
        if (is_string($masterKey)) {
            $decoded = json_decode($masterKey, true);
            if (is_array($decoded)) {
                $masterKey = $decoded;
            }
        }
        if (! is_array($masterKey)) {
            $masterKey = A32::fromString($masterKey);
        }

        $session = new Session(
            $masterKey,
            (string) $creds['session_id'],
            isset($creds['private_key']) && is_array($creds['private_key']) ? $creds['private_key'] : []
        );

        $client = (new ClientFactory)->create();
        $client->restoreSession($session);

        return $client;
    }

    public function connector(StorageAccount $account): Connector
    {
        $creds = $this->getCredentials($account);
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
            $connector->setSessionId((string) $creds['session_id']);
        }

        return $connector;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCredentials(StorageAccount $account): array
    {
        /** @var mixed $creds */
        $creds = $account->credentials;
        if (! is_array($creds) || empty($creds['session_id']) || empty($creds['master_key'])) {
            throw new RuntimeException('Kredensial akun MEGA tidak lengkap atau rusak.');
        }

        return $creds;
    }
}
