<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    use BelongsToTenant, HasFactory;

    public const REVIEW_STATUSES = ['open', 'false_positive', 'confirmed'];

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
