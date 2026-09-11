<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileChunk extends Model
{
    protected $fillable = ['virtual_file_id', 'chunk_index', 'remote_file_id', 'size', 'checksum'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(VirtualFile::class, 'virtual_file_id');
    }
}
