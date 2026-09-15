<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('password.edit'));

        $response->assertOk();
    }

    public function test_password_page_redirects_unauthenticated_user(): void
    {
        $response = $this->get(route('password.edit'));

        $response->assertRedirect(route('login'));
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('password.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'old-password',
                'password' => 'new-password-1234',
                'password_confirmation' => 'new-password-1234',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('password.edit'));

        $this->assertTrue(Hash::check('new-password-1234', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('password.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-current-password',
                'password' => 'new-password-1234',
                'password_confirmation' => 'new-password-1234',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('password.edit'));

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_password_must_be_confirmed(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('password.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'old-password',
                'password' => 'new-password-1234',
                'password_confirmation' => 'mismatched-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('password.edit'));

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_current_password_and_new_password_are_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('password.edit'))
            ->put(route('user-password.update'), [
                'current_password' => '',
                'password' => '',
                'password_confirmation' => '',
            ]);

        $response
            ->assertSessionHasErrors(['current_password', 'password'])
            ->assertRedirect(route('password.edit'));
    }
}
