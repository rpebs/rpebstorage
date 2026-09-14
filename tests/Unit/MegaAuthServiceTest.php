<?php

namespace Tests\Unit;

use Mega\Crypto\Base64Url;
use PHPUnit\Framework\TestCase;

class MegaAuthServiceTest extends TestCase
{
    public function test_v2_key_derivation_produces_expected_lengths(): void
    {
        $password = 'secret_password_123';
        $saltB64 = Base64Url::encode('test_salt_123456');

        $decodedSalt = Base64Url::decode($saltB64);
        $derived = hash_pbkdf2('sha512', $password, $decodedSalt, 1000, 32, true);

        $passwordKey = substr($derived, 0, 16);
        $userHash = Base64Url::encode(substr($derived, 16, 16));

        $this->assertSame(16, strlen($passwordKey));
        $this->assertNotEmpty($userHash);
    }
}
