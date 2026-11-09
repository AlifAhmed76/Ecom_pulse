<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    use HasFactory;

    public const SCOPES = [
        'heartbeat' => 'Send heartbeat',
        'events:write' => 'Send events / webhooks',
        'products:write' => 'Sync products',
        'orders:write' => 'Sync orders',
    ];

    protected $fillable = [];

    protected $hidden = [
        'secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function state(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
