<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\JobStatus;
use App\Jobs\DownloadFileJob;
use App\Jobs\UploadFileJob;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use App\Services\Storage\StorageManager;
use App\Services\Storage\ThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileManagerController extends Controller
{
    public function __construct(
        private StorageManager $manager,
        private ThumbnailService $thumbnailService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $folderId = $request->input('folder');
        $search = trim((string) $request->input('q', ''));

        $folder = null;

        if ($folderId) {
            $folder = VirtualFolder::where('user_id', $user->id)->findOrFail($folderId);
        }

        $breadcrumb = [];
        if ($folder) {
            $chain = [];
            $walker = $folder;
            while ($walker !== null) {
                $chain[] = ['id' => $walker->id, 'name' => $walker->name];
                $walker = $walker->parent;
            }
            $breadcrumb = array_reverse($chain);
        }

        if ($search !== '') {
            $files = VirtualFile::where('user_id', $user->id)
                ->where('name', 'like', "%{$search}%")
                ->with('account.provider')
                ->latest()
                ->limit(50)
                ->get();
            $folders = collect();
        } else {
            $folders = VirtualFolder::where('user_id', $user->id)
                ->where('parent_id', $folder?->id)
                ->orderBy('name')
                ->get();
            $files = VirtualFile::where('user_id', $user->id)
                ->where('virtual_folder_id', $folder?->id)
                ->with('account.provider')
                ->orderBy('name')
                ->get();
        }

        $allFolders = VirtualFolder::where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'parent_id']);

        $accounts = $user->storageAccounts()
            ->where('status', AccountStatus::Active)
            ->with('provider')
            ->get()
            ->map(fn (StorageAccount $account) => [
                'id' => $account->id,
                'label' => "{$account->alias} ({$account->provider->label()})",
                'unlimited' => $account->isUnlimited(),
            ]);

        return Inertia::render('files/index', [
            'folder' => $folder?->only(['id', 'name', 'parent_id']),
            'breadcrumb' => $breadcrumb,
            'folders' => $folders->map(fn (VirtualFolder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'parent_id' => $f->parent_id,
                'updated_at' => $f->updated_at?->toISOString(),
            ]),
            'files' => $files->map(fn (VirtualFile $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'size' => $f->size,
                'mime_type' => $f->mime_type,
                'is_chunked' => $f->is_chunked,
                'account_label' => $f->account ? "{$f->account->alias} ({$f->account->provider->label()})" : '',
                'accessible' => $f->account?->status === AccountStatus::Active,
                'updated_at' => $f->updated_at?->toISOString(),
                'has_thumbnail' => $this->thumbnailService->supports($f),
                'thumbnail_url' => $this->thumbnailService->supports($f) ? route('files.thumbnail', $f) : null,
            ]),
            'allFolders' => $allFolders->values(),
            'accounts' => $accounts,
            'search' => $search,
        ]);
    }

    public function storeFolder(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parentId = $validated['parent_id'] ?? null;

        if ($parentId !== null) {
            VirtualFolder::where('user_id', $request->user()->id)->findOrFail($parentId);
        }

        $duplicate = VirtualFolder::where('user_id', $request->user()->id)
            ->where('parent_id', $parentId)
            ->where('name', $validated['name'])
            ->exists();

        if ($duplicate) {
            return $this->toast([
                'type' => 'error',
                'message' => "Folder \"{$validated['name']}\" sudah ada di lokasi ini.",
            ]);
        }

        VirtualFolder::create([
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'name' => $validated['name'],
        ]);

        return back();
    }

    public function updateFolder(Request $request, VirtualFolder $folder)
    {
        abort_unless($folder->user_id === $request->user()->id, 404);

        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $folder->update(['name' => $validated['name']]);

        return back();
    }

    public function destroyFolder(Request $request, VirtualFolder $folder)
    {
        abort_unless($folder->user_id === $request->user()->id, 404);

        $empty = $folder->children()->doesntExist() && $folder->files()->doesntExist();

        if (! $empty) {
            return $this->toast([
                'type' => 'error',
                'message' => 'Folder tidak kosong. Kosongkan dulu sebelum dihapus.',
            ]);
        }

        $folder->delete();

        return back();
    }

    public function upload(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->maxUploadKb()],
            'folder_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $uploaded = $request->file('file');

        $folderId = $validated['folder_id'] ?? null;

        if ($folderId !== null) {
            VirtualFolder::where('user_id', $user->id)->findOrFail($folderId);
        }

        if ($user->storageAccounts()->where('status', AccountStatus::Active)->doesntExist()) {
            return $this->toast([
                'type' => 'error',
                'message' => 'Belum ada akun aktif. Hubungkan akun storage dulu.',
            ]);
        }

        $this->manager->ensureTempDir();
        $tempPath = $this->manager->tempPath(Str::uuid()->toString());

        $uploaded->move(dirname($tempPath), basename($tempPath));

        $row = FileJob::create([
            'user_id' => $user->id,
            'type' => 'upload',
            'virtual_folder_id' => $folderId,
            'original_name' => $uploaded->getClientOriginalName(),
            'size' => filesize($tempPath),
            'mime_type' => $uploaded->getClientMimeType(),
            'status' => JobStatus::Pending,
        ]);

        UploadFileJob::dispatch($row->id, $tempPath, $validated['account_id'] ?? null);

        return $this->toast([
            'type' => 'success',
            'message' => "{$row->original_name} masuk antrean unggah.",
        ]);
    }

    /**
     * Polling endpoint for the upload progress panel.
     */
    public function jobs(Request $request)
    {
        $jobs = FileJob::where('user_id', $request->user()->id)
            ->whereIn('status', [JobStatus::Pending->value, JobStatus::Processing->value])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (FileJob $job) => [
                'id' => $job->id,
                'name' => $job->original_name,
                'size' => $job->size,
                'status' => $job->status->value,
                'progress' => $job->progress,
                'error' => $job->error,
            ]);

        return response()->json(['jobs' => $jobs]);
    }

    public function download(Request $request, VirtualFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $account = $file->account;

        if (! $account || $account->status !== AccountStatus::Active) {
            return $this->toast([
                'type' => 'error',
                'message' => 'File ini tersimpan di akun yang sudah diputuskan, tidak bisa diunduh.',
            ]);
        }

        $driver = $this->manager->driver($account);

        // Chunked files (Telegram) must be merged first; that happens in a
        // queued job and the merged result is cached until the file changes.
        if ($file->is_chunked) {
            $merged = DownloadFileJob::mergedPath($this->manager, $file);

            if (! file_exists($merged)) {
                DownloadFileJob::dispatch($file->id);

                return $this->toast([
                    'type' => 'info',
                    'message' => 'File besar sedang disiapkan. Klik unduh lagi dalam beberapa saat.',
                ]);
            }

            return new BinaryFileResponse($merged, 200, [], false, 'attachment', false, $file->name);
        }

        $temp = $driver->download($account, $file->remote_ref);

        return response()->download($temp, $file->name)->deleteFileAfterSend(true);
    }

    public function thumbnail(Request $request, VirtualFile $file): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        if (! $this->thumbnailService->supports($file)) {
            abort(404);
        }

        $path = $this->thumbnailService->getOrGenerateThumbnail($file);

        if (! $path || ! file_exists($path)) {
            abort(404);
        }

        $lastModified = filemtime($path) ?: time();
        $etag = '"' . md5($file->id . '-' . $lastModified) . '"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=604800, immutable',
            ]);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };

        $response = response()->file($path, [
            'Content-Type' => $contentType,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);
        $response->setPrivate();
        $response->setMaxAge(604800);

        return $response;
    }

    public function move(Request $request, VirtualFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'folder_id' => ['nullable', 'integer'],
        ]);

        $folderId = $validated['folder_id'] ?? null;

        if ($folderId !== null) {
            VirtualFolder::where('user_id', $request->user()->id)->findOrFail($folderId);
        }

        // FR-14: only the pointer moves, the remote copy stays put.
        $file->update(['virtual_folder_id' => $folderId]);

        return back();
    }

    public function destroy(Request $request, VirtualFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $account = $file->account;

        if (! $account || $account->status !== AccountStatus::Active) {
            return $this->toast([
                'type' => 'error',
                'message' => 'File tersimpan di akun yang sudah diputuskan, tidak bisa dihapus dari provider.',
            ]);
        }

        $merged = DownloadFileJob::mergedPath($this->manager, $file);
        if (file_exists($merged)) {
            @unlink($merged);
        }

        $this->thumbnailService->deleteThumbnail($file);

        $this->manager->driver($account)->delete($account, $file->remote_ref);

        $file->delete();

        return $this->toast([
            'type' => 'success',
            'message' => "{$file->name} dihapus.",
        ]);
    }

    private function maxUploadKb(): int
    {
        $shorthand = trim(ini_get('upload_max_filesize') ?: '512M');
        $value = (float) $shorthand;

        $bytes = match (strtoupper(substr($shorthand, -1))) {
            'G' => $value * 1024 ** 3,
            'M' => $value * 1024 ** 2,
            'K' => $value * 1024,
            default => $value,
        };

        return max(1, (int) ($bytes / 1024));
    }
}
