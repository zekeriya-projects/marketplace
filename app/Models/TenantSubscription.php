<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'subscription_plan_id', 'status', 'billing_cycle', 'starts_at', 'trial_ends_at', 'current_period_ends_at', 'cancelled_at', 'notes'])]
final class TenantSubscription extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'trial_ends_at' => 'datetime', 'current_period_ends_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
