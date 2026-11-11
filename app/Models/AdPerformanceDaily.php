<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdPerformanceDaily extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'ad_performance_daily';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend' => 'decimal:2',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'platform_conversions' => 'decimal:2',
            'platform_revenue' => 'decimal:2',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
