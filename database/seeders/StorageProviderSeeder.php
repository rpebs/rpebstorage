<?php

namespace Database\Seeders;

use App\Models\StorageProvider;
use Illuminate\Database\Seeder;

class StorageProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['name' => 'google_drive', 'driver_class' => \App\Services\Storage\Drivers\GoogleDriveDriver::class],
            ['name' => 'dropbox', 'driver_class' => \App\Services\Storage\Drivers\DropboxDriver::class],
            ['name' => 'onedrive', 'driver_class' => \App\Services\Storage\Drivers\OneDriveDriver::class],
            ['name' => 'telegram', 'driver_class' => \App\Services\Storage\Drivers\TelegramDriver::class],
        ];

        foreach ($providers as $provider) {
            StorageProvider::updateOrCreate(['name' => $provider['name']], $provider);
        }
    }
}
