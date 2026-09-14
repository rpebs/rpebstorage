<?php

namespace Tests\Feature;

use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakeDriver;
use Tests\TestCase;

class StorageManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private StorageProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);

        $this->provider = StorageProvider::create([
            'name' => 'fake',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);
    }

    private function makeAccount(string $alias, ?int $total, int $used, string $status = 'active'): StorageAccount
    {
        return StorageAccount::create([
            'user_id' => $this->user->id,
            'storage_provider_id' => $this->provider->id,
            'alias' => $alias,
            'quota_total' => $total,
            'quota_used' => $used,
            'status' => $status,
        ]);
    }

    public function test_picks_limited_account_with_most_remaining_space(): void
    {
        $small = $this->makeAccount('small', 10 * 1024 ** 3, 8 * 1024 ** 3); // 2 GB free
        $big = $this->makeAccount('big', 10 * 1024 ** 3, 5 * 1024 ** 3); // 5 GB free

        $picked = app(StorageManager::class)->pickBestAccount($this->user);

        $this->assertSame($big->id, $picked->id);
        $this->assertNotSame($small->id, $picked->id);
    }

    public function test_unlimited_account_only_fallback_when_limited_accounts_are_full(): void
    {
        config(['rpebs.unlimited_fallback_threshold' => 1024 ** 3]);

        $this->makeAccount('unlimited', null, 0);
        $full = $this->makeAccount('cloud', 1024 ** 3, 900 * 1024 ** 2); // 124 MB free < 1 GB

        $picked = app(StorageManager::class)->pickBestAccount($this->user);

        $this->assertNotSame($full->id, $picked->id);
        $this->assertTrue($picked->isUnlimited());
    }

    public function test_limited_account_wins_while_it_has_room(): void
    {
        config(['rpebs.unlimited_fallback_threshold' => 1024 ** 3]);

        $this->makeAccount('unlimited', null, 0);
        $roomy = $this->makeAccount('cloud', 10 * 1024 ** 3, 1 * 1024 ** 3);

        $picked = app(StorageManager::class)->pickBestAccount($this->user);

        $this->assertSame($roomy->id, $picked->id);
    }

    public function test_manual_pick_wins_and_must_be_active(): void
    {
        $a = $this->makeAccount('a', 10 * 1024 ** 3, 0);
        $b = $this->makeAccount('b', 20 * 1024 ** 3, 0);

        $manager = app(StorageManager::class);

        $this->assertSame($a->id, $manager->pickBestAccount($this->user, $a->id)->id);

        $b->update(['status' => 'disconnected']);
        $this->expectException(\RuntimeException::class);
        $manager->pickBestAccount($this->user, $b->id);
    }

    public function test_throws_when_no_active_accounts(): void
    {
        $this->expectException(\RuntimeException::class);
        app(StorageManager::class)->pickBestAccount($this->user);
    }
}
