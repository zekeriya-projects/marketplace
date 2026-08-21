<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Models\ChannelAccount;
use App\Models\Product;
use App\Models\TrendyolListingTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class BulkPublishTrendyolProducts
{
    public function __construct(private readonly PublishTrendyolListing $publish) {}

    /**
     * @param  Collection<int, Product>  $products
     * @return array{queued:int,already_linked:int,blocked:int,succeeded:int,failed:int}
     */
    public function execute(ChannelAccount $account, TrendyolListingTemplate $template, Collection $products): array
    {
        abort_unless($account->tenant_id === $template->tenant_id && $account->channel->code === 'trendyol', 404);
        $result = ['queued' => 0, 'already_linked' => 0, 'blocked' => 0, 'succeeded' => 0, 'failed' => 0];

        foreach ($products as $product) {
            if ($product->tenant_id !== $template->tenant_id || ($template->category_id !== null && $product->category_id !== $template->category_id)) {
                $result['blocked']++;

                continue;
            }

            $missing = $product->variants->filter(fn ($variant) => ! $product->channelListings->contains(fn ($listing) => $listing->channel_account_id === $account->id && $listing->product_variant_id === $variant->id));
            if ($missing->isEmpty()) {
                $result['already_linked']++;
                $result['succeeded'] += $product->channelListings->where('channel_account_id', $account->id)->contains(fn ($listing) => $listing->status->value === 'active') ? 1 : 0;

                continue;
            }

            $imageUrl = $template->image_url ?? $this->imageUrl($product);
            $ready = $imageUrl !== null && str_starts_with($imageUrl, 'https://') && $missing->every(fn ($variant) => $variant->currency === 'TRY' && filled($variant->sku) && filled($variant->barcode));
            if (! $ready || ! $this->hasRequiredAttributes($template)) {
                $result['blocked']++;

                continue;
            }

            foreach ($missing as $variant) {
                $outcome = $this->publish->queue($account, [
                    'variant_id' => $variant->id,
                    'brand_id' => $template->trendyol_brand_id,
                    'category_id' => $template->trendyol_category_id,
                    'image_url' => $imageUrl,
                    'vat_rate' => $template->vat_rate,
                    'dimensional_weight' => $template->dimensional_weight,
                    'origin' => $template->origin,
                    'attributes' => $template->attributes,
                    'template_id' => $template->id,
                ]);
                if (! $outcome->queued()) {
                    $result['already_linked']++;
                }
            }
            $result['queued']++;
        }

        return $result;
    }

    private function imageUrl(Product $product): ?string
    {
        $image = $product->images->first();

        return $image === null ? null : Storage::disk($image->disk)->url($image->path);
    }

    private function hasRequiredAttributes(TrendyolListingTemplate $template): bool
    {
        $selected = collect($template->attributes)->pluck('attributeId')->map(fn ($id) => (int) $id);

        return collect($template->required_attribute_ids)->every(fn ($id) => $selected->contains((int) $id));
    }
}
