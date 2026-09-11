<?php

use App\Http\Controllers\Auth\ProviderOAuthController;
use App\Http\Controllers\StorageAccountController;
use App\Http\Controllers\TelegramAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('accounts', [StorageAccountController::class, 'index'])->name('accounts.index');
    Route::patch('accounts/{account}', [StorageAccountController::class, 'update'])->name('accounts.update');
    Route::delete('accounts/{account}', [StorageAccountController::class, 'destroy'])->name('accounts.destroy');
    Route::get('accounts/connect/{provider}', [ProviderOAuthController::class, 'redirect'])->name('accounts.connect');
    Route::get('accounts/callback/{provider}', [ProviderOAuthController::class, 'callback'])->name('accounts.callback');

    Route::post('accounts/telegram/start', [TelegramAuthController::class, 'start'])->name('accounts.telegram.start');
    Route::post('accounts/telegram/verify', [TelegramAuthController::class, 'verify'])->name('accounts.telegram.verify');
});

require __DIR__.'/settings.php';
