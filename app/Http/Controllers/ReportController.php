<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', Order::class);
        $tenant = $context->get();
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'account' => ['nullable', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $from = Carbon::parse($validated['from'] ?? now()->subDays(29)->format('Y-m-d'))->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now()->format('Y-m-d'))->endOfDay();
        abort_if($from->gt($to) || $from->diffInDays($to) > 366, 422, 'Rapor tarih aralığı en fazla 366 gün olabilir.');
        $currency = strtoupper((string) ($validated['currency'] ?? 'TRY'));
        $accountId = $validated['account'] ?? null;
        $tenantId = (string) $tenant->id;

        $orders = $this->orders($tenantId, $from, $to, $currency, $accountId);
        $summary = (clone $orders)->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS sales, COALESCE(SUM(discount_amount), 0) AS discounts, COALESCE(SUM(shipping_amount), 0) AS shipping')->first();
        $itemCount = (int) $this->orderItems($tenantId, $from, $to, $currency, $accountId)->sum('oi.quantity');
        $orderCount = (int) $summary->orders;
        $grossSales = (int) $summary->sales;

        $timezone = (string) config('app.timezone', 'UTC');
        $dailyRows = (clone $orders)
            ->selectRaw('DATE(ordered_at AT TIME ZONE ?) AS report_date, COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS amount', [$timezone])
            ->groupBy('report_date')
            ->get()
            ->keyBy('report_date');
        $daily = collect(range(0, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())))
            ->map(function (int $offset) use ($from, $dailyRows): array {
                $day = $from->copy()->addDays($offset);
                $date = $day->format('Y-m-d');
                $row = $dailyRows->get($date);

                return ['date' => $date, 'label' => $day->format('d.m'), 'orders' => (int) ($row?->orders ?? 0), 'amount' => (int) ($row?->amount ?? 0)];
            });

        $byChannel = $this->orders($tenantId, $from, $to, $currency, $accountId, 'o')
            ->join('channel_accounts as ca', fn ($join) => $join->on('ca.id', '=', 'o.channel_account_id')->on('ca.tenant_id', '=', 'o.tenant_id'))
            ->join('channels as c', 'c.id', '=', 'ca.channel_id')
            ->selectRaw('ca.id, ca.name AS account, c.name AS channel, c.code, COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS amount')
            ->groupBy('ca.id', 'ca.name', 'c.name', 'c.code')
            ->orderByDesc('amount')->get()
            ->map(fn ($row): array => ['account' => $row->account, 'channel' => $row->channel, 'code' => $row->code, 'orders' => (int) $row->orders, 'amount' => (int) $row->amount]);

        $topProducts = $this->orderItems($tenantId, $from, $to, $currency, $accountId)
            ->leftJoin('product_variants as pv', fn ($join) => $join->on('pv.id', '=', 'oi.product_variant_id')->on('pv.tenant_id', '=', 'oi.tenant_id'))
            ->leftJoin('products as p', fn ($join) => $join->on('p.id', '=', 'pv.product_id')->on('p.tenant_id', '=', 'pv.tenant_id'))
            ->selectRaw('COALESCE(MAX(p.name), MAX(oi.name)) AS name, MAX(pv.name) AS variant, SUM(oi.quantity) AS quantity, SUM(oi.total_amount) AS amount')
            ->groupByRaw("COALESCE(oi.product_variant_id::text, 'unmapped:' || oi.name)")
            ->orderByDesc('quantity')->limit(10)->get()
            ->map(fn ($row): array => ['name' => $row->name, 'variant' => $row->variant, 'quantity' => (int) $row->quantity, 'amount' => (int) $row->amount]);

        $statusRows = (clone $orders)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $statusCounts = collect(OrderStatus::cases())->map(fn (OrderStatus $status): array => ['status' => $status->value, 'count' => (int) ($statusRows[$status->value] ?? 0)]);

        $lowStock = DB::table('inventory_items as ii')
            ->join('warehouses as w', fn ($join) => $join->on('w.id', '=', 'ii.warehouse_id')->on('w.tenant_id', '=', 'ii.tenant_id'))
            ->join('product_variants as pv', fn ($join) => $join->on('pv.id', '=', 'ii.product_variant_id')->on('pv.tenant_id', '=', 'ii.tenant_id'))
            ->join('products as p', fn ($join) => $join->on('p.id', '=', 'pv.product_id')->on('p.tenant_id', '=', 'pv.tenant_id'))
            ->where('ii.tenant_id', $tenantId)->where('w.is_active', true)
            ->whereRaw('(ii.quantity - ii.reserved_quantity) <= 5')
            ->selectRaw('p.name AS product, pv.name AS variant, w.name AS warehouse, (ii.quantity - ii.reserved_quantity) AS available')
            ->orderBy('available')->limit(10)->get()
            ->map(fn ($row): array => ['product' => $row->product, 'variant' => $row->variant, 'warehouse' => $row->warehouse, 'available' => (int) $row->available]);

        $liveSync = DB::table('sync_operations')->selectRaw('tenant_id, channel_account_id, status, created_at, 1::bigint as operation_count');
        $archivedSync = DB::table('sync_operation_archives')->selectRaw('tenant_id, channel_account_id, status, bucket_at as created_at, operation_count');
        $sync = DB::query()->fromSub($liveSync->unionAll($archivedSync), 'sync_history')->where('tenant_id', $tenantId)->whereBetween('created_at', [$from, $to])
            ->when($accountId, fn (Builder $query) => $query->where('channel_account_id', $accountId))
            ->selectRaw(
                'COALESCE(SUM(operation_count), 0) AS total, COALESCE(SUM(operation_count) FILTER (WHERE status = ?), 0) AS succeeded, COALESCE(SUM(operation_count) FILTER (WHERE status = ?), 0) AS failed, COALESCE(SUM(operation_count) FILTER (WHERE status IN (?, ?)), 0) AS running',
                [SyncOperationStatus::Succeeded->value, SyncOperationStatus::Failed->value, SyncOperationStatus::Pending->value, SyncOperationStatus::Running->value],
            )->first();

        return Inertia::render('Reports/Index', [
            'filters' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'currency' => $currency, 'account' => $accountId ?? ''],
            'accounts' => $tenant->channelAccounts()->with('channel:id,name')->orderBy('name')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->name]),
            'summary' => ['sales' => $grossSales, 'orders' => $orderCount, 'items' => $itemCount, 'average_order' => $orderCount === 0 ? 0 : (int) round($grossSales / $orderCount), 'discounts' => (int) $summary->discounts, 'shipping' => (int) $summary->shipping],
            'daily' => $daily, 'byChannel' => $byChannel, 'topProducts' => $topProducts, 'statusCounts' => $statusCounts, 'lowStock' => $lowStock,
            'syncSummary' => ['total' => (int) $sync->total, 'succeeded' => (int) $sync->succeeded, 'failed' => (int) $sync->failed, 'running' => (int) $sync->running],
        ]);
    }

    private function orders(string $tenantId, Carbon $from, Carbon $to, string $currency, ?string $accountId, string $alias = ''): Builder
    {
        $table = $alias === '' ? 'orders' : "orders as {$alias}";
        $column = $alias === '' ? '' : "{$alias}.";

        return DB::table($table)->where("{$column}tenant_id", $tenantId)
            ->whereBetween("{$column}ordered_at", [$from, $to])->where("{$column}currency", $currency)
            ->when($accountId, fn (Builder $query) => $query->where("{$column}channel_account_id", $accountId));
    }

    private function orderItems(string $tenantId, Carbon $from, Carbon $to, string $currency, ?string $accountId): Builder
    {
        return DB::table('order_items as oi')
            ->join('orders as o', fn ($join) => $join->on('o.id', '=', 'oi.order_id')->on('o.tenant_id', '=', 'oi.tenant_id'))
            ->where('o.tenant_id', $tenantId)->whereBetween('o.ordered_at', [$from, $to])->where('o.currency', $currency)
            ->when($accountId, fn (Builder $query) => $query->where('o.channel_account_id', $accountId));
    }
}
