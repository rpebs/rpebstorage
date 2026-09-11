<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Single-user app: registration is disabled, the owner account is
        // created here. Set ADMIN_EMAIL / ADMIN_PASSWORD in .env.
        if (User::count() > 0) {
            return;
        }

        User::create([
            'name' => env('ADMIN_NAME', 'Owner'),
            'email' => env('ADMIN_EMAIL', 'admin@rpebstorage.local'),
            'password' => bcrypt(env('ADMIN_PASSWORD', 'change-me-now')),
        ]);

        $this->call(StorageProviderSeeder::class);
    }
}
