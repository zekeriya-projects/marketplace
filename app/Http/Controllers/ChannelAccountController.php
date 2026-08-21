<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\CreateChannelAccount;
use App\Domain\Channels\Actions\PublishTrendyolListing;
use App\Domain\Channels\Actions\SaveHepsiburadaCredentials;
use App\Domain\Channels\Actions\SaveTicimaxCredentials;
use App\Domain\Channels\Actions\SaveTrendyolCredentials;
use App\Domain\Channels\Actions\SaveWooCommerceCredentials;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Orders\Actions\DispatchTrendyolOrderPull;
use App\Domain\Orders\Actions\DispatchWooCommerceOrderPull;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\PublishTrendyolListingRequest;
use App\Http\Requests\StoreChannelAccountRequest;
use App\Http\Requests\UpdateHepsiburadaCredentialsRequest;
use App\Http\Requests\UpdateTicimaxCredentialsRequest;
use App\Http\Requests\UpdateTrendyolCredentialsRequest;
use App\Http\Requests\UpdateWooCommerceCredentialsRequest;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Integrations\Trendyol\TrendyolClient;
use App\Integrations\WooCommerce\DTO\WooCommerceCredentials;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Jobs\ImportWooCommerceProductsPageJob;
use App\Jobs\TestChannelConnectionJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ChannelAccountController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', ChannelAccount::class);

        $filters = $request->validate([
            'category' => ['nullable', Rule::in(['marketplace', 'ecommerce', 'shipping'])],
        ]);
        $category = $filters['category'] ?? 'marketplace';
        $channelType = match ($category) {
            'marketplace' => 'marketplace',
            'ecommerce' => 'storefront',
            'shipping' => 'shipping',
        };

        return Inertia::render('Channels/Index', [
            'category' => $category,
            'channels' => Channel::query()->where('is_active', true)->where('type', $channelType)->orderBy('name')->get(['id', 'code', 'name', 'type'])->map(fn (Channel $channel): array => [
                ...$channel->toArray(),
                'connection_available' => in_array($channel->code, ['woocommerce', 'trendyol', 'hepsiburada', 'ticimax'], true),
            ]),
            'accounts' => $context->get()->channelAccounts()->whereHas('channel', fn ($query) => $query->where('type', $channelType))->with('channel:id,code,name')->withCount('listings')->orderBy('name')->get()->map(fn (ChannelAccount $account): array => [
                ...$account->only(['id', 'name', 'status', 'last_connected_at']),
                'channel' => $account->channel->only(['code', 'name']),
                'listings_count' => $account->listings_count,
                'credentials_configured' => $account->getRawOriginal('credentials_encrypted') !== null,
            ]),
            'canManage' => Gate::allows('create', ChannelAccount::class),
        ]);
    }

    public function store(StoreChannelAccountRequest $request, TenantContext $context, CreateChannelAccount $action): RedirectResponse
    {
        $account = $action->execute($context->get(), $request->validated());
        $account->load('channel:id,type');
        $category = match ($account->channel->type) {
            'storefront' => 'ecommerce',
            'shipping' => 'shipping',
            default => 'marketplace',
        };

        return redirect()->route('channels.index', ['category' => $category])->with('success', 'Channel account created. Credentials can be configured in the provider connection phase.');
    }

    public function show(ChannelAccount $account): Response
    {
        Gate::authorize('view', $account);
        $account->load('channel:id,code,name');
        $credentials = $account->credentials_encrypted;

        if ($account->channel->code === 'hepsiburada') {
            return Inertia::render('Channels/Hepsiburada/Show', [
                'account' => [
                    ...$account->only(['id', 'name', 'status', 'last_connected_at']),
                    'channel' => $account->channel->only(['code', 'name']),
                    'credentials_configured' => is_array($credentials),
                    'merchant_id' => is_array($credentials) ? $credentials['merchant_id'] : null,
                    'environment' => is_array($credentials) ? $credentials['environment'] : 'production',
                    'username_hint' => is_array($credentials) ? '••••'.substr($credentials['username'], -4) : null,
                ],
                'canManage' => Gate::allows('update', $account),
            ]);
        }

        if ($account->channel->code === 'trendyol') {
            return Inertia::render('Channels/Trendyol/Show', [
                'account' => [
                    ...$account->only(['id', 'name', 'status', 'last_connected_at']),
                    'channel' => $account->channel->only(['code', 'name']),
                    'credentials_configured' => is_array($credentials),
                    'seller_id' => is_array($credentials) ? $credentials['seller_id'] : null,
                    'environment' => is_array($credentials) ? $credentials['environment'] : 'production',
                    'api_key_hint' => is_array($credentials) ? '••••'.substr($credentials['api_key'], -4) : null,
                ],
                'canManage' => Gate::allows('update', $account),
                'latestOrderPull' => $account->syncOperations()->where('operation', 'order_pull')->latest()->first()?->only(['id', 'status', 'context', 'created_at', 'finished_at', 'safe_error_message']),
            ]);
        }

        if ($account->channel->code === 'ticimax') {
            return Inertia::render('Channels/Ticimax/Show', [
                'account' => [
                    ...$account->only(['id', 'name', 'status', 'last_connected_at']),
                    'channel' => $account->channel->only(['code', 'name']),
                    'credentials_configured' => is_array($credentials),
                    'store_url' => is_array($credentials) ? $credentials['store_url'] : null,
                    'member_code_hint' => is_array($credentials) ? '••••'.substr($credentials['member_code'], -4) : null,
                ],
                'canManage' => Gate::allows('update', $account),
            ]);
        }

        abort_unless($account->channel->code === 'woocommerce', 404);

        return Inertia::render('Channels/WooCommerce/Show', [
            'account' => [
                ...$account->only(['id', 'name', 'status', 'last_connected_at']),
                'channel' => $account->channel->only(['code', 'name']),
                'credentials_configured' => is_array($credentials),
                'store_url' => is_array($credentials) ? $credentials['store_url'] : null,
                'consumer_key_hint' => is_array($credentials) ? '••••'.substr($credentials['consumer_key'], -4) : null,
                'shipped_status' => data_get($account->settings, 'order_status_mappings.shipped'),
            ],
            'canManage' => Gate::allows('update', $account),
            'latestImport' => $account->syncOperations()->where('operation', 'product_import')->latest()->first()?->only(['id', 'status', 'context', 'created_at', 'finished_at', 'safe_error_message']),
            'latestOrderPull' => $account->syncOperations()->where('operation', 'order_pull')->latest()->first()?->only(['id', 'status', 'context', 'created_at', 'finished_at', 'safe_error_message']),
        ]);
    }

    public function updateWooCommerceCredentials(UpdateWooCommerceCredentialsRequest $request, ChannelAccount $account, SaveWooCommerceCredentials $action): RedirectResponse
    {
        $action->execute($account, $request->validated());

        return redirect()->route('channel-accounts.show', $account)->with('success', 'WooCommerce credentials saved securely. Test the connection to activate the account.');
    }

    public function updateTrendyolCredentials(UpdateTrendyolCredentialsRequest $request, ChannelAccount $account, SaveTrendyolCredentials $action): RedirectResponse
    {
        $action->execute($account, $request->validated());

        return redirect()->route('channel-accounts.show', $account)->with('success', 'Trendyol credentials saved securely. Test the connection to activate the account.');
    }

    public function updateHepsiburadaCredentials(UpdateHepsiburadaCredentialsRequest $request, ChannelAccount $account, SaveHepsiburadaCredentials $action): RedirectResponse
    {
        $action->execute($account, $request->validated());

        return redirect()->route('channel-accounts.show', $account)->with('success', 'Hepsiburada kimlik bilgileri şifreli olarak kaydedildi. Hesabı etkinleştirmek için bağlantıyı test edin.');
    }

    public function updateTicimaxCredentials(UpdateTicimaxCredentialsRequest $request, ChannelAccount $account, SaveTicimaxCredentials $action): RedirectResponse
    {
        $action->execute($account, $request->validated());

        return redirect()->route('channel-accounts.show', $account)->with('success', 'Ticimax üye kodu şifreli olarak kaydedildi. Hesabı etkinleştirmek için bağlantıyı test edin.');
    }

    public function publishTrendyolListing(PublishTrendyolListingRequest $request, ChannelAccount $account, PublishTrendyolListing $action): RedirectResponse
    {
        $data = $request->validated();
        $selected = collect($data['attributes'])->pluck('attributeId')->map(fn ($id) => (int) $id);
        abort_if(collect($data['required_attribute_ids'])->contains(fn ($id) => ! $selected->contains((int) $id)), 422, 'Zorunlu Trendyol özellikleri tamamlanmalıdır.');
        $outcome = $action->queue($account, $data);
        if (filled($data['template_name'] ?? null)) {
            $account->tenant->trendyolListingTemplates()->create([
                'name' => $data['template_name'], 'category_id' => $data['central_category_id'] ?? null,
                'trendyol_category_id' => $data['category_id'], 'trendyol_brand_id' => $data['brand_id'],
                'image_url' => null, 'vat_rate' => $data['vat_rate'], 'dimensional_weight' => $data['dimensional_weight'],
                'origin' => strtoupper($data['origin']), 'attributes' => $data['attributes'], 'required_attribute_ids' => $data['required_attribute_ids'],
            ]);
        }

        return back()->with('success', $outcome->queued() ? 'Trendyol ürün yayını kuyruğa alındı.' : 'Trendyol ürün yayını zaten kuyrukta.');
    }

    public function trendyolCategories(ChannelAccount $account, TrendyolClient $client): JsonResponse
    {
        $this->authorizeTrendyolLookup($account);
        $response = $client->categories(TrendyolCredentials::fromAccount($account));

        if (! $response['result']->successful) {
            return response()->json(['message' => $response['result']->safeMessage], 422);
        }

        return response()->json(['categories' => $this->flattenCategories($response['categories'])]);
    }

    public function trendyolBrands(Request $request, ChannelAccount $account, TrendyolClient $client): JsonResponse
    {
        $this->authorizeTrendyolLookup($account);
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']]);
        $response = $client->brands(TrendyolCredentials::fromAccount($account), $validated['name']);

        return $response['result']->successful
            ? response()->json(['brands' => collect($response['brands'])->map(fn (array $brand): array => ['id' => $brand['id'] ?? null, 'name' => $brand['name'] ?? ''])->filter(fn (array $brand): bool => is_numeric($brand['id']) && $brand['name'] !== '')->values()])
            : response()->json(['message' => $response['result']->safeMessage], 422);
    }

    public function trendyolCategoryAttributes(ChannelAccount $account, int $categoryId, TrendyolClient $client): JsonResponse
    {
        $this->authorizeTrendyolLookup($account);
        abort_if($categoryId < 1, 404);
        $response = $client->categoryAttributes(TrendyolCredentials::fromAccount($account), $categoryId);

        return $response['result']->successful
            ? response()->json(['attributes' => $response['attributes']])
            : response()->json(['message' => $response['result']->safeMessage], 422);
    }

    public function marketplaceProducts(Request $request, ChannelAccount $account, WooCommerceClient $wooCommerce, TrendyolClient $trendyol): JsonResponse
    {
        Gate::authorize('update', $account);
        $account->loadMissing('channel');
        abort_unless($account->status === ChannelAccountStatus::Active, 422, 'Önce entegrasyon bağlantısını etkinleştirin.');
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);

        if ($account->channel->code === 'woocommerce') {
            $response = $wooCommerce->searchProducts(WooCommerceCredentials::fromAccount($account), $search, $page);
            $products = collect($response['products'])->map(fn (array $item): array => [
                'external_product_id' => (string) ($item['id'] ?? ''),
                'external_variant_id' => null,
                'name' => (string) ($item['name'] ?? 'İsimsiz ürün'),
                'sku' => ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null,
                'barcode' => ($item['global_unique_id'] ?? '') !== '' ? (string) $item['global_unique_id'] : null,
                'image_url' => is_array($item['images'] ?? null) ? ($item['images'][0]['src'] ?? null) : null,
                'status' => (string) ($item['status'] ?? ''),
                'selectable' => ($item['type'] ?? 'simple') === 'simple',
                'note' => ($item['type'] ?? 'simple') === 'simple' ? null : 'Varyantlı ürünü önce WooCommerce’den içe aktarın.',
            ])->filter(fn (array $item): bool => $item['external_product_id'] !== '')->values();
        } elseif ($account->channel->code === 'trendyol') {
            $response = $trendyol->searchProducts(TrendyolCredentials::fromAccount($account), $search, $page - 1);
            $products = collect($response['products'])->map(fn (array $item): array => [
                'external_product_id' => (string) ($item['productMainId'] ?? $item['id'] ?? ''),
                'external_variant_id' => isset($item['id']) ? (string) $item['id'] : null,
                'name' => (string) ($item['title'] ?? 'İsimsiz ürün'),
                'sku' => ($item['stockCode'] ?? '') !== '' ? (string) $item['stockCode'] : null,
                'barcode' => ($item['barcode'] ?? '') !== '' ? (string) $item['barcode'] : null,
                'image_url' => is_array($item['images'] ?? null) ? ($item['images'][0]['url'] ?? null) : null,
                'status' => (bool) ($item['approved'] ?? false) ? 'approved' : 'pending',
                'selectable' => true,
                'note' => null,
            ])->filter(fn (array $item): bool => $item['external_product_id'] !== '')->values();
        } else {
            abort(404);
        }
        if (! $response['result']->successful) {
            return response()->json(['message' => $response['result']->safeMessage], 422);
        }
        $mapped = ChannelListing::query()->where('tenant_id', $account->tenant_id)->where('channel_account_id', $account->id)->whereIn('external_product_id', $products->pluck('external_product_id'))->pluck('external_product_id')->all();

        return response()->json(['products' => $products->map(fn (array $item): array => [...$item, 'already_mapped' => in_array($item['external_product_id'], $mapped, true)])]);
    }

    private function authorizeTrendyolLookup(ChannelAccount $account): void
    {
        Gate::authorize('update', $account);
        $account->loadMissing('channel');
        abort_unless($account->channel->code === 'trendyol' && $account->status === ChannelAccountStatus::Active, 404);
    }

    /** @param list<array<string, mixed>> $categories @return list<array{id:int,name:string}> */
    private function flattenCategories(array $categories, string $prefix = ''): array
    {
        $result = [];
        foreach ($categories as $category) {
            $name = trim($prefix.' / '.(string) ($category['name'] ?? ''), ' /');
            $children = is_array($category['subCategories'] ?? null) ? $category['subCategories'] : [];
            if ($children !== []) {
                $result = [...$result, ...$this->flattenCategories($children, $name)];
            } elseif (is_numeric($category['id'] ?? null) && $name !== '') {
                $result[] = ['id' => (int) $category['id'], 'name' => $name];
            }
        }

        return $result;
    }

    public function testConnection(ChannelAccount $account, CreateSyncOperation $createSyncOperation): RedirectResponse
    {
        Gate::authorize('update', $account);
        $account->load('channel');
        abort_unless(in_array($account->channel->code, ['woocommerce', 'trendyol', 'hepsiburada', 'ticimax'], true), 404);
        abort_unless(is_array($account->credentials_encrypted), 422, 'Configure credentials before testing the connection.');

        $operation = $createSyncOperation->execute($account, 'connection_test');
        TestChannelConnectionJob::dispatch($operation->id);

        return redirect()->route('channel-accounts.show', $account)->with('success', 'Connection test queued. The result will appear in synchronization history.');
    }

    public function importProducts(ChannelAccount $account, CreateSyncOperation $createSyncOperation): RedirectResponse
    {
        Gate::authorize('update', $account);
        $account->load('channel');
        abort_unless($account->channel->code === 'woocommerce', 404);
        abort_unless($account->status === ChannelAccountStatus::Active, 422, 'Test and activate the connection before importing products.');
        abort_if($account->syncOperations()->where('operation', 'product_import')->whereIn('status', ['pending', 'running'])->exists(), 409, 'A catalog import is already running.');

        $operation = $createSyncOperation->execute($account, 'product_import', context: ['total_pages' => 1, 'processed_pages' => [], 'imported' => 0, 'failed' => 0, 'skipped' => 0]);
        ImportWooCommerceProductsPageJob::dispatch($operation->id);

        return redirect()->route('channel-accounts.show', $account)->with('success', 'WooCommerce catalog import queued.');
    }

    public function pullOrders(ChannelAccount $account, DispatchWooCommerceOrderPull $dispatch): RedirectResponse
    {
        Gate::authorize('update', $account);
        $account->load('channel');
        abort_unless($account->channel->code === 'woocommerce', 404);
        abort_unless($account->status === ChannelAccountStatus::Active, 422, 'Test and activate the connection before pulling orders.');
        abort_if($account->syncOperations()->where('operation', 'order_pull')->whereIn('status', ['pending', 'running'])->exists(), 409, 'An order pull is already running.');
        $dispatch->execute($account);

        return redirect()->route('channel-accounts.show', $account)->with('success', 'WooCommerce order pull queued.');
    }

    public function pullTrendyolOrders(ChannelAccount $account, DispatchTrendyolOrderPull $dispatch): RedirectResponse
    {
        Gate::authorize('update', $account);
        $account->load('channel');
        abort_unless($account->channel->code === 'trendyol', 404);
        abort_unless($account->status === ChannelAccountStatus::Active, 422, 'Siparişleri çekmeden önce bağlantıyı test edip etkinleştirin.');
        abort_if($account->syncOperations()->where('operation', 'order_pull')->whereIn('status', ['pending', 'running'])->exists(), 409, 'Sipariş aktarımı zaten çalışıyor.');
        $dispatch->execute($account);

        return redirect()->route('channel-accounts.show', $account)->with('success', 'Trendyol sipariş aktarımı kuyruğa alındı.');
    }
}
