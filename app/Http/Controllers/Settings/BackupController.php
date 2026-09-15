<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

class BackupController extends Controller
{
    public function __construct(
        private BackupService $backupService
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Backup', [
            'stats' => $this->backupService->getSystemStats(),
            'restoreSummary' => $request->session()->get('restore_summary'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $filePath = $this->backupService->createBackup($validated['password']);
            $filename = 'rpebstorage-backup-'.date('Y-m-d-His').'.zip';

            return response()->download($filePath, $filename);
        } catch (BackupException $e) {
            return $this->toast([
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
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
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Berkas backup yang diunggah tidak valid atau kosong (0 bytes).',
            ]);

            return back()->withErrors([
                'backup_file' => 'Berkas backup yang diunggah tidak valid atau kosong (0 bytes).',
            ]);
        }

        $tempPath = $file->getRealPath();

        try {
            Log::info('BackupController: Memulai restore dari file unggahan.', [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            $summary = $this->backupService->restoreBackup($tempPath, $password);

            Log::info('BackupController: Restore berhasil diselesaikan.', [
                'restored_table_counts' => $summary['restored_table_counts'] ?? [],
                'restored_sessions_count' => $summary['restored_sessions_count'] ?? 0,
            ]);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Sistem berhasil dipulihkan dari arsip backup.',
            ]);

            return back()->with('restore_summary', [
                'success' => true,
                'manifest' => $summary['manifest'] ?? [],
                'restored_table_counts' => $summary['restored_table_counts'] ?? [],
                'restored_sessions_count' => $summary['restored_sessions_count'] ?? 0,
                'restored_env_keys' => $summary['restored_env_keys'] ?? [],
            ])->with('status', 'Sistem berhasil dipulihkan dari arsip backup.');
        } catch (BackupException $e) {
            Log::warning('BackupController: Restore gagal (BackupException): '.$e->getMessage());

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'password' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BackupController: Restore gagal (\Throwable): '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Terjadi kesalahan saat memulihkan sistem: '.$e->getMessage(),
            ]);

            return back()->withErrors([
                'backup_file' => 'Terjadi kesalahan saat memulihkan sistem: '.$e->getMessage(),
            ]);
        }
    }
}
