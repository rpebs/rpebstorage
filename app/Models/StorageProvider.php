<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProvider extends Model
{
    public const LABELS = [
        'google_drive' => 'Google Drive',
        'dropbox' => 'Dropbox',
        'onedrive' => 'OneDrive',
        'telegram' => 'Telegram',
        'mega' => 'MEGA',
    ];

    protected $fillable = ['name', 'driver_class', 'is_active'];

    // Bind {provider} route params by slug (google_drive), not id.
    public function getRouteKeyName(): string
    {
        return 'name';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return self::LABELS[$this->name] ?? $this->name;
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(StorageAccount::class);
    }
}
