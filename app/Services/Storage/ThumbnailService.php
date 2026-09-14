<?php

namespace App\Services\Storage;

use App\Enums\AccountStatus;
use App\Jobs\DownloadFileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class ThumbnailService
{
    private const SUPPORTED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico',
    ];

    private const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/svg+xml',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    public function __construct(private StorageManager $manager) {}

    /**
     * Check if the given VirtualFile supports thumbnail generation/display.
     */
    public function supports(VirtualFile $file): bool
    {
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        if (in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
            return true;
        }

        $mime = strtolower((string) $file->mime_type);
        if (in_array($mime, self::SUPPORTED_MIMES, true)) {
            return true;
        }

        return str_starts_with($mime, 'image/');
    }

    /**
     * Path where thumbnails are cached on disk.
     */
    public function thumbnailDir(): string
    {
        return storage_path('app/' . config('rpebs.thumbnails.path', 'thumbnails'));
    }

    /**
     * Ensure the thumbnail cache directory exists.
     */
    public function ensureThumbnailDir(): void
    {
        $dir = $this->thumbnailDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * Returns the cached thumbnail file path if it exists on disk, or null.
     */
    public function getThumbnailPath(VirtualFile $file): ?string
    {
        $dir = $this->thumbnailDir();
        $candidates = [
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.webp",
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.svg",
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.png",
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.jpg",
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && filesize($candidate) > 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Check if a cached thumbnail is already present on disk.
     */
    public function hasThumbnail(VirtualFile $file): bool
    {
        return $this->getThumbnailPath($file) !== null;
    }

    /**
     * Generate and cache a thumbnail for the VirtualFile from a local source file.
     */
    public function generateThumbnail(VirtualFile $file, string $sourceFilePath): ?string
    {
        if (! file_exists($sourceFilePath) || ! is_readable($sourceFilePath)) {
            return null;
        }

        $this->ensureThumbnailDir();
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $mime = strtolower((string) $file->mime_type);

        // For SVG files: copy SVG directly to cache.
        if ($ext === 'svg' || $mime === 'image/svg+xml') {
            $dest = $this->thumbnailDir() . DIRECTORY_SEPARATOR . "{$file->id}.svg";
            copy($sourceFilePath, $dest);
            return $dest;
        }

        // For raster images: resize using GD to WebP thumbnail.
        return $this->resizeRasterImage($file->id, $sourceFilePath);
    }

    /**
     * Get the cached thumbnail or generate it on-demand from the remote provider.
     */
    public function getOrGenerateThumbnail(VirtualFile $file): ?string
    {
        $cached = $this->getThumbnailPath($file);
        if ($cached !== null) {
            return $cached;
        }

        if (! $this->supports($file)) {
            return null;
        }

        $account = $file->account;
        if (! ($account instanceof StorageAccount) || $account->status !== AccountStatus::Active) {
            return null;
        }

        $tempPath = null;
        $shouldDeleteTemp = false;

        try {
            if ($file->is_chunked) {
                $merged = DownloadFileJob::mergedPath($this->manager, $file);
                if (file_exists($merged)) {
                    $tempPath = $merged;
                } else {
                    return null;
                }
            } else {
                $driver = $this->manager->driver($account);
                $tempPath = $driver->download($account, $file->remote_ref);
                $shouldDeleteTemp = true;
            }

            return $this->generateThumbnail($file, $tempPath);
        } catch (Throwable $e) {
            Log::warning('On-demand thumbnail generation failed', [
                'file_id' => $file->id,
                'name' => $file->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($shouldDeleteTemp && $tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Delete any cached thumbnails for the given VirtualFile.
     */
    public function deleteThumbnail(VirtualFile $file): void
    {
        $dir = $this->thumbnailDir();
        $candidates = [
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.webp",
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.svg",
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.png",
            $dir . DIRECTORY_SEPARATOR . "{$file->id}.jpg",
        ];

        foreach ($candidates as $fileCandidate) {
            if (file_exists($fileCandidate)) {
                @unlink($fileCandidate);
            }
        }
    }

    /**
     * Downscale a raster image to max 320x320 px preserving aspect ratio and transparency.
     */
    private function resizeRasterImage(int $fileId, string $sourceFilePath): ?string
    {
        $imageInfo = @getimagesize($sourceFilePath);
        if (! $imageInfo) {
            return null;
        }

        [$origWidth, $origHeight, $imageType] = $imageInfo;

        if ($origWidth <= 0 || $origHeight <= 0) {
            return null;
        }

        $source = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourceFilePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourceFilePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourceFilePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourceFilePath) : null,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourceFilePath) : null,
            default => null,
        };

        if (! $source) {
            return null;
        }

        // Handle EXIF orientation for JPEG
        if ($imageType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourceFilePath);
            if (! empty($exif['Orientation'])) {
                $rotated = match ((int) $exif['Orientation']) {
                    3 => imagerotate($source, 180, 0),
                    6 => imagerotate($source, -90, 0),
                    8 => imagerotate($source, 90, 0),
                    default => $source,
                };

                if ($rotated !== false && $rotated !== $source) {
                    if (in_array((int) $exif['Orientation'], [6, 8], true)) {
                        [$origWidth, $origHeight] = [$origHeight, $origWidth];
                    }
                    imagedestroy($source);
                    $source = $rotated;
                }
            }
        }

        $maxDim = (int) config('rpebs.thumbnails.max_width', 320);
        $ratio = min($maxDim / $origWidth, $maxDim / $origHeight, 1.0);
        $targetWidth = max(1, (int) round($origWidth * $ratio));
        $targetHeight = max(1, (int) round($origHeight * $ratio));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $target) {
            imagedestroy($source);
            return null;
        }

        // Preserve transparency for PNG, GIF, WebP
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $origWidth,
            $origHeight
        );

        $dest = $this->thumbnailDir() . DIRECTORY_SEPARATOR . "{$fileId}.webp";
        $quality = (int) config('rpebs.thumbnails.quality', 80);

        $saved = imagewebp($target, $dest, $quality);

        imagedestroy($source);
        imagedestroy($target);

        return $saved && file_exists($dest) ? $dest : null;
    }
}
