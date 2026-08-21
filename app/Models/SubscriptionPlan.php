<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'monthly_price_amount', 'yearly_price_amount', 'currency', 'trial_days', 'is_featured', 'status', 'sort_order'])]
final class SubscriptionPlan extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['monthly_price_amount' => 'integer', 'yearly_price_amount' => 'integer', 'trial_days' => 'integer', 'is_featured' => 'boolean', 'sort_order' => 'integer'];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}
