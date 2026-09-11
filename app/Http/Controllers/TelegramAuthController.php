<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Services\Telegram\TelegramRpc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramAuthController extends Controller
{
    public function __construct(private TelegramRpc $rpc) {}

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
        ]);

        $phone = '+'.ltrim($validated['phone'], '+');
        $provider = StorageProvider::where('name', 'telegram')->firstOrFail();

        $account = StorageAccount::create([
            'user_id' => $request->user()->id,
            'storage_provider_id' => $provider->id,
            'alias' => 'Telegram',
            'status' => AccountStatus::Expired,
            'meta' => ['connecting' => true],
        ]);

        try {
            $this->rpc->call('connect.request_code', [
                'account_id' => $account->id,
                'phone' => $phone,
            ], timeout: 120);
        } catch (\Throwable $e) {
            $account->delete();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->put('telegram_connect_account', $account->id);

        return response()->json(['status' => 'code_sent', 'account_id' => $account->id]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'max:200'],
        ]);

        $accountId = $request->session()->get('telegram_connect_account');

        if (! $accountId) {
            return response()->json(['message' => 'Sesi koneksi Telegram habis. Mulai lagi dari awal.'], 422);
        }

        $account = StorageAccount::where('user_id', $request->user()->id)->findOrFail($accountId);

        try {
            $result = $this->rpc->call('connect.complete', [
                'account_id' => $account->id,
                'code' => $validated['code'],
                'password' => $validated['password'] ?? null,
            ], timeout: 120);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (($result['status'] ?? '') === 'password_needed') {
            return response()->json(['status' => 'password_needed']);
        }

        try {
            $this->rpc->call('connect.create_bucket', ['account_id' => $account->id], timeout: 300);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Login Telegram berhasil, tapi gagal membuat channel bucket: '.$e->getMessage()], 422);
        }

        $account->forceFill([
            'alias' => 'Telegram ('.($result['name'] ?: $result['phone']).')',
            'status' => AccountStatus::Active,
            'meta' => ['connecting' => false],
            'quota_synced_at' => now(),
        ])->save();

        $request->session()->forget('telegram_connect_account');

        return response()->json(['status' => 'authorized']);
    }
}
