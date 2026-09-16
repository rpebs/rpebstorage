<?php

namespace App\Services\Storage\Mega;

use App\Services\Storage\Drivers\MegaDriver;
use Http\Discovery\Psr17FactoryDiscovery;
use Illuminate\Support\Facades\Log;
use Mega\Config;
use Mega\Crypto\Aes;
use Mega\Crypto\Base64Url;
use Mega\Entity\Session;
use Mega\Exception\ApiException;
use Mega\Service\SessionAuthenticator;
use Mega\Transport\Connector;
use Psr\Log\NullLogger;
use RuntimeException;

class MegaAuthService
{
    public function createConnector(): Connector
    {
        return new Connector(
            Config::SERVER_GLOBAL,
            MegaDriver::resolveHttpClient(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            new NullLogger
        );
    }

    /**
     * Authenticate a MEGA user supporting both v1 (legacy) and v2 (PBKDF2) accounts,
     * plus optional 2FA / MFA.
     *
     * @return array{session: Session, root_handle: ?string, quota_total: ?int, quota_used: int}
     */
    public function login(string $email, string $password, ?string $twoFactorCode = null): array
    {
        $email = strtolower(trim($email));
        $connector = $this->createConnector();

        // 1. Cek versi akun MEGA (us0)
        $version = 1;
        $salt = null;

        try {
            $versionResponse = $connector->send([
                'a' => 'us0',
                'user' => $email,
            ]);

            if (is_array($versionResponse)) {
                $version = (int) ($versionResponse['v'] ?? 1);
                $salt = $versionResponse['s'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::info('MEGA us0 check returned error, falling back to v1', ['error' => $e->getMessage()]);
            $version = 1;
        }

        // 2. Turunkan kunci (derive key) sesuai versi akun
        if ($version === 2 && ! empty($salt)) {
            $decodedSalt = Base64Url::decode($salt);
            // PBKDF2: SHA-512, 100.000 iterasi, 32 bytes
            $derived = hash_pbkdf2('sha512', $password, $decodedSalt, 100000, 32, true);
            $passwordKey = substr($derived, 0, 16);
            $userHash = Base64Url::encode(substr($derived, 16, 16));
        } else {
            $passwordKey = Aes::deriveKeyFromPassword($password);
            $userHash = Aes::userHash($email, $passwordKey);
        }

        // 3. Kirim perintah login ('us')
        $payload = [
            'a' => 'us',
            'user' => $email,
            'uh' => $userHash,
        ];

        if (! empty($twoFactorCode)) {
            $payload['mfa'] = trim($twoFactorCode);
        }

        try {
            $loginResponse = $connector->send($payload);
        } catch (ApiException $e) {
            if ($e->getCode() === -26) {
                throw new RuntimeException('Akun MEGA ini mengaktifkan autentikasi 2-faktor (2FA). Silakan masukkan kode 2FA.');
            }
            if ($e->getCode() === -9) {
                throw new RuntimeException('Email atau kata sandi akun MEGA tidak cocok.');
            }
            throw new RuntimeException("Gagal login ke MEGA: {$e->getMessage()} (Kode: {$e->getCode()})", $e->getCode(), $e);
        }

        if (! is_array($loginResponse)) {
            throw new RuntimeException('Respons login dari MEGA tidak valid.');
        }

        // 4. Bangun sesi terotentikasi
        $sessionAuth = new SessionAuthenticator;
        $session = $sessionAuth->buildSessionFromLoginResponse($loginResponse, $passwordKey);

        $connector->setSessionId($session->getSessionId());

        // 5. Ambil root directory (Cloud Drive: t = 2)
        $rootHandle = null;
        try {
            $tree = $connector->send(['a' => 'f', 'c' => 1]);
            foreach ($tree['f'] ?? [] as $node) {
                if (($node['t'] ?? -1) === 2) {
                    $rootHandle = (string) ($node['h'] ?? '');
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal membaca direktori pohon MEGA saat login', ['error' => $e->getMessage()]);
        }

        // 6. Ambil kuota penyimpanan awal
        $quotaTotal = null;
        $quotaUsed = 0;
        try {
            $quota = $connector->send(['a' => 'uq', 'strg' => 1]);
            $quotaTotal = isset($quota['mstrg']) ? (int) $quota['mstrg'] : null;
            $quotaUsed = isset($quota['cstrg']) ? (int) $quota['cstrg'] : 0;
        } catch (\Throwable $e) {
            Log::warning('Gagal membaca kuota MEGA saat login', ['error' => $e->getMessage()]);
        }

        return [
            'session' => $session,
            'root_handle' => $rootHandle,
            'quota_total' => $quotaTotal,
            'quota_used' => $quotaUsed,
        ];
    }
}
