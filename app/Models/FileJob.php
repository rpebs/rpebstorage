<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileJob extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'virtual_folder_id',
        'storage_account_id',
        'original_name',
        'size',
        'mime_type',
        'status',
        'progress',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(VirtualFolder::class, 'virtual_folder_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(StorageAccount::class, 'storage_account_id');
    }
}
