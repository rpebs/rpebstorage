<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class VirtualFile extends Model
{
    protected $fillable = [
        'user_id',
        'virtual_folder_id',
        'storage_account_id',
        'name',
        'remote_ref',
        'size',
        'mime_type',
        'is_chunked',
        'is_starred',
    ];

    protected function casts(): array
    {
        return [
            'is_starred' => 'boolean',
            'is_chunked' => 'boolean',
        ];
    }

    /**
     * @return MorphToMany<Label, $this>
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable')->withTimestamps();
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

    public function chunks(): HasMany
    {
        return $this->hasMany(FileChunk::class)->orderBy('chunk_index');
    }

    /**
     * True when the file cannot be downloaded/deleted because its
     * storage account is disconnected.
     */
    public function isAccessible(): bool
    {
        return $this->account?->status === AccountStatus::Active;
    }
}
