<?php

namespace App\Values;

final readonly class UploadResult
{
    /**
     * @param  string  $remoteRef  Reference to the uploaded file at the provider.
     * @param  int  $size  Uploaded size in bytes.
     * @param  array<int, array{chunk_index: int, remote_file_id: string, size: int, checksum: string}>|null  $chunks
     *            Present when the driver split the file into chunks (Telegram).
     */
    public function __construct(
        public string $remoteRef,
        public int $size,
        public ?array $chunks = null,
    ) {}

    public function isChunked(): bool
    {
        return $this->chunks !== null;
    }
}
