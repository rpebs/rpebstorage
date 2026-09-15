<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RestoreController extends Controller
{
    public function __construct(
        private BackupService $backupService
    ) {}

    public function index(): Response
    {
        return Inertia::render('auth/RestoreBackup');
    }

    public function restore(Request $request): RedirectResponse
    {
        $request->validate([
            'backup_file' => ['required', 'file', 'max:204800'],
            'password' => ['required', 'string'],
        ]);

        $file = $request->file('backup_file');
        $password = $request->input('password');

        if (! $file || ! $file->isValid() || $file->getSize() === 0) {
            return back()->withErrors(['backup_file' => 'Berkas backup yang diunggah tidak valid atau kosong (0 bytes).']);
        }

        $tempPath = $file->getRealPath();

        try {
            $this->backupService->restoreBackup($tempPath, $password);

            return redirect()->route('login')->with(
                'status',
                'Sistem berhasil dipulihkan dari backup. Silakan login menggunakan akun Anda.'
            );
        } catch (BackupException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withErrors(['backup_file' => 'Terjadi kesalahan saat memulihkan: '.$e->getMessage()]);
        }
    }
}
