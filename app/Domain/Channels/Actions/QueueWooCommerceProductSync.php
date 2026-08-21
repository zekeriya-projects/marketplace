<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Channels\Enums\PublicationStatus;
use App\Domain\Channels\Results\PublicationResult;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Jobs\PublishWooCommerceProductJob;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;

final class QueueWooCommerceProductSync
{
    public function __construct(private readonly CreateSyncOperation $operations) {}

    public function execute(Product $product): int
    {
        if ($product->disable_external_sync) {
            return 0;
        }

        $product->loadMissing('variants');
        $queued = 0;

        ChannelAccount::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('status', ChannelAccountStatus::Active)
            ->whereHas('channel', fn ($query) => $query->where('code', 'woocommerce'))
            ->each(function (ChannelAccount $account) use ($product, &$queued): void {
                $queued += $this->executeForAccount($product, $account)->queued() ? 1 : 0;
            });

        return $queued;
    }

    public function executeForAccount(Product $product, ChannelAccount $account): PublicationResult
    {
        if ($product->disable_external_sync) {
            return new PublicationResult(PublicationStatus::Skipped);
        }
        if ($account->tenant_id !== $product->tenant_id || $account->status !== ChannelAccountStatus::Active || $account->channel()->where('code', 'woocommerce')->doesntExist()) {
            return new PublicationResult(PublicationStatus::Invalid);
        }
        $product->loadMissing('variants');
        $creation = $this->operations->executeUnique($account, 'product_publish', $product);
        if (! $creation->created) {
            return new PublicationResult(PublicationStatus::AlreadyActive);
        }
        foreach ($product->variants as $variant) {
            ChannelListing::query()->updateOrCreate(
                ['channel_account_id' => $account->id, 'product_variant_id' => $variant->id],
                ['tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'external_sku' => $variant->sku, 'external_barcode' => $variant->barcode, 'status' => ChannelListingStatus::Pending, 'channel_price_amount' => $variant->base_price_amount, 'currency' => $variant->currency],
            );
        }
        PublishWooCommerceProductJob::dispatch($creation->operation->id);

        return new PublicationResult(PublicationStatus::Queued);
    }
}
