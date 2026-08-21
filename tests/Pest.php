<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

function tenantMember(Tenant $tenant, TenantRole $role = TenantRole::Owner): User
{
    $user = User::factory()->create(['active_tenant_id' => null]);
    $tenant->users()->attach($user, ['role' => $role->value]);
    $user->update(['active_tenant_id' => $tenant->id]);

    return $user;
}

function trendyolAccount(Tenant $tenant, array $attributes = []): ChannelAccount
{
    return ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'trendyol')->sole()->id, ...$attributes]);
}

function trendyolCredentials(array $overrides = []): array
{
    return ['seller_id' => '123456', 'api_key' => 'trend-api-key', 'api_secret' => 'trend-api-secret', 'environment' => 'production', ...$overrides];
}

function wooAccount(Tenant $tenant, array $attributes = []): ChannelAccount
{
    return ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id, ...$attributes]);
}

function wooCredentialsPayload(array $overrides = []): array
{
    return ['store_url' => 'https://shop.example.com/', 'consumer_key' => 'ck_'.str_repeat('a', 40), 'consumer_secret' => 'cs_'.str_repeat('b', 40), ...$overrides];
}

function orderListing(Tenant $tenant, ChannelAccount $account, array $overrides = []): ChannelListing
{
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id]);

    return ChannelListing::query()->create([
        'tenant_id' => $tenant->id, 'channel_account_id' => $account->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'external_product_id' => '55', 'external_variant_id' => '77', 'external_sku' => 'SKU-55', 'external_barcode' => '86955', 'status' => ChannelListingStatus::Active,
        ...$overrides,
    ]);
}
