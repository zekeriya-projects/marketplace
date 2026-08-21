<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use App\Events\InventoryChanged;
use App\Events\VariantPriceChanged;
use App\Integrations\ConnectorManager;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Hepsiburada\HepsiburadaConnector;
use App\Integrations\Ticimax\Contracts\TicimaxSoapTransport;
use App\Integrations\Ticimax\NativeTicimaxSoapTransport;
use App\Integrations\Ticimax\TicimaxConnector;
use App\Integrations\Trendyol\TrendyolConnector;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceConnector;
use App\Integrations\WooCommerce\WooCommerceOrderImporter;
use App\Listeners\QueueVariantInventorySync;
use App\Listeners\QueueVariantPriceSync;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Models\VariantTemplate;
use App\Models\Warehouse;
use App\Observers\SyncOperationObserver;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\ChannelAccountPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\TenantPolicy;
use App\Policies\VariantTemplatePolicy;
use App\Policies\WarehousePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(ConnectorManager::class);
        $this->app->alias(ConnectorManager::class, ConnectorRegistry::class);
        $this->app->bind(TicimaxSoapTransport::class, NativeTicimaxSoapTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(ChannelAccount::class, ChannelAccountPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(VariantTemplate::class, VariantTemplatePolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        SyncOperation::observe(SyncOperationObserver::class);
        Event::listen(InventoryChanged::class, QueueVariantInventorySync::class);
        Event::listen(VariantPriceChanged::class, QueueVariantPriceSync::class);
        RateLimiter::for('trendyol-sync', fn (): Limit => Limit::perMinute((int) config('services.trendyol.sync_rate_limit_per_minute', 60))->by('trendyol-api'));
        RateLimiter::for('login', fn ($request): Limit => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('registration', fn ($request): Limit => Limit::perMinute(3)->by($request->ip()));

        $this->app->make(ConnectorManager::class)->register('woocommerce', new WooCommerceConnector(
            $this->app->make(WooCommerceClient::class),
            $this->app->make(WooCommerceCatalogImporter::class),
            $this->app->make(WooCommerceOrderImporter::class),
        ));
        $this->app->make(ConnectorManager::class)->register('trendyol', $this->app->make(TrendyolConnector::class));
        $this->app->make(ConnectorManager::class)->register('hepsiburada', $this->app->make(HepsiburadaConnector::class));
        $this->app->make(ConnectorManager::class)->register('ticimax', $this->app->make(TicimaxConnector::class));
    }
}
