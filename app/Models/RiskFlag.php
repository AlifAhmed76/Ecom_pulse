<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskFlag extends Model
{
    use BelongsToTenant, HasFactory;

    public const RULES = ['S1', 'S2', 'S3'];

    public const STATUSES = ['open', 'reviewed_ok', 'confirmed'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function ruleTitle(string $rule): string
    {
        return match ($rule) {
            'S1' => 'S1: Repeat failure (>= 3 cancelled/returned orders in 30 days)',
            'S2' => 'S2: Duplicate order (same customer, item set & total within 24 h)',
            'S3' => 'S3: Order burst (>= 5 orders from same customer within 1 hour)',
            default => $rule,
        };
    }
}
