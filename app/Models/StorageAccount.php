<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageAccount extends Model
{
    protected $fillable = [
        'user_id',
        'storage_provider_id',
        'alias',
        'credentials',
        'meta',
        'quota_total',
        'quota_used',
        'quota_synced_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta' => 'array',
            'quota_synced_at' => 'datetime',
            'status' => AccountStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class, 'storage_provider_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(VirtualFile::class);
    }

    public function isUnlimited(): bool
    {
        return $this->quota_total === null;
    }

    public function remainingQuota(): ?int
    {
        return $this->quota_total === null ? null : max(0, $this->quota_total - $this->quota_used);
    }

    public function usedPercent(): ?float
    {
        if ($this->quota_total === null || $this->quota_total === 0) {
            return null;
        }

        return round(($this->quota_used / $this->quota_total) * 100, 1);
    }

    public function isNearlyFull(): bool
    {
        return $this->usedPercent() !== null && $this->usedPercent() >= config('rpebs.quota_warning_percent', 90);
    }
}
