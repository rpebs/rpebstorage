<?php

namespace Tests\Feature;

use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Storage\Drivers\MegaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MegaAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($this->user);

        StorageProvider::create([
            'name' => 'mega',
            'driver_class' => MegaDriver::class,
            'is_active' => true,
        ]);
    }

    public function test_mega_is_marked_as_connectable_in_accounts_page(): void
    {
        $this->get('/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounts/index')
                ->where('providers.0.name', 'mega')
                ->where('providers.0.connectable', true)
                ->where('providers.0.credentials_missing', false)
            );
    }

    public function test_connect_requires_email_and_password(): void
    {
        $this->post('/accounts/mega/connect', [])
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_connect_handles_invalid_credentials_gracefully(): void
    {
        $this->post('/accounts/mega/connect', [
            'email' => 'invalid@example.com',
            'password' => 'wrongpassword',
        ])->assertSessionHasErrors(['email']);
    }
}
