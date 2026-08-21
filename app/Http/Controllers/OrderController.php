<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\Actions\QueueOrderStatusUpdate;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Support\OrderStatusCapabilities;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Jobs\RefreshOrderFromChannelJob;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', Order::class);
        $tenant = $context->get();
        $filters = $request->validate([
            'channel_account' => ['nullable', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $tenant->id)],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $base = Order::query()->where('tenant_id', $tenant->id);
        $counts = (clone $base)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $orders = $base->with('account.channel:id,name,code')
            ->when($filters['channel_account'] ?? null, fn ($query, $account) => $query->where('channel_account_id', $account))
            ->when($filters['status'] ?? null, fn ($query, $status) => $status === OrderStatus::Cancelled->value
                ? $query->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Returned])
                : $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('external_order_number', 'ilike', "%{$search}%")
                    ->orWhere('external_order_id', 'ilike', "%{$search}%")
                    ->orWhereRaw("customer_snapshot->>'name' ILIKE ?", ["%{$search}%"]);
            }))
            ->latest('ordered_at')->paginate(25)->withQueryString()->through(fn (Order $order): array => [
                ...$order->only(['id', 'external_order_id', 'external_order_number', 'status', 'currency', 'total_amount']),
                'customer_name' => (string) data_get($order->customer_snapshot, 'name', 'Unknown customer'),
                'channel' => ['account_name' => $order->account->name, 'name' => $order->account->channel->name, 'code' => $order->account->channel->code],
                'ordered_at' => $order->ordered_at->toIso8601String(),
            ]);

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'accounts' => $tenant->channelAccounts()->with('channel:id,name')->orderBy('name')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->name]),
            'statuses' => array_column(OrderStatus::cases(), 'value'),
            'counts' => collect(OrderStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => (int) ($counts[$status->value] ?? 0)]),
            'filters' => ['channel_account' => $filters['channel_account'] ?? '', 'status' => $filters['status'] ?? '', 'search' => $search],
        ]);
    }

    public function show(Order $order, OrderStatusCapabilities $capabilities): Response
    {
        Gate::authorize('view', $order);
        $order->load(['account.channel:id,name,code', 'items.variant.product']);
        $previousId = Order::query()->where('tenant_id', $order->tenant_id)->where('ordered_at', '<', $order->ordered_at)->latest('ordered_at')->value('id');
        $nextId = Order::query()->where('tenant_id', $order->tenant_id)->where('ordered_at', '>', $order->ordered_at)->oldest('ordered_at')->value('id');

        return Inertia::render('Orders/Show', [
            'order' => [
                ...$order->only(['id', 'external_order_id', 'external_order_number', 'status', 'external_status', 'currency', 'subtotal_amount', 'discount_amount', 'shipping_amount', 'tax_amount', 'total_amount']),
                'channel' => ['account_id' => $order->account->id, 'account_name' => $order->account->name, 'name' => $order->account->channel->name, 'code' => $order->account->channel->code],
                'customer' => $this->snapshot($order->customer_snapshot, ['name', 'email', 'phone']),
                'shipping_address' => $this->snapshot($order->shipping_address_snapshot, ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone']),
                'billing_address' => $this->snapshot($order->billing_address_snapshot ?? [], ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone']),
                'ordered_at' => $order->ordered_at->toIso8601String(),
                'imported_at' => $order->imported_at->toIso8601String(),
                'items' => $order->items->map(fn ($item): array => [
                    ...$item->only(['id', 'name', 'quantity', 'unit_price_amount', 'discount_amount', 'tax_amount', 'total_amount', 'mapping_status', 'external_product_id', 'external_variant_id', 'external_sku', 'external_barcode']),
                    'variant' => $item->variant ? ['id' => $item->variant->id, 'name' => $item->variant->name, 'product_name' => $item->variant->product->name] : null,
                ])->all(),
            ],
            'navigation' => ['previous_id' => $previousId, 'next_id' => $nextId],
            'canSync' => Gate::allows('update', $order->account),
            'canUpdateStatus' => Gate::allows('update', $order),
            'availableStatuses' => $capabilities->available($order->account, $order->status),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order, QueueOrderStatusUpdate $queue): RedirectResponse
    {
        $queue->execute($order, OrderStatus::from($request->string('status')->toString()));

        return back()->with('success', 'Durum güncellemesi kuyruğa alındı. Pazaryeri onayladığında sipariş otomatik güncellenecek.');
    }

    public function refresh(Order $order, CreateSyncOperation $createSyncOperation): RedirectResponse
    {
        Gate::authorize('update', $order);
        $order->loadMissing('account.channel');
        abort_unless($order->account->channel->code === 'woocommerce', 422, 'Bu kanal tekil sipariş yenilemeyi desteklemiyor.');
        abort_if($order->account->syncOperations()->where('operation', 'order_refresh')->where('entity_type', $order->getMorphClass())->where('entity_id', $order->id)->whereIn('status', ['pending', 'running'])->exists(), 409, 'Sipariş yenilemesi zaten çalışıyor.');
        $operation = $createSyncOperation->execute($order->account, 'order_refresh', $order);
        RefreshOrderFromChannelJob::dispatch($operation->id);

        return back()->with('success', 'Sipariş WooCommerce mağazasından yenilenmek üzere kuyruğa alındı.');
    }

    /** @param array<string, mixed> $snapshot @param list<string> $keys @return array<string, string> */
    private function snapshot(array $snapshot, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => is_scalar($snapshot[$key] ?? null) ? (string) $snapshot[$key] : ''])->filter()->all();
    }
}
