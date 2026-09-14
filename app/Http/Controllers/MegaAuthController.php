<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Services\Storage\Mega\MegaAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MegaAuthController extends Controller
{
    public function __construct(
        private MegaAuthService $authService,
    ) {}

    public function connect(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
            'alias' => ['nullable', 'string', 'max:100'],
        ]);

        $provider = StorageProvider::where('name', 'mega')->firstOrFail();

        try {
            $authData = $this->authService->login(
                $validated['email'],
                $validated['password'],
                $validated['two_factor_code'] ?? null
            );

            $session = $authData['session'];
            $alias = ! empty($validated['alias']) ? $validated['alias'] : 'MEGA ('.$validated['email'].')';

            $account = StorageAccount::create([
                'user_id' => $request->user()->id,
                'storage_provider_id' => $provider->id,
                'alias' => $alias,
                'credentials' => [
                    'session_id' => $session->getSessionId(),
                    'master_key' => $session->getMasterKey(),
                    'private_key' => $session->getPrivateKey(),
                    'root_handle' => $authData['root_handle'],
                    'email' => $validated['email'],
                ],
                'status' => AccountStatus::Active,
                'quota_total' => $authData['quota_total'],
                'quota_used' => $authData['quota_used'],
                'quota_synced_at' => now(),
            ]);

            return $this->toastRoute('accounts.index', [
                'type' => 'success',
                'message' => "Akun {$account->alias} berhasil terhubung.",
            ]);
        } catch (Throwable $e) {
            Log::error('MEGA login error: '.$e->getMessage(), [
                'class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'Gagal menghubungkan akun MEGA: '.$e->getMessage(),
            ]);
        }
    }
}
