<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualFolder extends Model
{
    protected $fillable = ['user_id', 'parent_id', 'name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(VirtualFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(VirtualFolder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(VirtualFile::class);
    }
}
