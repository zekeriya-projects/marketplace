<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\SyncVariantInventoryJob;
use App\Jobs\SyncVariantPriceJob;
use App\Models\ChannelAccount;
use App\Models\SyncOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SyncOperationController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', ChannelAccount::class);
        $filters = $request->validate([
            'period' => ['nullable', 'in:1,7,30,90,all'],
            'status' => ['nullable', 'in:all,running,succeeded,failed'],
            'operation' => ['nullable', 'string', 'max:50'],
            'account' => ['nullable', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $context->get()->getKey())],
            'detail_operation' => ['nullable', 'string', 'max:50'],
            'detail_account' => ['nullable', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $context->get()->getKey())],
            'detail_bucket' => ['nullable', 'date_format:Y-m-d H:i:00'],
        ]);
        $period = $filters['period'] ?? '7';
        $status = $filters['status'] ?? 'all';
        $tenantId = $context->get()->getKey();
        $from = $period === 'all' ? null : now()->subDays(((int) $period) - 1)->startOfDay();
        $live = DB::table('sync_operations')->selectRaw('tenant_id, channel_account_id, operation, status, created_at, 1::bigint as operation_count, safe_error_message');
        $archived = DB::table('sync_operation_archives')->selectRaw('tenant_id, channel_account_id, operation, status, bucket_at as created_at, operation_count, latest_error as safe_error_message');
        $base = DB::query()->fromSub((clone $live)->unionAll(clone $archived), 'so')->where('so.tenant_id', $tenantId)
            ->when($from, fn ($query) => $query->where('so.created_at', '>=', $from))
            ->when($filters['account'] ?? null, fn ($query, $account) => $query->where('so.channel_account_id', $account))
            ->when($filters['operation'] ?? null, fn ($query, $operation) => $query->where('so.operation', $operation));
        $summary = [
            'events' => (int) (clone $base)->sum('so.operation_count'),
            'running' => (int) (clone $base)->whereIn('so.status', [SyncOperationStatus::Pending->value, SyncOperationStatus::Running->value])->sum('so.operation_count'),
            'succeeded' => (int) (clone $base)->where('so.status', SyncOperationStatus::Succeeded->value)->sum('so.operation_count'),
            'failed' => (int) (clone $base)->where('so.status', SyncOperationStatus::Failed->value)->sum('so.operation_count'),
        ];
        $bucket = "date_trunc('minute', so.created_at)";
        $groups = (clone $base)->join('channel_accounts as ca', 'ca.id', '=', 'so.channel_account_id')->join('channels as c', 'c.id', '=', 'ca.channel_id')
            ->when($status === 'failed', fn ($query) => $query->where('so.status', 'failed'))
            ->when($status === 'succeeded', fn ($query) => $query->where('so.status', 'succeeded'))
            ->when($status === 'running', fn ($query) => $query->whereIn('so.status', ['pending', 'running']))
            ->selectRaw("so.operation, so.channel_account_id, ca.name as account_name, c.name as channel_name, c.code as channel_code, {$bucket} as bucket, sum(so.operation_count) as total_count, sum(so.operation_count) filter (where so.status = 'succeeded') as succeeded_count, sum(so.operation_count) filter (where so.status = 'failed') as failed_count, sum(so.operation_count) filter (where so.status in ('pending','running')) as running_count, max(so.created_at) as latest_at, max(so.safe_error_message) filter (where so.status = 'failed') as latest_error")
            ->groupByRaw("so.operation, so.channel_account_id, ca.name, c.name, c.code, {$bucket}")
            ->orderByRaw('max(so.created_at) desc')->simplePaginate(25)->withQueryString();
        $groups->through(function ($group) {
            foreach (['total_count', 'succeeded_count', 'failed_count', 'running_count'] as $field) {
                $group->{$field} = (int) ($group->{$field} ?? 0);
            }

            return $group;
        });

        $details = [];
        if (isset($filters['detail_operation'], $filters['detail_account'], $filters['detail_bucket'])) {
            $detailFrom = Carbon::createFromFormat('Y-m-d H:i:00', $filters['detail_bucket'])->startOfMinute();
            $details = SyncOperation::query()->where('tenant_id', $tenantId)->where('channel_account_id', $filters['detail_account'])->where('operation', $filters['detail_operation'])->whereBetween('created_at', [$detailFrom, $detailFrom->copy()->endOfMinute()])->latest()->limit(50)->get()->map(fn (SyncOperation $operation): array => [
                ...$operation->only(['id', 'status', 'attempt', 'error_category', 'error_code', 'safe_error_message']),
                'created_at' => $operation->created_at->toIso8601String(),
                'finished_at' => $operation->finished_at?->toIso8601String(),
                'can_retry' => $operation->status === SyncOperationStatus::Failed && in_array($operation->operation, ['inventory_push', 'price_push'], true) && Gate::allows('update', $operation->account),
            ]);
        }

        return Inertia::render('Sync/Index', [
            'groups' => $groups,
            'details' => $details,
            'summary' => $summary,
            'filters' => ['period' => $period, 'status' => $status, 'operation' => $filters['operation'] ?? '', 'account' => $filters['account'] ?? '', 'detail_operation' => $filters['detail_operation'] ?? '', 'detail_account' => $filters['detail_account'] ?? '', 'detail_bucket' => $filters['detail_bucket'] ?? ''],
            'accounts' => $context->get()->channelAccounts()->with('channel:id,name')->orderBy('name')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->name]),
            'operations' => DB::query()->fromSub((clone $live)->unionAll(clone $archived), 'operations')->where('tenant_id', $tenantId)->distinct()->orderBy('operation')->pluck('operation'),
        ]);
    }

    public function retry(SyncOperation $operation, TenantContext $context, CreateSyncOperation $create): RedirectResponse
    {
        abort_unless($operation->tenant_id === $context->get()->getKey(), 403);
        $operation->load('account');
        Gate::authorize('update', $operation->account);
        abort_unless($operation->status === SyncOperationStatus::Failed && in_array($operation->operation, ['inventory_push', 'price_push'], true), 422);
        abort_unless($operation->entity !== null, 422);
        abort_if($operation->account->syncOperations()->where('operation', $operation->operation)->where('entity_type', $operation->entity_type)->where('entity_id', $operation->entity_id)->whereIn('status', ['pending', 'running'])->exists(), 409);

        $retry = $create->execute($operation->account, $operation->operation, $operation->entity, ['retry_of' => $operation->id]);
        ($operation->operation === 'inventory_push' ? SyncVariantInventoryJob::class : SyncVariantPriceJob::class)::dispatch($retry->id);

        return redirect()->route('sync.index')->with('success', 'Synchronization retry queued.');
    }
}
