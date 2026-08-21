<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Domain\Channels\Actions\ImportWooCommerceProduct;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\WooCommerce\DTO\WooCommerceCredentials;
use App\Models\ChannelAccount;
use Throwable;

final class WooCommerceCatalogImporter
{
    public function __construct(private readonly WooCommerceClient $client, private readonly ImportWooCommerceProduct $importProduct) {}

    public function importPage(ChannelAccount $account, int $page): SyncResult
    {
        try {
            $credentials = WooCommerceCredentials::fromAccount($account);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'WooCommerce credentials have not been configured.', 'credentials_missing');
        }

        if (! isset($account->settings['currency'])) {
            $currency = $this->client->pullCurrentCurrency($credentials);
            if (! $currency['result']->successful || $currency['currency'] === null) {
                return $currency['result']->successful
                    ? SyncResult::failure(SyncErrorCategory::Validation, 'WooCommerce returned an invalid store currency.', 'invalid_currency')
                    : $currency['result'];
            }
            $account->update(['settings' => [...($account->settings ?? []), 'currency' => $currency['currency']]]);
        }

        $remotePage = $this->client->pullProductsPage($credentials, $page);
        if (! $remotePage->result->successful) {
            return $remotePage->result;
        }

        $imported = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($remotePage->products as $payload) {
            if (! in_array($payload['type'] ?? null, ['simple', 'variable'], true)) {
                $skipped++;

                continue;
            }

            $variations = [];
            if (($payload['type'] ?? null) === 'variable') {
                $variationPage = $this->client->pullVariations($credentials, (string) ($payload['id'] ?? ''));
                if (! $variationPage['result']->successful) {
                    if ($variationPage['result']->retryable) {
                        return $variationPage['result'];
                    }
                    $failed++;

                    continue;
                }
                $variations = $variationPage['variations'];
            }

            try {
                $this->importProduct->execute($account, $payload, $variations);
                $imported++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return SyncResult::success(['page' => $page, 'total_pages' => $remotePage->totalPages, 'imported' => $imported, 'failed' => $failed, 'skipped' => $skipped]);
    }
}
