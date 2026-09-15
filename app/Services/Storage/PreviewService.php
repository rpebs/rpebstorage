<?php

namespace App\Services\Storage;

use App\Enums\AccountStatus;
use App\Jobs\DownloadFileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class PreviewService
{
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif',
    ];

    private const VIDEO_EXTENSIONS = [
        'mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v', 'ogv',
    ];

    private const AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'weba', 'opus',
    ];

    private const PDF_EXTENSIONS = [
        'pdf',
    ];

    private const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'json', 'csv', 'log', 'js', 'ts', 'vue',
        'jsx', 'tsx', 'php', 'py', 'html', 'css', 'xml', 'yaml', 'yml',
        'sql', 'sh', 'env', 'ini', 'conf',
    ];

    public function __construct(private StorageManager $manager) {}

    /**
     * Determine the preview type category: 'pdf', 'audio', 'video', 'image', 'text', or 'unsupported'.
     */
    public function previewType(VirtualFile $file): string
    {
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $mime = strtolower((string) $file->mime_type);

        if ($ext === 'pdf' || $mime === 'application/pdf' || $mime === 'application/x-pdf') {
            return 'pdf';
        }

        if (in_array($ext, self::IMAGE_EXTENSIONS, true) || str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (in_array($ext, self::VIDEO_EXTENSIONS, true) || str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (in_array($ext, self::AUDIO_EXTENSIONS, true) || str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        if (in_array($ext, self::TEXT_EXTENSIONS, true) || str_starts_with($mime, 'text/') || $mime === 'application/json' || $mime === 'application/xml') {
            return 'text';
        }

        return 'unsupported';
    }

    /**
     * Check if the file is supported for in-browser preview or streaming.
     */
    public function supports(VirtualFile $file): bool
    {
        return $this->previewType($file) !== 'unsupported';
    }

    /**
     * Directory path where preview/streaming cache is stored.
     */
    public function previewCacheDir(): string
    {
        return storage_path('app/' . config('rpebs.preview_cache.path', 'preview_cache'));
    }

    /**
     * Ensure the preview cache directory exists.
     */
    public function ensurePreviewCacheDir(): void
    {
        $dir = $this->previewCacheDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * Deterministic cache path for a file.
     */
    public function getCachePath(VirtualFile $file): string
    {
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $hash = md5($file->id . '_' . $file->updated_at . '_' . $file->size);
        $extPart = $ext !== '' ? ".{$ext}" : '';

        return $this->previewCacheDir() . DIRECTORY_SEPARATOR . "preview_{$file->id}_{$hash}{$extPart}";
    }

    /**
     * Check if the local file is already prepared and ready for streaming.
     */
    public function isReady(VirtualFile $file): bool
    {
        $cachePath = $this->getCachePath($file);
        if (file_exists($cachePath) && filesize($cachePath) > 0) {
            return true;
        }

        if ($file->is_chunked) {
            $merged = DownloadFileJob::mergedPath($this->manager, $file);
            return file_exists($merged) && filesize($merged) > 0;
        }

        return false;
    }

    /**
     * Prepare the file for streaming.
     * Returns the absolute path if ready, 'pending' if a background job was dispatched (chunked),
     * or false if preparation failed.
     *
     * @return string|false|'pending'
     */
    public function prepare(VirtualFile $file): string|false
    {
        // 1. Check existing preview cache
        $cachePath = $this->getCachePath($file);
        if (file_exists($cachePath) && filesize($cachePath) > 0) {
            return $cachePath;
        }

        $account = $file->account;
        if (! ($account instanceof StorageAccount) || $account->status !== AccountStatus::Active) {
            return false;
        }

        // 2. If chunked (Telegram), check merged file or dispatch merge job
        if ($file->is_chunked) {
            $merged = DownloadFileJob::mergedPath($this->manager, $file);
            if (file_exists($merged) && filesize($merged) > 0) {
                return $merged;
            }

            DownloadFileJob::dispatch($file->id);
            return 'pending';
        }

        // 3. For standard cloud drivers, download to preview cache
        $this->ensurePreviewCacheDir();

        try {
            $driver = $this->manager->driver($account);
            $tempDownloaded = $driver->download($account, $file->remote_ref);

            if (! file_exists($tempDownloaded) || filesize($tempDownloaded) === 0) {
                return false;
            }

            // Rename or copy to cached path
            if (! @rename($tempDownloaded, $cachePath)) {
                copy($tempDownloaded, $cachePath);
                @unlink($tempDownloaded);
            }

            return $cachePath;
        } catch (Throwable $e) {
            Log::error('Failed to prepare file for preview', [
                'file_id' => $file->id,
                'name' => $file->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Guess the most appropriate HTTP Content-Type for streaming.
     */
    public function resolveMimeType(VirtualFile $file, string $filePath): string
    {
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));

        $known = match ($ext) {
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg', 'oga' => 'audio/ogg',
            'ogv' => 'video/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'txt', 'log' => 'text/plain',
            'json' => 'application/json',
            'csv' => 'text/csv',
            'md', 'markdown' => 'text/markdown',
            'html' => 'text/html',
            'css' => 'text/css',
            'js', 'ts' => 'text/javascript',
            default => null,
        };

        if ($known !== null) {
            return $known;
        }

        if (! empty($file->mime_type) && $file->mime_type !== 'application/octet-stream') {
            return (string) $file->mime_type;
        }

        return mime_content_type($filePath) ?: 'application/octet-stream';
    }

    /**
     * Delete cached preview file(s) for a VirtualFile.
     */
    public function deleteCache(VirtualFile $file): void
    {
        $pattern = $this->previewCacheDir() . DIRECTORY_SEPARATOR . "preview_{$file->id}_*";
        $matches = glob($pattern);
        if ($matches) {
            foreach ($matches as $match) {
                if (file_exists($match)) {
                    @unlink($match);
                }
            }
        }
    }

    /**
     * Clean old preview cache files older than maxAgeHours.
     */
    public function cleanOldCache(int $maxAgeHours = 24): int
    {
        $dir = $this->previewCacheDir();
        if (! is_dir($dir)) {
            return 0;
        }

        $now = time();
        $ttl = $maxAgeHours * 3600;
        $deleted = 0;

        $files = scandir($dir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_file($full) && ($now - (filemtime($full) ?: $now)) > $ttl) {
                if (@unlink($full)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
