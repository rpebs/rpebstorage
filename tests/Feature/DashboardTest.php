<?php

namespace Tests\Feature;

use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakeDriver;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('total')
                ->has('providers')
                ->has('accounts')
                ->has('recent_files')
            );
    }

    public function test_dashboard_provides_storage_breakdown_and_recent_files()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $provider = StorageProvider::create([
            'name' => 'google_drive',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);

        $account = $user->storageAccounts()->create([
            'storage_provider_id' => $provider->id,
            'alias' => 'Drive Utama',
            'quota_total' => 15 * 1024 * 1024 * 1024,
            'quota_used' => 5 * 1024 * 1024 * 1024,
            'status' => 'active',
        ]);

        $file = $user->virtualFiles()->create([
            'storage_account_id' => $account->id,
            'name' => 'laporan-keuangan.pdf',
            'remote_ref' => 'ref-123',
            'size' => 1024 * 1024,
            'mime_type' => 'application/pdf',
            'is_chunked' => false,
        ]);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('total.account_count', 1)
                ->where('total.file_count', 1)
                ->where('total.quota', 15 * 1024 * 1024 * 1024)
                ->where('total.used', 5 * 1024 * 1024 * 1024)
                ->where('total.free', 10 * 1024 * 1024 * 1024)
                ->has('providers', 1)
                ->has('recent_files', 1)
                ->where('recent_files.0.name', 'laporan-keuangan.pdf')
            );
    }
}
