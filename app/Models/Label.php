<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Label extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'color',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphToMany<VirtualFile, $this>
     */
    public function files(): MorphToMany
    {
        return $this->morphedByMany(VirtualFile::class, 'labelable')->withTimestamps();
    }

    /**
     * @return MorphToMany<VirtualFolder, $this>
     */
    public function folders(): MorphToMany
    {
        return $this->morphedByMany(VirtualFolder::class, 'labelable')->withTimestamps();
    }
}
