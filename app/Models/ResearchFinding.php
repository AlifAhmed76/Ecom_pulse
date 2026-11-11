<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchFinding extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = [
        'new',
        'reviewing',
        'approved',
        'testing',
        'validated',
        'rejected',
        'dismissed',
    ];

    public const OPEN_STATUSES = [
        'new',
        'reviewing',
        'approved',
        'testing',
    ];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const CONFIDENCES = ['high', 'medium', 'low'];

    public const RULES = [
        'R01', 'R02', 'R03', 'R04', 'R05', 'R06',
        'R07', 'R08', 'R09', 'R10', 'R11', 'R12',
    ];

    /**
     * Sensitive attribution, status, and tenant columns are never mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'is_insufficient_data' => 'boolean',
            'params' => 'array',
            'evidence' => 'array',
            'hypotheses' => 'array',
            'missing_data' => 'array',
            'success_metric' => 'array',
            'money_at_stake' => 'decimal:2',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'ai_generated_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function experiments(): HasMany
    {
        return $this->hasMany(Experiment::class, 'finding_id')->latest();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function severityWeight(): int
    {
        return match ($this->severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    public function confidenceWeight(): int
    {
        return match ($this->confidence) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
