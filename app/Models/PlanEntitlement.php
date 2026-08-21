<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_plan_id', 'feature_code', 'label', 'enabled', 'limit'])]
final class PlanEntitlement extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
