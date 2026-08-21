<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogImportController;
use App\Http\Controllers\CatalogImportTemplateController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChannelAccountController;
use App\Http\Controllers\ChannelListingController;
use App\Http\Controllers\ChannelListingMappingController;
use App\Http\Controllers\ChannelReferenceMappingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\SubscriptionController as PlatformSubscriptionController;
use App\Http\Controllers\Platform\SubscriptionPlanController as PlatformSubscriptionPlanController;
use App\Http\Controllers\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\Platform\TenantMemberController as PlatformTenantMemberController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SyncOperationController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantMemberController;
use App\Http\Controllers\TrendyolListingTemplateController;
use App\Http\Controllers\VariantController;
use App\Http\Controllers\VariantDefinitionController;
use App\Http\Controllers\VariantTemplateController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->user()?->is_platform_admin
    ? redirect()->route('platform.dashboard')
    : redirect()->route('dashboard'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:registration');
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::resource('products', ProductController::class)->except(['destroy']);
    Route::post('/products/bulk-publish', [ProductController::class, 'bulkPublish'])->middleware('entitled:bulk_operations')->name('products.bulk-publish');
    Route::post('/trendyol-listing-templates', [TrendyolListingTemplateController::class, 'store'])->name('trendyol-listing-templates.store');
    Route::delete('/trendyol-listing-templates/{template}', [TrendyolListingTemplateController::class, 'destroy'])->name('trendyol-listing-templates.destroy');
    Route::post('/products/{product}/woocommerce/{account}', [ProductController::class, 'publishWooCommerce'])->name('products.woocommerce.publish');
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('brands', BrandController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/variants', [VariantController::class, 'index'])->name('variants.index');
    Route::resource('variant-definitions', VariantDefinitionController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('variant-templates', VariantTemplateController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/imports', [CatalogImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [CatalogImportController::class, 'store'])->name('imports.store');
    Route::get('/import-templates', [CatalogImportTemplateController::class, 'index'])->name('import-templates.index');
    Route::get('/import-templates/catalog.xlsx', [CatalogImportTemplateController::class, 'download'])->name('import-templates.catalog.download');
    Route::resource('orders', OrderController::class)->only(['index', 'show']);
    Route::get('/reports', ReportController::class)->middleware('entitled:reports')->name('reports.index');
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status.update');
    Route::post('/orders/{order}/refresh', [OrderController::class, 'refresh'])->name('orders.refresh');
    Route::get('/channels', [ChannelAccountController::class, 'index'])->name('channels.index');
    Route::get('/listings', ChannelListingController::class)->name('listings.index');
    Route::post('/channel-listing-mappings', [ChannelListingMappingController::class, 'store'])->name('channel-listing-mappings.store');
    Route::post('/channel-reference-mappings', [ChannelReferenceMappingController::class, 'store'])->name('channel-reference-mappings.store');
    Route::delete('/channel-reference-mappings/{mapping}', [ChannelReferenceMappingController::class, 'destroy'])->name('channel-reference-mappings.destroy');
    Route::post('/channels/accounts', [ChannelAccountController::class, 'store'])->name('channel-accounts.store');
    Route::get('/channels/accounts/{account}', [ChannelAccountController::class, 'show'])->name('channel-accounts.show');
    Route::get('/channels/accounts/{account}/marketplace-products', [ChannelAccountController::class, 'marketplaceProducts'])->name('channel-accounts.marketplace-products');
    Route::put('/channels/accounts/{account}/woocommerce/credentials', [ChannelAccountController::class, 'updateWooCommerceCredentials'])->name('channel-accounts.woocommerce.credentials.update');
    Route::put('/channels/accounts/{account}/trendyol/credentials', [ChannelAccountController::class, 'updateTrendyolCredentials'])->name('channel-accounts.trendyol.credentials.update');
    Route::put('/channels/accounts/{account}/hepsiburada/credentials', [ChannelAccountController::class, 'updateHepsiburadaCredentials'])->name('channel-accounts.hepsiburada.credentials.update');
    Route::put('/channels/accounts/{account}/ticimax/credentials', [ChannelAccountController::class, 'updateTicimaxCredentials'])->name('channel-accounts.ticimax.credentials.update');
    Route::post('/channels/accounts/{account}/trendyol/listings', [ChannelAccountController::class, 'publishTrendyolListing'])->name('channel-accounts.trendyol.listings.store');
    Route::get('/channels/accounts/{account}/trendyol/categories', [ChannelAccountController::class, 'trendyolCategories'])->name('channel-accounts.trendyol.categories');
    Route::get('/channels/accounts/{account}/trendyol/brands', [ChannelAccountController::class, 'trendyolBrands'])->name('channel-accounts.trendyol.brands');
    Route::get('/channels/accounts/{account}/trendyol/categories/{categoryId}/attributes', [ChannelAccountController::class, 'trendyolCategoryAttributes'])->name('channel-accounts.trendyol.category-attributes');
    Route::post('/channels/accounts/{account}/test', [ChannelAccountController::class, 'testConnection'])->name('channel-accounts.test');
    Route::post('/channels/accounts/{account}/woocommerce/import-products', [ChannelAccountController::class, 'importProducts'])->name('channel-accounts.woocommerce.import-products');
    Route::post('/channels/accounts/{account}/woocommerce/pull-orders', [ChannelAccountController::class, 'pullOrders'])->name('channel-accounts.woocommerce.pull-orders');
    Route::post('/channels/accounts/{account}/trendyol/pull-orders', [ChannelAccountController::class, 'pullTrendyolOrders'])->name('channel-accounts.trendyol.pull-orders');
    Route::get('/sync', [SyncOperationController::class, 'index'])->name('sync.index');
    Route::post('/sync/{operation}/retry', [SyncOperationController::class, 'retry'])->name('sync.retry');
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::get('/inventory/{variant}', [InventoryController::class, 'show'])->name('inventory.show');
    Route::post('/inventory/{variant}/warehouses/{warehouse}/adjustments', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::put('/settings/organizations/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::put('/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/settings/organizations/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::post('/settings/organizations/{tenant}/switch', [TenantController::class, 'switch'])->name('tenants.switch');
    Route::post('/settings/organizations/{tenant}/members', [TenantMemberController::class, 'store'])->name('tenant-members.store');
    Route::put('/settings/organizations/{tenant}/members/{member}', [TenantMemberController::class, 'update'])->name('tenant-members.update');
    Route::delete('/settings/organizations/{tenant}/members/{member}', [TenantMemberController::class, 'destroy'])->name('tenant-members.destroy');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'platform_admin'])->prefix('platform')->name('platform.')->group(function (): void {
    Route::get('/dashboard', PlatformDashboardController::class)->name('dashboard');
    Route::get('/organizations', [PlatformTenantController::class, 'index'])->name('tenants.index');
    Route::post('/organizations', [PlatformTenantController::class, 'store'])->name('tenants.store');
    Route::get('/organizations/{tenant}', [PlatformTenantController::class, 'show'])->name('tenants.show');
    Route::put('/organizations/{tenant}', [PlatformTenantController::class, 'update'])->name('tenants.update');
    Route::post('/organizations/{tenant}/members', [PlatformTenantMemberController::class, 'store'])->name('tenants.members.store');
    Route::put('/organizations/{tenant}/members/{member}', [PlatformTenantMemberController::class, 'update'])->name('tenants.members.update');
    Route::delete('/organizations/{tenant}/members/{member}', [PlatformTenantMemberController::class, 'destroy'])->name('tenants.members.destroy');
    Route::resource('plans', PlatformSubscriptionPlanController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/subscriptions', [PlatformSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::put('/organizations/{tenant}/subscription', [PlatformSubscriptionController::class, 'update'])->name('subscriptions.update');
});
