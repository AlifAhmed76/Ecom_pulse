<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierShipment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'cod_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'return_fee' => 'decimal:2',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'matched' => 'boolean',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
