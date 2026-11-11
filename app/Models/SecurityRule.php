<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityRule extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPES = [
        'login_failed',
        'rate_limit_hit',
        'signature_failed',
        'suspicious_request',
        'widget_auth_failed',
    ];

    public const MODES = ['log_only', 'throttle'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
