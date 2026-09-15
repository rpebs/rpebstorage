<?php

use App\Http\Controllers\Auth\ProviderOAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MegaAuthController;
use App\Http\Controllers\StorageAccountController;
use App\Http\Controllers\TelegramAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('files', [FileManagerController::class, 'index'])->name('files.index');
    Route::post('files/folders', [FileManagerController::class, 'storeFolder'])->name('files.folders.store');
    Route::patch('files/folders/{folder}', [FileManagerController::class, 'updateFolder'])->name('files.folders.update');
    Route::delete('files/folders/{folder}', [FileManagerController::class, 'destroyFolder'])->name('files.folders.destroy');
    Route::post('files/upload', [FileManagerController::class, 'upload'])->name('files.upload');
    Route::get('files/jobs', [FileManagerController::class, 'jobs'])->name('files.jobs');
    Route::get('files/{file}/preview', [FileManagerController::class, 'preview'])->name('files.preview');
    Route::get('files/{file}/preview-status', [FileManagerController::class, 'previewStatus'])->name('files.preview.status');
    Route::get('files/{file}/download', [FileManagerController::class, 'download'])->name('files.download');
    Route::get('files/{file}/thumbnail', [FileManagerController::class, 'thumbnail'])->name('files.thumbnail');
    Route::patch('files/{file}/move', [FileManagerController::class, 'move'])->name('files.move');
    Route::delete('files/{file}', [FileManagerController::class, 'destroy'])->name('files.destroy');

    Route::get('accounts', [StorageAccountController::class, 'index'])->name('accounts.index');
    Route::patch('accounts/{account}', [StorageAccountController::class, 'update'])->name('accounts.update');
    Route::delete('accounts/{account}', [StorageAccountController::class, 'destroy'])->name('accounts.destroy');
    Route::post('accounts/{account}/scan', [StorageAccountController::class, 'scan'])->name('accounts.scan');
    Route::get('accounts/connect/{provider}', [ProviderOAuthController::class, 'redirect'])->name('accounts.connect');
    Route::get('accounts/callback/{provider}', [ProviderOAuthController::class, 'callback'])->name('accounts.callback');

    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    Route::delete('logs', [LogController::class, 'clear'])->name('logs.clear');
    Route::delete('logs/{job}', [LogController::class, 'destroy'])->name('logs.destroy');

    Route::post('accounts/telegram/start', [TelegramAuthController::class, 'start'])->name('accounts.telegram.start');
    Route::post('accounts/telegram/verify', [TelegramAuthController::class, 'verify'])->name('accounts.telegram.verify');
    Route::post('accounts/mega/connect', [MegaAuthController::class, 'connect'])->name('accounts.mega.connect');
});

require __DIR__.'/settings.php';
