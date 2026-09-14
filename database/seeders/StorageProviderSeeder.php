<?php

namespace Database\Seeders;

use App\Models\StorageProvider;
use App\Services\Storage\Drivers\DropboxDriver;
use App\Services\Storage\Drivers\GoogleDriveDriver;
use App\Services\Storage\Drivers\OneDriveDriver;
use App\Services\Storage\Drivers\TelegramDriver;
use Illuminate\Database\Seeder;

class StorageProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['name' => 'google_drive', 'driver_class' => GoogleDriveDriver::class],
            ['name' => 'dropbox', 'driver_class' => DropboxDriver::class],
            ['name' => 'onedrive', 'driver_class' => OneDriveDriver::class],
            ['name' => 'telegram', 'driver_class' => TelegramDriver::class],
        ];

        foreach ($providers as $provider) {
            StorageProvider::updateOrCreate(['name' => $provider['name']], $provider);
        }
    }
}
