<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Order;
use App\Models\SyncOperation;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $tenant = $context->get();
        $orders = Order::query()->where('tenant_id', $tenant->id)->where('ordered_at', '>=', now()->subDays(6)->startOfDay())->get(['ordered_at', 'total_amount']);

        return Inertia::render('Dashboard', [
            'tenant' => $tenant->only(['id', 'name', 'slug']),
            'stats' => [
                'today_orders' => $orders->where('ordered_at', '>=', now()->startOfDay())->count(),
                'products' => $tenant->products()->count(),
                'active_channels' => $tenant->channelAccounts()->where('status', 'active')->count(),
                'failed_operations' => SyncOperation::query()->where('tenant_id', $tenant->id)->where('status', SyncOperationStatus::Failed)->count(),
            ],
            'weeklySales' => collect(range(6, 0))->map(function (int $daysAgo) use ($orders): array {
                $day = now()->subDays($daysAgo);

                return ['label' => $day->translatedFormat('D'), 'date' => $day->format('d.m'), 'amount' => $orders->filter(fn (Order $order): bool => Carbon::parse($order->ordered_at)->isSameDay($day))->sum('total_amount')];
            }),
            'channels' => $tenant->channelAccounts()->with('channel:id,code,name')->latest()->limit(4)->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'provider' => $account->channel->name, 'code' => $account->channel->code, 'status' => $account->status]),
            'recentOperations' => SyncOperation::query()->where('tenant_id', $tenant->id)->with('account.channel:id,name')->latest()->limit(5)->get()->map(fn (SyncOperation $operation): array => ['id' => $operation->id, 'operation' => $operation->operation, 'status' => $operation->status, 'account' => $operation->account->name, 'channel' => $operation->account->channel->name, 'created_at' => $operation->created_at]),
        ]);
    }
}
