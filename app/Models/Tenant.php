<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name', 'slug', 'status', 'subscription_plan', 'subscription_status', 'legal_title',
    'contact_first_name', 'contact_last_name', 'mobile_phone', 'company_email', 'website',
    'company_phone', 'province', 'district', 'address', 'company_type', 'tax_office', 'national_id',
])]
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'national_id' => 'encrypted',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function activeUsers(): HasMany
    {
        return $this->hasMany(User::class, 'active_tenant_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function variantTemplates(): HasMany
    {
        return $this->hasMany(VariantTemplate::class);
    }

    public function variantDefinitions(): HasMany
    {
        return $this->hasMany(VariantDefinition::class);
    }

    public function catalogImports(): HasMany
    {
        return $this->hasMany(CatalogImport::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function channelAccounts(): HasMany
    {
        return $this->hasMany(ChannelAccount::class);
    }

    public function trendyolListingTemplates(): HasMany
    {
        return $this->hasMany(TrendyolListingTemplate::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }
}
