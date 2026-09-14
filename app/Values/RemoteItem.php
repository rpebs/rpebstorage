<?php

namespace App\Values;

class RemoteItem
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isFolder,
        public ?int $size = null,
        public ?string $mimeType = null,
        public ?string $parentId = null,
        public ?string $remotePath = null,
    ) {}
}
