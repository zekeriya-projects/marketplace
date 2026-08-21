<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Sync\Results\SyncOperationCreation;
use App\Models\ChannelAccount;
use App\Models\SyncOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateSyncOperation
{
    /** @param array<string, mixed> $context */
    public function execute(ChannelAccount $account, string $operation, ?Model $entity = null, array $context = []): SyncOperation
    {
        if ($entity !== null && $entity->getAttribute('tenant_id') !== $account->tenant_id) {
            throw new InvalidArgumentException('The sync entity must belong to the channel account tenant.');
        }

        return $account->syncOperations()->create([
            'tenant_id' => $account->tenant_id,
            'operation' => $operation,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'context' => $context,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function executeUnique(ChannelAccount $account, string $operation, Model $entity, array $context = []): SyncOperationCreation
    {
        $active = $this->active($account, $operation, $entity);
        if ($active !== null) {
            return new SyncOperationCreation($active, false);
        }

        try {
            $created = DB::transaction(fn () => $this->execute($account, $operation, $entity, $context));

            return new SyncOperationCreation($created, true);
        } catch (QueryException $exception) {
            $active = $this->active($account, $operation, $entity);
            if ($exception->getCode() === '23505' && $active !== null) {
                return new SyncOperationCreation($active, false);
            }

            throw $exception;
        }
    }

    private function active(ChannelAccount $account, string $operation, Model $entity): ?SyncOperation
    {
        return $account->syncOperations()->where('operation', $operation)
            ->where('entity_type', $entity->getMorphClass())->where('entity_id', $entity->getKey())
            ->whereIn('status', ['pending', 'running'])->first();
    }
}
