<?php

namespace App\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $user = Auth::user();
            if ($user !== null) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $user->tenant_id);
            }
        });

        static::creating(function ($model): void {
            if (empty($model->tenant_id) && ($user = Auth::user()) !== null) {
                $model->tenant_id = $user->tenant_id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
