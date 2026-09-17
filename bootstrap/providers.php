<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    // Boot pertama: kredensial provider dari tabel settings harus sudah masuk
    // config sebelum AppServiceProvider memutuskan menjalankan daemon Telegram.
    SettingsServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
