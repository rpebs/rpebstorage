<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class TelegramRpcException extends RuntimeException {}

/**
 * Client side of the telegram:rpc Redis queue. The telegram:listen daemon
 * is the single owner of every MadelineProto session; queue jobs and web
 * requests talk to it exclusively through this client.
 */
class TelegramRpc
{
    public const QUEUE = 'telegram:rpc';
    private const REPLY_TTL = 300;
    private const POLL_INTERVAL = 2;

    public static function daemonConfigured(): bool
    {
        return (bool) config('rpebs.telegram.api_id') && (bool) config('rpebs.telegram.api_hash');
    }

    /**
     * Send a request to the daemon and wait for the reply.
     *
     * @return array<string, mixed>
     *
     * @throws TelegramRpcException
     */
    public function call(string $action, array $payload = [], int $timeout = 900): array
    {
        if (! self::daemonConfigured()) {
            throw new TelegramRpcException('TELEGRAM_API_ID / TELEGRAM_API_HASH belum diisi di .env');
        }

        $id = uniqid('rpc_', true);

        Redis::lpush(self::QUEUE, json_encode([
            'id' => $id,
            'action' => $action,
            ...$payload,
        ]));

        $deadline = microtime(true) + $timeout;
        $key = "telegram:rpc:reply:{$id}";

        do {
            $reply = Redis::get($key);

            if ($reply !== null) {
                Redis::del($key);

                $decoded = json_decode($reply, true);

                if (($decoded['ok'] ?? false) !== true) {
                    throw new TelegramRpcException($decoded['error'] ?? 'Kesalahan daemon Telegram tidak diketahui');
                }

                return $decoded['data'] ?? [];
            }

            if (microtime(true) >= $deadline) {
                throw new TelegramRpcException(
                    "Timeout menunggu daemon Telegram (telegram:listen berjalan?) [action={$action}]"
                );
            }

            sleep(self::POLL_INTERVAL);
        } while (true);
    }
}
