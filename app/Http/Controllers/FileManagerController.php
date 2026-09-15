<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\JobStatus;
use App\Jobs\CreateZipArchiveJob;
use App\Jobs\DownloadFileJob;
use App\Jobs\UploadFileJob;
use App\Models\FileJob;
use App\Models\Label;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use App\Services\Storage\PreviewService;
use App\Services\Storage\StorageManager;
use App\Services\Storage\ThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileManagerController extends Controller
{
    public function __construct(
        private StorageManager $manager,
        private ThumbnailService $thumbnailService,
        private PreviewService $previewService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $folderId = $request->input('folder');
        $search = trim((string) $request->input('q', ''));
        $filter = $request->input('filter');
        $labelId = $request->input('label');

        $currentLabel = null;
        if ($labelId) {
            $currentLabel = Label::where('user_id', $user->id)->find($labelId);
        }

        $folder = null;
        $breadcrumb = [];

        if ($filter === 'starred') {
            $breadcrumb = [
                ['id' => 'starred', 'name' => 'Favorit'],
            ];

            $folders = VirtualFolder::where('user_id', $user->id)
                ->where('is_starred', true)
                ->with(['labels', 'parent'])
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->get();

            $files = VirtualFile::where('user_id', $user->id)
                ->where('is_starred', true)
                ->with(['account.provider', 'labels', 'folder'])
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->get();
        } elseif ($currentLabel) {
            $breadcrumb = [
                ['id' => 'label-'.$currentLabel->id, 'name' => "Label: {$currentLabel->name}"],
            ];

            $folders = VirtualFolder::where('user_id', $user->id)
                ->whereHas('labels', fn ($q) => $q->where('labels.id', $currentLabel->id))
                ->with(['labels', 'parent'])
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->get();

            $files = VirtualFile::where('user_id', $user->id)
                ->whereHas('labels', fn ($q) => $q->where('labels.id', $currentLabel->id))
                ->with(['account.provider', 'labels', 'folder'])
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->get();
        } elseif ($search !== '') {
            $files = VirtualFile::where('user_id', $user->id)
                ->where('name', 'like', "%{$search}%")
                ->with(['account.provider', 'labels', 'folder'])
                ->latest()
                ->limit(50)
                ->get();
            $folders = VirtualFolder::where('user_id', $user->id)
                ->where('name', 'like', "%{$search}%")
                ->with(['labels', 'parent'])
                ->limit(20)
                ->get();
        } else {
            if ($folderId) {
                $folder = VirtualFolder::where('user_id', $user->id)->findOrFail($folderId);
            }

            if ($folder) {
                $chain = [];
                $walker = $folder;
                while ($walker !== null) {
                    $chain[] = ['id' => $walker->id, 'name' => $walker->name];
                    $walker = $walker->parent;
                }
                $breadcrumb = array_reverse($chain);
            }

            $folders = VirtualFolder::where('user_id', $user->id)
                ->where('parent_id', $folder?->id)
                ->with('labels')
                ->orderBy('name')
                ->get();
            $files = VirtualFile::where('user_id', $user->id)
                ->where('virtual_folder_id', $folder?->id)
                ->with(['account.provider', 'labels'])
                ->orderBy('name')
                ->get();
        }

        $allFolders = VirtualFolder::where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'parent_id']);

        $allLabels = Label::where('user_id', $user->id)
            ->withCount(['files', 'folders'])
            ->orderBy('name')
            ->get()
            ->map(fn (Label $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'color' => $l->color,
                'files_count' => $l->files_count,
                'folders_count' => $l->folders_count,
            ]);

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
                'parent_name' => $f->parent?->name,
                'is_starred' => (bool) $f->is_starred,
                'labels' => $f->labels->map(fn (Label $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'color' => $l->color,
                ])->values(),
                'updated_at' => $f->updated_at?->toISOString(),
            ]),
            'files' => $files->map(fn (VirtualFile $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'size' => $f->size,
                'mime_type' => $f->mime_type,
                'is_chunked' => $f->is_chunked,
                'is_starred' => (bool) $f->is_starred,
                'virtual_folder_id' => $f->virtual_folder_id,
                'folder_name' => $f->folder?->name,
                'labels' => $f->labels->map(fn (Label $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'color' => $l->color,
                ])->values(),
                'account_label' => $f->account ? "{$f->account->alias} ({$f->account->provider->label()})" : '',
                'accessible' => $f->account?->status === AccountStatus::Active,
                'updated_at' => $f->updated_at?->toISOString(),
                'has_thumbnail' => $this->thumbnailService->supports($f),
                'thumbnail_url' => $this->thumbnailService->supports($f) ? route('files.thumbnail', $f) : null,
                'is_previewable' => $this->previewService->supports($f),
                'preview_type' => $this->previewService->previewType($f),
                'preview_url' => route('files.preview', $f),
            ]),
            'allFolders' => $allFolders->values(),
            'allLabels' => $allLabels->values(),
            'currentFilter' => $filter === 'starred' ? 'starred' : ($currentLabel ? 'label' : 'all'),
            'currentLabel' => $currentLabel ? [
                'id' => $currentLabel->id,
                'name' => $currentLabel->name,
                'color' => $currentLabel->color,
            ] : null,
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

    public function previewStatus(Request $request, VirtualFile $file)
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $supported = $this->previewService->supports($file);
        $type = $this->previewService->previewType($file);
        $ready = $this->previewService->isReady($file);

        if (! $ready && $file->is_chunked && $file->isAccessible()) {
            DownloadFileJob::dispatch($file->id);
        }

        return response()->json([
            'supported' => $supported,
            'type' => $type,
            'ready' => $ready,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'name' => $file->name,
            'preview_url' => route('files.preview', $file),
        ]);
    }

    public function preview(Request $request, VirtualFile $file): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        if (! $file->isAccessible()) {
            abort(403, 'File tersimpan di akun yang sudah diputuskan.');
        }

        if (! $this->previewService->supports($file)) {
            abort(415, 'Tipe berkas tidak mendukung pratinjau langsung.');
        }

        $result = $this->previewService->prepare($file);

        if ($result === 'pending') {
            return response()->json([
                'status' => 'pending',
                'message' => 'Berkas sedang disiapkan dari cloud storage.',
            ], 202);
        }

        if ($result === false || ! file_exists($result)) {
            abort(500, 'Gagal menyiapkan berkas untuk pratinjau.');
        }

        $contentType = $this->previewService->resolveMimeType($file, $result);
        $lastModified = filemtime($result) ?: time();
        $etag = '"' . md5($file->id . '-' . $lastModified . '-' . $file->size) . '"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        $safeFilename = str_replace('"', '', $file->name);

        $response = response()->file($result, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="' . $safeFilename . '"',
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);

        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
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
        $this->previewService->deleteCache($file);

        $this->manager->driver($account)->delete($account, $file->remote_ref);

        $file->delete();

        return $this->toast([
            'type' => 'success',
            'message' => "{$file->name} dihapus.",
        ]);
    }

    public function bulkMove(Request $request)
    {
        $validated = $request->validate([
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['required', 'integer'],
            'folder_id' => ['nullable', 'integer'],
        ]);

        $folderId = $validated['folder_id'] ?? null;

        if ($folderId !== null) {
            VirtualFolder::where('user_id', $request->user()->id)->findOrFail($folderId);
        }

        $count = VirtualFile::where('user_id', $request->user()->id)
            ->whereIn('id', $validated['file_ids'])
            ->update(['virtual_folder_id' => $folderId]);

        return $this->toast([
            'type' => 'success',
            'message' => "{$count} berkas berhasil dipindahkan.",
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['required', 'integer'],
        ]);

        $files = VirtualFile::where('user_id', $request->user()->id)
            ->whereIn('id', $validated['file_ids'])
            ->with('account')
            ->get();

        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($files as $file) {
            $account = $file->account;

            if (! $account || $account->status !== AccountStatus::Active) {
                $skippedCount++;
                continue;
            }

            $merged = DownloadFileJob::mergedPath($this->manager, $file);
            if (file_exists($merged)) {
                @unlink($merged);
            }

            $this->thumbnailService->deleteThumbnail($file);
            $this->previewService->deleteCache($file);

            try {
                $this->manager->driver($account)->delete($account, $file->remote_ref);
            } catch (\Throwable $e) {
                Log::warning("Gagal menghapus berkas remote {$file->id}: {$e->getMessage()}");
            }

            $file->delete();
            $deletedCount++;
        }

        if ($deletedCount === 0 && $skippedCount > 0) {
            return $this->toast([
                'type' => 'error',
                'message' => 'Semua berkas yang dipilih berada di akun yang terputus, tidak dapat dihapus.',
            ]);
        }

        if ($skippedCount > 0) {
            return $this->toast([
                'type' => 'info',
                'message' => "{$deletedCount} berkas berhasil dihapus. {$skippedCount} berkas dilewati karena akun terputus.",
            ]);
        }

        return $this->toast([
            'type' => 'success',
            'message' => "{$deletedCount} berkas berhasil dihapus.",
        ]);
    }

    public function bulkZip(Request $request)
    {
        $validated = $request->validate([
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['required', 'integer'],
        ]);

        $files = VirtualFile::where('user_id', $request->user()->id)
            ->whereIn('id', $validated['file_ids'])
            ->with('account')
            ->get();

        $validFiles = $files->filter(fn (VirtualFile $f) => $f->account && $f->account->status === AccountStatus::Active);

        if ($validFiles->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada berkas aktif yang dapat diunduh.',
            ], 422);
        }

        $archiveName = 'berkas_' . now()->format('Ymd_His') . '.zip';

        $fileJob = FileJob::create([
            'user_id' => $request->user()->id,
            'type' => 'zip',
            'original_name' => $archiveName,
            'size' => $validFiles->sum('size'),
            'mime_type' => 'application/zip',
            'status' => JobStatus::Pending,
            'progress' => 0,
        ]);

        CreateZipArchiveJob::dispatch($fileJob->id, $validFiles->pluck('id')->all());

        $fileJob->refresh();

        return response()->json([
            'job_id' => $fileJob->id,
            'name' => $fileJob->original_name,
            'status' => $fileJob->status->value,
            'progress' => $fileJob->progress,
            'download_url' => $fileJob->status === JobStatus::Done ? route('files.zip.download', $fileJob) : null,
        ]);
    }

    public function zipStatus(Request $request, FileJob $job)
    {
        abort_unless($job->user_id === $request->user()->id, 404);
        abort_unless($job->type === 'zip', 404);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status->value,
            'progress' => $job->progress,
            'name' => $job->original_name,
            'size' => $job->size,
            'error' => $job->error,
            'download_url' => $job->status === JobStatus::Done ? route('files.zip.download', $job) : null,
        ]);
    }

    public function zipDownload(Request $request, FileJob $job)
    {
        abort_unless($job->user_id === $request->user()->id, 404);
        abort_unless($job->type === 'zip', 404);

        $zipPath = CreateZipArchiveJob::zipPath($this->manager, $job);

        if (! file_exists($zipPath)) {
            abort(404, 'Berkas ZIP tidak ditemukan atau sudah dibersihkan.');
        }

        return response()->download($zipPath, $job->original_name)->deleteFileAfterSend(true);
    }

    public function toggleStarFile(Request $request, VirtualFile $file): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $file->update(['is_starred' => ! $file->is_starred]);

        if ($request->wantsJson()) {
            return response()->json([
                'is_starred' => (bool) $file->is_starred,
                'message' => $file->is_starred ? 'Ditambahkan ke Favorit.' : 'Dihapus dari Favorit.',
            ]);
        }

        return back();
    }

    public function toggleStarFolder(Request $request, VirtualFolder $folder): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 404);

        $folder->update(['is_starred' => ! $folder->is_starred]);

        if ($request->wantsJson()) {
            return response()->json([
                'is_starred' => (bool) $folder->is_starred,
                'message' => $folder->is_starred ? 'Folder ditambahkan ke Favorit.' : 'Folder dihapus dari Favorit.',
            ]);
        }

        return back();
    }

    public function updateFileLabels(Request $request, VirtualFile $file): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'label_ids' => ['present', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('user_id', $request->user()->id)],
        ]);

        $file->labels()->sync($validated['label_ids']);

        if ($request->wantsJson()) {
            return response()->json([
                'labels' => $file->labels()->get(['labels.id', 'labels.name', 'labels.color']),
                'message' => 'Label berkas berhasil diperbarui.',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Label berkas \"{$file->name}\" berhasil disimpan.",
        ]);
    }

    public function updateFolderLabels(Request $request, VirtualFolder $folder): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'label_ids' => ['present', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('user_id', $request->user()->id)],
        ]);

        $folder->labels()->sync($validated['label_ids']);

        if ($request->wantsJson()) {
            return response()->json([
                'labels' => $folder->labels()->get(['labels.id', 'labels.name', 'labels.color']),
                'message' => 'Label folder berhasil diperbarui.',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Label folder \"{$folder->name}\" berhasil disimpan.",
        ]);
    }

    public function bulkStar(Request $request): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'file_ids' => ['nullable', 'array'],
            'file_ids.*' => ['integer'],
            'folder_ids' => ['nullable', 'array'],
            'folder_ids.*' => ['integer'],
            'is_starred' => ['required', 'boolean'],
        ]);

        $userId = $request->user()->id;
        $status = (bool) $validated['is_starred'];

        if (! empty($validated['file_ids'])) {
            VirtualFile::where('user_id', $userId)
                ->whereIn('id', $validated['file_ids'])
                ->update(['is_starred' => $status]);
        }

        if (! empty($validated['folder_ids'])) {
            VirtualFolder::where('user_id', $userId)
                ->whereIn('id', $validated['folder_ids'])
                ->update(['is_starred' => $status]);
        }

        $msg = $status ? 'Item terpilih ditandai sebagai Favorit.' : 'Item terpilih dihapus dari Favorit.';

        if ($request->wantsJson()) {
            return response()->json(['message' => $msg]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $msg,
        ]);
    }

    public function bulkLabels(Request $request): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'file_ids' => ['nullable', 'array'],
            'file_ids.*' => ['integer'],
            'folder_ids' => ['nullable', 'array'],
            'folder_ids.*' => ['integer'],
            'label_ids' => ['required', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('user_id', $user->id)],
            'action' => ['required', 'string', 'in:attach,detach,sync'],
        ]);

        $action = $validated['action'];
        $labelIds = $validated['label_ids'];

        if (! empty($validated['file_ids'])) {
            $files = VirtualFile::where('user_id', $user->id)
                ->whereIn('id', $validated['file_ids'])
                ->get();

            foreach ($files as $file) {
                if ($action === 'attach') {
                    $file->labels()->syncWithoutDetaching($labelIds);
                } elseif ($action === 'detach') {
                    $file->labels()->detach($labelIds);
                } elseif ($action === 'sync') {
                    $file->labels()->sync($labelIds);
                }
            }
        }

        if (! empty($validated['folder_ids'])) {
            $folders = VirtualFolder::where('user_id', $user->id)
                ->whereIn('id', $validated['folder_ids'])
                ->get();

            foreach ($folders as $folder) {
                if ($action === 'attach') {
                    $folder->labels()->syncWithoutDetaching($labelIds);
                } elseif ($action === 'detach') {
                    $folder->labels()->detach($labelIds);
                } elseif ($action === 'sync') {
                    $folder->labels()->sync($labelIds);
                }
            }
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Label item terpilih berhasil diperbarui.']);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Label item terpilih berhasil diperbarui.',
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
