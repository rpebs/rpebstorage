<?php

namespace App\Console\Commands;

use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Services\Storage\Concerns\HandlesChunking;
use App\Services\Storage\StorageManager;
use App\Services\Telegram\TelegramRpc;
use danog\MadelineProto\API;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Predis\ClientInterface;
use Throwable;

use function Amp\async;

/**
 * Single owner of every MadelineProto session file. Queue jobs never touch
 * Telegram directly; they send telegram:rpc requests through Redis and this
 * daemon executes them, so a session is never held by two processes.
 */
class TelegramListenCommand extends Command
{
    use HandlesChunking;

    protected $signature = 'telegram:listen';

    protected $description = 'Daemon pemilik session Telegram; eksekusi RPC upload/download/delete via Redis';

    /** @var array<int, API> */
    private array $instances = [];

    private ClientInterface $redis;

    public function handle(): int
    {
        if (! TelegramRpc::daemonConfigured()) {
            $this->error('TELEGRAM_API_ID / TELEGRAM_API_HASH belum diisi di .env');

            return self::FAILURE;
        }

        $this->redis = Redis::connection()->client();

        $this->info('telegram:listen berjalan. Menunggu RPC...');

        while (true) {
            $packed = $this->redis->brpop([TelegramRpc::QUEUE], 5);

            if ($packed === null || count($packed) < 2) {
                continue;
            }

            $request = json_decode($packed[1], true) ?? [];

            $reply = $this->safelyHandle($request);

            $this->redis->setex(
                'telegram:rpc:reply:'.($request['id'] ?? 'unknown'),
                TelegramRpc::REPLY_TTL,
                json_encode($reply),
            );
        }
    }

    /**
     * @return array{ok: bool, data?: array, error?: string}
     */
    private function safelyHandle(array $request): array
    {
        try {
            $action = $request['action'] ?? '';

            $data = match ($action) {
                'connect.request_code' => $this->requestCode($request),
                'connect.complete' => $this->completeLogin($request),
                'connect.create_bucket' => $this->createBucket($request),
                'upload' => $this->upload($request),
                'download' => $this->download($request),
                'delete' => $this->delete($request),
                'disconnect' => $this->disconnect($request),
                default => throw new \RuntimeException("Aksi Telegram RPC tidak dikenal: {$action}"),
            };

            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function requestCode(array $request): array
    {
        $mp = $this->mp((int) $request['account_id']);
        $mp->phoneLogin((string) $request['phone']);

        return ['status' => 'code_sent'];
    }

    private function completeLogin(array $request): array
    {
        $mp = $this->mp((int) $request['account_id']);

        if (! empty($request['password'])) {
            $mp->complete2faLogin((string) $request['password']);
        } else {
            try {
                $mp->completePhoneLogin((string) $request['code']);
            } catch (RPCErrorException $e) {
                if ($e->getMessage() === 'SESSION_PASSWORD_NEEDED') {
                    return ['status' => 'password_needed'];
                }

                throw $e;
            }
        }

        $self = $mp->getSelf();

        return [
            'status' => 'authorized',
            'phone' => (string) ($self['phone'] ?? ''),
            'name' => trim(($self['first_name'] ?? '').' '.($self['last_name'] ?? '')),
        ];
    }

    private function createBucket(array $request): array
    {
        $accountId = (int) $request['account_id'];
        $account = StorageAccount::findOrFail($accountId);
        $mp = $this->mp($accountId);

        $result = async(fn () => $mp->channels->createChannel([
            'title' => 'rpebstorage bucket',
            'about' => 'Private storage bucket dibuat oleh rpebstorage',
            'megagroup' => false,
        ]))->await();

        $chat = $result['chats'][0] ?? ($result['updates']['chats'][0] ?? null);

        if ($chat === null || ! isset($chat['id'])) {
            throw new \RuntimeException('Gagal membuat channel bucket Telegram.');
        }

        $meta = $account->meta ?? [];
        $meta['channel_id'] = (int) $chat['id'];
        $meta['channel_hash'] = (string) $chat['access_hash'];
        $account->meta = $meta;
        $account->save();

        return ['channel_id' => $meta['channel_id']];
    }

    private function upload(array $request): array
    {
        $accountId = (int) $request['account_id'];
        $account = StorageAccount::findOrFail($accountId);
        $mp = $this->mp($accountId);
        $peer = $this->bucketPeer($account);
        $peerId = (int) ('-100'.$peer['channel_id']);

        $path = (string) $request['path'];
        $name = (string) $request['name'];
        $size = (int) $request['size'];
        $jobId = isset($request['job_id']) ? (int) $request['job_id'] : null;
        $chunkSize = (int) config('rpebs.telegram.chunk_size');

        if ($size <= $chunkSize) {
            $messageId = $this->sendWithThrottle($mp, $peerId, $path, $name);

            return ['remote_ref' => (string) $messageId, 'chunks' => null];
        }

        $parts = $this->splitFile($path, $chunkSize);
        $total = count($parts);
        $chunks = [];

        foreach ($parts as $index => $part) {
            $messageId = $this->sendWithThrottle($mp, $peerId, $part['path'], "{$name}.part{$index}");

            $chunks[] = [
                'chunk_index' => $index,
                'remote_file_id' => (string) $messageId,
                'size' => $part['size'],
                'checksum' => $part['checksum'],
            ];

            if ($jobId !== null) {
                FileJob::where('id', $jobId)->update([
                    'progress' => (int) (5 + (80 * ($index + 1) / $total)),
                ]);
            }
        }

        foreach ($parts as $part) {
            @unlink($part['path']);
        }

        return ['remote_ref' => $chunks[0]['remote_file_id'], 'chunks' => $chunks];
    }

    private function download(array $request): array
    {
        $accountId = (int) $request['account_id'];
        $account = StorageAccount::findOrFail($accountId);
        $mp = $this->mp($accountId);
        $peer = $this->bucketPeer($account);
        $dest = (string) $request['dest'];

        $chunks = $request['chunks'] ?? [];

        if ($chunks === []) {
            $media = $this->fetchMedia($mp, $peer, (int) $request['remote_ref']);
            async(fn () => $mp->downloadToFile($media, $dest))->await();

            return ['dest' => $dest];
        }

        // Chunked file: download every part, verify checksum, then merge.
        $this->manager()->ensureTempDir();
        $parts = [];

        foreach ($chunks as $chunk) {
            $partPath = $this->manager()->tempPath(uniqid('chunk_', true));
            $media = $this->fetchMedia($mp, $peer, (int) $chunk['remote_file_id']);

            async(fn () => $mp->downloadToFile($media, $partPath))->await();

            $parts[] = [
                'path' => $partPath,
                'size' => (int) $chunk['size'],
                'checksum' => (string) $chunk['checksum'],
            ];
        }

        $this->mergeChunks($parts, $dest);

        foreach ($parts as $part) {
            @unlink($part['path']);
        }

        return ['dest' => $dest];
    }

    private function delete(array $request): array
    {
        $accountId = (int) $request['account_id'];
        $account = StorageAccount::findOrFail($accountId);
        $mp = $this->mp($accountId);
        $peer = $this->bucketPeer($account);

        $ids = [(int) $request['remote_ref']];

        foreach ($request['chunks'] ?? [] as $chunk) {
            $ids[] = (int) $chunk['remote_file_id'];
        }

        async(fn () => $mp->channels->deleteMessages($peer, $ids))->await();

        return ['deleted' => count($ids)];
    }

    private function disconnect(array $request): array
    {
        $accountId = (int) $request['account_id'];

        if (isset($this->instances[$accountId])) {
            try {
                async(fn () => $this->instances[$accountId]->stop())->await();
            } catch (Throwable) {
                // The session file is removed right after; failures here are not fatal.
            }

            unset($this->instances[$accountId]);
        }

        return ['stopped' => true];
    }

    private function fetchMedia(API $mp, array $peer, int $messageId): mixed
    {
        $result = async(fn () => $mp->channels->getMessages($peer, [$messageId]))->await();

        $messages = $result['messages'] ?? [];

        $media = $messages[0]['media'] ?? null;

        if ($media === null) {
            throw new \RuntimeException("Pesan {$messageId} tidak ditemukan di channel bucket Telegram.");
        }

        return $media;
    }

    private function sendWithThrottle(API $mp, int $peerId, string $path, string $name): int
    {
        sleep((int) config('rpebs.telegram.upload_delay'));

        try {
            $message = async(fn () => $mp->uploadDocument(
                file: new LocalFile($path),
                peer: $peerId,
                fileName: $name,
                silent: true,
            ))->await();
        } catch (RPCErrorException $e) {
            if (! str_contains($e->getMessage(), 'FLOOD_WAIT_')) {
                throw $e;
            }

            // FLOOD_WAIT_<seconds>: back off once, then retry.
            $wait = (int) (preg_replace('/\D/', '', $e->getMessage()) ?: 15);
            sleep(min($wait, 120));

            $message = async(fn () => $mp->uploadDocument(
                file: new LocalFile($path),
                peer: $peerId,
                fileName: $name,
                silent: true,
            ))->await();
        }

        $messageId = $this->extractMessageId($message);

        if ($messageId === null) {
            throw new \RuntimeException('Telegram tidak mengembalikan message_id setelah upload.');
        }

        return $messageId;
    }

    private function extractMessageId(mixed $result): ?int
    {
        if (is_object($result) && isset($result->id)) {
            return (int) $result->id;
        }

        $found = null;

        if (is_array($result)) {
            array_walk_recursive($result, function ($value, $key) use (&$found) {
                if ($found === null && $key === 'message_id' && is_int($value)) {
                    $found = $value;
                }
            });
        }

        return $found;
    }

    private function bucketPeer(StorageAccount $account): array
    {
        $meta = $account->meta ?? [];

        if (! isset($meta['channel_id'], $meta['channel_hash'])) {
            throw new \RuntimeException('Akun Telegram belum punya channel bucket. Hubungkan ulang akun.');
        }

        return [
            '_' => 'inputPeerChannel',
            'channel_id' => (int) $meta['channel_id'],
            'access_hash' => (string) $meta['channel_hash'],
        ];
    }

    private function mp(int $accountId): API
    {
        if (isset($this->instances[$accountId])) {
            return $this->instances[$accountId];
        }

        return $this->instances[$accountId] = new API($this->sessionPath($accountId), $this->settings());
    }

    private function settings(): Settings
    {
        $settings = new Settings;
        $settings->getAppInfo()
            ->setApiId((int) config('rpebs.telegram.api_id'))
            ->setApiHash((string) config('rpebs.telegram.api_hash'));

        return $settings;
    }

    private function sessionPath(int $accountId): string
    {
        $dir = storage_path('app/'.config('rpebs.telegram.session_path'));

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir."/{$accountId}.madeline";
    }

    private function manager(): StorageManager
    {
        return app(StorageManager::class);
    }
}
