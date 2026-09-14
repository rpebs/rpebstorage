<?php

namespace Tests\Unit;

use App\Services\Storage\Concerns\HandlesChunking;
use PHPUnit\Framework\TestCase;

class HandlesChunkingTest extends TestCase
{
    private object $impl;

    private string $source;

    protected function setUp(): void
    {
        // splitFile/mergeChunks are protected (driver internals), so expose
        // them through a small public facade on an anonymous class.
        $this->impl = new class
        {
            use HandlesChunking;

            public function split(string $path, int $chunkSize): array
            {
                return $this->splitFile($path, $chunkSize);
            }

            public function merge(array $chunks, string $destination): void
            {
                $this->mergeChunks($chunks, $destination);
            }
        };

        $this->source = tempnam(sys_get_temp_dir(), 'chunksrc');
        file_put_contents($this->source, random_bytes(1024 * 512)); // 512 KB
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
    }

    public function test_split_produces_ordered_chunks_with_correct_checksums(): void
    {
        $chunks = $this->impl->split($this->source, 200 * 1024); // 200 KB

        $this->assertCount(3, $chunks);
        $this->assertSame(512 * 1024, array_sum(array_column($chunks, 'size')));

        foreach ($chunks as $index => $chunk) {
            $this->assertSame(hash_file('md5', $chunk['path']), $chunk['checksum']);
            @unlink($chunk['path']);
        }
    }

    public function test_merge_restores_original_bytes_and_validates_checksum(): void
    {
        $chunks = $this->impl->split($this->source, 200 * 1024);
        $dest = tempnam(sys_get_temp_dir(), 'chunkdest');

        $this->impl->merge($chunks, $dest);

        $this->assertSame(md5_file($this->source), md5_file($dest));

        // Corrupt one checksum: merge must refuse to produce a bad file.
        $chunks[1]['checksum'] = str_repeat('0', 32);

        $badDest = tempnam(sys_get_temp_dir(), 'chunkbad');
        $this->expectException(\RuntimeException::class);
        $this->impl->merge($chunks, $badDest);
    }
}
