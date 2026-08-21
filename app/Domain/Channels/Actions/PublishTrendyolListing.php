<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Channels\Enums\PublicationStatus;
use App\Domain\Channels\Results\PublicationResult;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Jobs\PublishTrendyolListingJob;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

final class PublishTrendyolListing
{
    public function __construct(private readonly CreateSyncOperation $operations) {}

    /** @param array<string,mixed> $data */
    public function execute(ChannelAccount $account, array $data): ChannelListing
    {
        return $this->queue($account, $data)->listing ?? throw ValidationException::withMessages(['variant_id' => 'Trendyol yayını hazırlanamadı.']);
    }

    /** @param array<string,mixed> $data */
    public function queue(ChannelAccount $account, array $data): PublicationResult
    {
        if ($account->status !== ChannelAccountStatus::Active || $account->channel()->where('code', 'trendyol')->doesntExist()) {
            throw ValidationException::withMessages(['account' => 'Aktif bir Trendyol bağlantısı gereklidir.']);
        }
        $variant = ProductVariant::query()->where('tenant_id', $account->tenant_id)->with('product')->findOrFail($data['variant_id']);
        if ($variant->currency !== 'TRY' || $variant->barcode === null || $variant->sku === null) {
            throw ValidationException::withMessages(['variant_id' => 'Yayınlama için TRY fiyatı, SKU ve barkod gereklidir.']);
        }
        $listing = ChannelListing::query()->updateOrCreate(['channel_account_id' => $account->id, 'product_variant_id' => $variant->id], ['tenant_id' => $account->tenant_id, 'product_id' => $variant->product_id, 'status' => ChannelListingStatus::Pending, 'external_sku' => $variant->sku, 'external_barcode' => $variant->barcode, 'channel_price_amount' => $variant->base_price_amount, 'currency' => 'TRY', 'metadata' => collect($data)->except('variant_id')->all()]);
        $creation = $this->operations->executeUnique($account, 'listing_publish', $listing);
        if (! $creation->created) {
            return new PublicationResult(PublicationStatus::AlreadyActive, $listing);
        }
        PublishTrendyolListingJob::dispatch($creation->operation->id);

        return new PublicationResult(PublicationStatus::Queued, $listing);
    }
}
