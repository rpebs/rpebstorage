<?php

namespace Tests\Feature;

use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakeDriver;
use Tests\TestCase;

class StorageAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::reset();

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($this->user);

        StorageProvider::create([
            'name' => 'fake',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);
    }

    public function test_accounts_page_renders_for_user_without_accounts(): void
    {
        $this->get('/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('accounts/index')->has('accounts', 0));
    }

    public function test_alias_can_be_changed(): void
    {
        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => StorageProvider::where('name', 'fake')->firstOrFail()->id,
            'alias' => 'Lama',
            'status' => 'active',
        ]);

        $this->patch("/accounts/{$account->id}", ['alias' => 'Gdrive Kerja'])
            ->assertRedirect();

        $this->assertSame('Gdrive Kerja', $account->fresh()->alias);
    }

    public function test_disconnect_deletes_credentials_and_flags_files_inaccessible(): void
    {
        $provider = StorageProvider::where('name', 'fake')->firstOrFail();
        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $provider->id,
            'alias' => 'Fake',
            'credentials' => ['access_token' => 'abc', 'refresh_token' => null, 'expires_at' => 0],
            'status' => 'active',
        ]);

        $file = $account->files()->create([
            'user_id' => $this->user->id,
            'name' => 'report.pdf',
            'remote_ref' => 'fake-1',
            'size' => 1024,
            'mime_type' => 'application/pdf',
        ]);

        $this->delete("/accounts/{$account->id}")
            ->assertRedirect()
            ->assertInertiaFlash('toast');

        $fresh = $account->fresh();
        $this->assertSame('disconnected', $fresh->status->value);
        $this->assertNull($fresh->credentials);
        $this->assertFalse($file->fresh()->isAccessible());
    }
}
