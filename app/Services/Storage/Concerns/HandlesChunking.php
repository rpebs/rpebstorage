<?php

namespace App\Services\Storage\Concerns;

use RuntimeException;

/**
 * Split/merge for files that exceed a single provider request limit
 * (Telegram MTProto). Chunk index order and per-chunk checksums make the
 * merge verifiable, per PRD risk note in section 13.
 */
trait HandlesChunking
{
    /**
     * @return array<int, array{path: string, size: int, checksum: string}>
     */
    protected function splitFile(string $filePath, int $chunkSize): array
    {
        $size = filesize($filePath);
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Tidak bisa membaca {$filePath}");
        }

        $chunks = [];
        $offset = 0;
        $index = 0;

        while ($offset < $size) {
            $chunkPath = $filePath.".part{$index}";
            $chunkHandle = fopen($chunkPath, 'wb');
            $chunkChecksum = hash_init('md5');

            $remaining = min($chunkSize, $size - $offset);
            $written = 0;

            while ($written < $remaining) {
                $buffer = fread($handle, (int) min(1024 * 1024, $remaining - $written));

                if ($buffer === false || $buffer === '') {
                    break;
                }

                fwrite($chunkHandle, $buffer);
                hash_update($chunkChecksum, $buffer);
                $written += strlen($buffer);
            }

            fclose($chunkHandle);

            if ($written === 0) {
                // Unexpected EOF mid-file: fail loudly instead of spinning.
                fclose($handle);
                @unlink($chunkPath);
                throw new RuntimeException("File {$filePath} terpotong saat dibaca.");
            }

            $offset += $written;

            $chunks[] = [
                'path' => $chunkPath,
                'size' => $written,
                'checksum' => hash_final($chunkChecksum),
            ];

            $index++;
        }

        fclose($handle);

        return $chunks;
    }

    /**
     * @param  array<int, array{path: string, size: int, checksum: string}>  $chunks
     */
    protected function mergeChunks(array $chunks, string $destination): void
    {
        $dest = fopen($destination, 'wb');

        if ($dest === false) {
            throw new RuntimeException("Tidak bisa menulis {$destination}");
        }

        foreach ($chunks as $i => $chunk) {
            $actual = md5_file($chunk['path']);

            if ($actual !== $chunk['checksum']) {
                fclose($dest);
                unlink($destination);
                throw new RuntimeException("Checksum chunk {$i} tidak cocok, file korup saat transfer.");
            }

            $handle = fopen($chunk['path'], 'rb');

            while (($buffer = fread($handle, 1024 * 1024)) !== false && $buffer !== '') {
                fwrite($dest, $buffer);
            }

            fclose($handle);
        }

        fclose($dest);
    }
}
