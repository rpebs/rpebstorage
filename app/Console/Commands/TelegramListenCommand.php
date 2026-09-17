<?php

namespace App\Console\Commands;

use Amp\CancelledException;
use Amp\Future\UnhandledFutureError;
use Amp\SignalException;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Services\Storage\Concerns\HandlesChunking;
use App\Services\Storage\StorageManager;
use App\Services\Telegram\TelegramRpc;
use danog\DialogId\DialogId;
use danog\MadelineProto\API;
use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\InternalDoc;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Magic;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SecurityException;
use danog\MadelineProto\Settings;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Predis\ClientInterface;
use Revolt\EventLoop;
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
            $this->error('API ID / API Hash Telegram belum diisi. Buka Settings → Provider di aplikasi.');

            return self::FAILURE;
        }

        $this->registerLoopErrorHandler();

        $this->redis = Redis::connection()->client();

        $this->info('telegram:listen berjalan. Menunggu RPC...');

        while (true) {
            // Heartbeat so the web side can fail fast when this daemon dies.
            $this->redis->setex(TelegramRpc::HEARTBEAT_KEY, 15, '1');

            $packed = $this->redis->brpop([TelegramRpc::QUEUE], 5);

            if ($packed === null || count($packed) < 2) {
                continue;
            }

            $request = json_decode($packed[1], true) ?? [];

            $reply = $this->safelyHandle($request);

            try {
                $this->redis->setex(
                    'telegram:rpc:reply:'.($request['id'] ?? 'unknown'),
                    TelegramRpc::REPLY_TTL,
                    json_encode($reply),
                );
            } catch (Throwable $e) {
                // A failed reply must not kill the daemon; the client times out on its side.
                report($e);
            }
        }
    }

    private function registerLoopErrorHandler(): void
    {
        EventLoop::setErrorHandler(static function (Throwable $e): void {
            if ($e instanceof UnhandledFutureError) {
                $e = $e->getPrevious() ?? $e;
            }

            // Normal cancellation of background event loops, pings, or timeouts
            if ($e instanceof CancelledException) {
                return;
            }

            if ($e instanceof SecurityException || $e instanceof SignalException) {
                throw $e;
            }

            if (str_starts_with($e->getMessage(), 'Could not connect to DC ')) {
                throw $e;
            }

            Log::warning('Telegram daemon event loop warning: '.$e->getMessage(), [
                'exception' => $e,
            ]);
        });
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

        $prop = new \ReflectionProperty(InternalDoc::class, 'wrapper');
        $wrapper = $prop->getValue($mp);
        $raw = $wrapper instanceof APIWrapper ? $wrapper->getAPI() : null;

        $hash = null;
        $mtprotoId = null;

        // 1. Re-use an existing bucket channel created earlier if present in dialogs.
        if ($raw !== null) {
            try {
                $dialogs = async(fn () => $raw->methodCallAsyncRead('messages.getDialogs', [
                    'offset_date' => 0,
                    'offset_id' => 0,
                    'offset_peer' => ['_' => 'inputPeerEmpty'],
                    'limit' => 100,
                    'hash' => 0,
                ]))->await();

                foreach ($dialogs['chats'] ?? [] as $c) {
                    if (! is_array($c)) {
                        continue;
                    }
                    if (($c['title'] ?? '') === 'rpebstorage bucket' && ! empty($c['access_hash']) && empty($c['left'])) {
                        $mtprotoId = DialogId::toMTProtoId((int) $c['id']);
                        $hash = $c['access_hash'];
                        break;
                    }
                }
            } catch (Throwable) {
            }
        }

        // 2. If not found, create a new broadcast channel.
        if (! $hash) {
            $result = async(fn () => $mp->channels->createChannel(
                broadcast: true,
                megagroup: false,
                title: 'rpebstorage bucket',
                about: 'Private storage bucket dibuat oleh rpebstorage',
            ))->await();

            $chat = $this->findChannel($result);
            $chatId = is_array($chat) ? (int) ($chat['id'] ?? 0) : 0;

            if ($chatId === 0) {
                throw new \RuntimeException('Gagal membuat channel bucket Telegram (respons tanpa chat id).');
            }

            $mtprotoId = DialogId::toMTProtoId($chatId);
            $hash = $chat['access_hash'] ?? null;
        }

        // 3. Freshly created channels often omit access_hash in the createChannel response.
        // Resolve it via messages.getDialogs or MadelineProto peer methods.
        if (! $hash && $mtprotoId !== null) {
            $botApiId = DialogId::fromSupergroupOrChannelId($mtprotoId);

            for ($attempt = 0; $attempt < 6 && ! $hash; $attempt++) {
                if ($attempt > 0) {
                    async(fn () => \Amp\delay(1))->await();
                }

                if ($raw !== null) {
                    try {
                        $dialogs = async(fn () => $raw->methodCallAsyncRead('messages.getDialogs', [
                            'offset_date' => 0,
                            'offset_id' => 0,
                            'offset_peer' => ['_' => 'inputPeerEmpty'],
                            'limit' => 100,
                            'hash' => 0,
                        ]))->await();

                        foreach ($dialogs['chats'] ?? [] as $c) {
                            if (! is_array($c)) {
                                continue;
                            }
                            $cMtprotoId = DialogId::toMTProtoId((int) ($c['id'] ?? 0));
                            if (($cMtprotoId === $mtprotoId || ($c['title'] ?? '') === 'rpebstorage bucket') && ! empty($c['access_hash'])) {
                                $hash = $c['access_hash'];
                                $mtprotoId = $cMtprotoId;
                                break;
                            }
                        }
                    } catch (Throwable) {
                    }
                }

                if (! $hash) {
                    try {
                        async(fn () => $mp->getFullDialogs())->await();
                    } catch (Throwable) {
                    }
                }

                if (! $hash) {
                    try {
                        $info = async(fn () => $mp->getInfo($botApiId))->await();
                        if (is_array($info)) {
                            $hash = $info['Chat']['access_hash'] ?? ($info['access_hash'] ?? null);
                        }
                    } catch (Throwable) {
                    }
                }

                if (! $hash) {
                    try {
                        $pwr = async(fn () => $mp->getPwrChat($botApiId))->await();
                        if (is_array($pwr)) {
                            $hash = $pwr['Chat']['access_hash'] ?? ($pwr['access_hash'] ?? null);
                        }
                    } catch (Throwable) {
                    }
                }
            }
        }

        if (! $hash || $mtprotoId === null) {
            throw new \RuntimeException('Telegram belum mengembalikan access_hash untuk channel bucket; coba hubungkan ulang akun.');
        }

        $meta = $account->meta ?? [];
        $meta['channel_id'] = $mtprotoId;
        $meta['channel_hash'] = (string) $hash;
        $account->forceFill(['meta' => $meta])->save();

        return ['channel_id' => $mtprotoId];
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>|null
     */
    private function findChannel(array $result): ?array
    {
        $candidates = [];

        if (isset($result['chat']) && is_array($result['chat'])) {
            $candidates[] = $result['chat'];
        }

        if (isset($result['chats']) && is_array($result['chats'])) {
            foreach ($result['chats'] as $chat) {
                if (is_array($chat)) {
                    $candidates[] = $chat;
                }
            }
        }

        // Fall back to a manual depth-first scan for a channel constructor.
        $stack = [$result];

        while ($stack) {
            $node = array_pop($stack);

            foreach ($node as $key => $value) {
                if (! is_array($value)) {
                    continue;
                }

                $type = (string) ($value['_'] ?? '');
                if (($type === 'channel' || $type === 'channelForbidden') && isset($value['id'])) {
                    $candidates[] = $value;
                } elseif ($key !== 'chats' && $key !== 'chat') {
                    $stack[] = $value;
                }
            }
        }

        // Prioritize candidate with non-empty access_hash
        foreach ($candidates as $cand) {
            $type = (string) ($cand['_'] ?? '');
            if (($type === 'channel' || $type === 'channelForbidden') && isset($cand['id'], $cand['access_hash']) && $cand['access_hash'] !== 0 && $cand['access_hash'] !== '0' && $cand['access_hash'] !== '') {
                return $cand;
            }
        }

        // Fallback to any channel constructor
        foreach ($candidates as $cand) {
            $type = (string) ($cand['_'] ?? '');
            if (($type === 'channel' || $type === 'channelForbidden') && isset($cand['id'])) {
                return $cand;
            }
        }

        return null;
    }

    private function upload(array $request): array
    {
        $accountId = (int) $request['account_id'];
        $account = StorageAccount::findOrFail($accountId);
        $mp = $this->mp($accountId);
        $peer = $this->bucketPeer($account);
        // Bot-API style peer id for channels (uploadDocument takes string|int peer).
        $peerId = Magic::ZERO_CHANNEL_ID - (int) $peer['channel_id'];

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
                // Failures here are not fatal; the session dir is removed right after.
            }

            unset($this->instances[$accountId]);
        }

        // v8 sessions are directories, not single files.
        $dir = dirname($this->sessionPath($accountId));
        $session = $this->sessionPath($accountId);

        if (is_dir($session)) {
            app(Filesystem::class)->deleteDirectory($session);
        } elseif (file_exists($session)) {
            @unlink($session);
        }
        @unlink($dir.'/'.$accountId.'.madeline.lock');

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
        $delay = (int) config('rpebs.telegram.upload_delay');
        if ($delay > 0) {
            async(fn () => \Amp\delay($delay))->await();
        }

        $message = null;

        foreach ([1, 2] as $attempt) {
            try {
                $message = async(fn () => $mp->sendDocument(
                    peer: $peerId,
                    file: new LocalFile($path),
                    fileName: $name,
                    silent: true,
                ))->await();

                break;
            } catch (RPCErrorException $e) {
                if (! str_contains($e->getMessage(), 'FLOOD_WAIT_')) {
                    throw $e;
                }

                // FLOOD_WAIT_<seconds>: back off once, then retry.
                $wait = (int) (preg_replace('/\D/', '', $e->getMessage()) ?: 15);
                async(fn () => \Amp\delay(min($wait, 120)))->await();

                if ($attempt === 2) {
                    throw $e;
                }
            } catch (CancelledException $e) {
                if ($attempt === 2) {
                    throw $e;
                }

                // Temporary connection cancellation/reconnect; back off briefly and retry.
                async(fn () => \Amp\delay(2))->await();
            }
        }

        $messageId = $this->extractMessageId($message);

        if ($messageId === null) {
            throw new \RuntimeException('Telegram tidak mengembalikan message_id setelah upload.');
        }

        return $messageId;
    }

    private function extractMessageId(mixed $result): ?int
    {
        if (is_object($result) && isset($result->id) && is_numeric($result->id)) {
            return (int) $result->id;
        }

        if (is_array($result)) {
            if (isset($result['id']) && is_numeric($result['id'])) {
                return (int) $result['id'];
            }

            if (isset($result['message_id']) && is_numeric($result['message_id'])) {
                return (int) $result['message_id'];
            }

            $found = null;
            array_walk_recursive($result, function ($value, $key) use (&$found) {
                if ($found === null && ($key === 'message_id' || $key === 'id') && is_int($value) && $value > 0) {
                    $found = $value;
                }
            });

            return $found;
        }

        return null;
    }

    private function bucketPeer(StorageAccount $account): array
    {
        $meta = $account->meta ?? [];

        if (! isset($meta['channel_id'], $meta['channel_hash'])) {
            throw new \RuntimeException('Akun Telegram belum punya channel bucket. Hubungkan ulang akun.');
        }

        return [
            '_' => 'inputPeerChannel',
            'channel_id' => DialogId::toMTProtoId((int) $meta['channel_id']),
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

        // Quiet the per-message MTProto chatter; keep warnings and errors.
        $settings->getLogger()->setLevel(Logger::WARNING);

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
