<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    use BelongsToTenant, HasFactory;

    public const PLATFORMS = ['laravel', 'file'];

    protected $fillable = [
        'name',
        'platform',
        'domain',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isConnected(): bool
    {
        return $this->last_heartbeat_at !== null && $this->last_heartbeat_at->gt(now()->subMinutes(10));
    }
}
