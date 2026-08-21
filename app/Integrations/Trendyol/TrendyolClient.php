<?php

declare(strict_types=1);

namespace App\Integrations\Trendyol;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Integrations\Trendyol\DTO\TrendyolOrderPage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class TrendyolClient
{
    /** @return array{result: SyncResult, categories: list<array<string, mixed>>} */
    public function categories(TrendyolCredentials $credentials): array
    {
        try {
            $response = $this->request($credentials)->get('/integration/product/product-categories');
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'Trendyol kategorileri okunamadı.', 'connection_failed', retryable: true), 'categories' => []];
        }

        return ['result' => $this->translate($response->status()), 'categories' => $response->successful() && is_array($response->json('categories')) ? $response->json('categories') : []];
    }

    /** @return array{result: SyncResult, attributes: list<array<string, mixed>>} */
    public function categoryAttributes(TrendyolCredentials $credentials, int $categoryId): array
    {
        try {
            $response = $this->request($credentials)->get("/integration/product/categories/{$categoryId}/attributes");
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'Trendyol kategori özellikleri okunamadı.', 'connection_failed', retryable: true), 'attributes' => []];
        }
        $payload = $response->json();
        $attributes = is_array($payload) ? ($payload['categoryAttributes'] ?? $payload['attributes'] ?? []) : [];

        return ['result' => $this->translate($response->status()), 'attributes' => is_array($attributes) ? $attributes : []];
    }

    /** @return array{result: SyncResult, brands: list<array<string, mixed>>} */
    public function brands(TrendyolCredentials $credentials, string $name): array
    {
        try {
            $response = $this->request($credentials)->get('/integration/product/brands/by-name', ['name' => $name]);
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'Trendyol markaları okunamadı.', 'connection_failed', retryable: true), 'brands' => []];
        }
        $brands = $response->successful() && is_array($response->json()) ? ($response->json('brands') ?? $response->json()) : [];

        return ['result' => $this->translate($response->status()), 'brands' => is_array($brands) ? $brands : []];
    }

    /** @return array{result: SyncResult, products: list<array<string, mixed>>} */
    public function searchProducts(TrendyolCredentials $credentials, string $search, int $page = 0): array
    {
        $query = ['page' => max(0, $page), 'size' => 20];
        if ($search !== '') {
            $query[preg_match('/^\d{8,14}$/', $search) === 1 ? 'barcode' : 'productMainId'] = $search;
        }
        try {
            $response = $this->request($credentials)->get("/integration/product/sellers/{$credentials->sellerId}/products", $query);
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'Trendyol ürünlerine ulaşılamadı.', 'connection_failed', retryable: true), 'products' => []];
        }
        $payload = $response->json('content');

        return ['result' => $this->translate($response->status()), 'products' => $response->successful() && is_array($payload) ? $payload : []];
    }

    /** @param array<string, mixed> $payload @return array{result: SyncResult, batch_id: ?string} */
    public function createProducts(TrendyolCredentials $credentials, array $payload): array
    {
        try {
            $response = $this->request($credentials)->post("/integration/product/sellers/{$credentials->sellerId}/v2/products", $payload);
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'Trendyol ürün servisine ulaşılamadı.', 'connection_failed', retryable: true), 'batch_id' => null];
        }
        $result = $this->translate($response->status());
        $batchId = $response->successful() ? $response->json('batchRequestId') : null;
        if ($result->successful && ! is_string($batchId)) {
            $result = SyncResult::failure(SyncErrorCategory::Unknown, 'Trendyol geçerli bir işlem kimliği döndürmedi.', 'batch_id_missing');
        }

        return ['result' => $result, 'batch_id' => is_string($batchId) ? $batchId : null];
    }

    /** @return array{result: SyncResult, payload: array<string, mixed>} */
    public function batchResult(TrendyolCredentials $credentials, string $batchId): array
    {
        try {
            $response = $this->request($credentials)->get("/integration/product/sellers/{$credentials->sellerId}/products/batch-requests/{$batchId}");
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'Trendyol işlem sonucu okunamadı.', 'connection_failed', retryable: true), 'payload' => []];
        }

        return ['result' => $this->translate($response->status()), 'payload' => $response->successful() && is_array($response->json()) ? $response->json() : []];
    }

    /** @param array<string, mixed> $item */
    public function updatePriceAndInventory(TrendyolCredentials $credentials, array $item): SyncResult
    {
        try {
            $response = $this->request($credentials)->post("/integration/inventory/sellers/{$credentials->sellerId}/products/price-and-inventory", ['items' => [$item]]);
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Trendyol stok ve fiyat servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }

        $result = $this->translate($response->status());
        if (! $result->successful) {
            return $result;
        }
        $batchId = $response->json('batchRequestId');

        return is_string($batchId) && $batchId !== ''
            ? SyncResult::success(['batch_request_id' => $batchId, 'awaiting_batch' => true])
            : SyncResult::failure(SyncErrorCategory::Unknown, 'Trendyol geçerli bir işlem kimliği döndürmedi.', 'batch_id_missing');
    }

    public function pullOrders(TrendyolCredentials $credentials, int $fromMilliseconds, int $toMilliseconds, ?string $cursor = null): TrendyolOrderPage
    {
        $query = ['size' => 200, 'lastModifiedStartDate' => $fromMilliseconds, 'lastModifiedEndDate' => $toMilliseconds];
        if ($cursor !== null && $cursor !== '') {
            $query['nextCursor'] = $cursor;
        }
        try {
            $response = $this->request($credentials)->get("/integration/order/sellers/{$credentials->sellerId}/orders/stream", $query);
        } catch (ConnectionException) {
            return new TrendyolOrderPage(SyncResult::failure(SyncErrorCategory::Network, 'Trendyol sipariş servisine ulaşılamadı.', 'connection_failed', retryable: true));
        }
        $result = $this->translate($response->status());
        $orders = $response->successful() && is_array($response->json('content')) ? $response->json('content') : [];
        $nextCursor = $response->successful() ? $response->json('nextCursor') : null;

        return new TrendyolOrderPage($result, $orders, is_string($nextCursor) && $nextCursor !== '' ? $nextCursor : null);
    }

    /** @param list<array{lineId: int, quantity: int}> $lines */
    public function updatePackageStatus(TrendyolCredentials $credentials, string $packageId, string $status, array $lines): SyncResult
    {
        try {
            $response = $this->request($credentials)->put("/integration/order/sellers/{$credentials->sellerId}/shipment-packages/{$packageId}", ['status' => $status, 'lines' => $lines]);
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Trendyol sipariş servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }

        return $this->translate($response->status());
    }

    public function testConnection(TrendyolCredentials $credentials): SyncResult
    {
        $baseUrl = config("services.trendyol.endpoints.{$credentials->environment}");
        if (! is_string($baseUrl)) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'The Trendyol environment is invalid.', 'invalid_environment');
        }

        try {
            $response = Http::baseUrl($baseUrl)->acceptJson()
                ->withBasicAuth($credentials->apiKey, $credentials->apiSecret)
                ->withHeaders(['User-Agent' => "{$credentials->sellerId} - MarketplaceSaaS", 'storeFrontCode' => 'TR'])
                ->connectTimeout(5)->timeout(15)
                ->get("/integration/sellers/{$credentials->sellerId}/addresses");
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Trendyol could not be reached.', 'connection_failed', retryable: true);
        }

        return match (true) {
            $response->successful() => SyncResult::success(),
            in_array($response->status(), [401, 403], true) => SyncResult::failure(SyncErrorCategory::Authentication, 'Trendyol rejected the API credentials, seller ID, or request permissions.', 'authentication_failed'),
            $response->status() === 404 => SyncResult::failure(SyncErrorCategory::NotFound, 'The Trendyol seller account or address service was not found.', 'seller_not_found'),
            $response->status() === 429 => SyncResult::failure(SyncErrorCategory::RateLimited, 'Trendyol temporarily rate limited the request.', 'rate_limited', retryable: true),
            $response->serverError() => SyncResult::failure(SyncErrorCategory::RemoteServer, 'Trendyol returned a temporary server error.', 'remote_server_error', retryable: true),
            default => SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol rejected the connection request.', 'request_rejected'),
        };
    }

    private function request(TrendyolCredentials $credentials): PendingRequest
    {
        return Http::baseUrl((string) config("services.trendyol.endpoints.{$credentials->environment}"))->acceptJson()->withBasicAuth($credentials->apiKey, $credentials->apiSecret)->withHeaders(['User-Agent' => "{$credentials->sellerId} - MarketplaceSaaS", 'storeFrontCode' => 'TR'])->connectTimeout(5)->timeout(30);
    }

    private function translate(int $status): SyncResult
    {
        return match (true) {
            $status >= 200 && $status < 300 => SyncResult::success(),
            in_array($status, [401, 403], true) => SyncResult::failure(SyncErrorCategory::Authentication, 'Trendyol kimlik bilgilerini veya yetkileri reddetti.', 'authentication_failed'),
            $status === 404 => SyncResult::failure(SyncErrorCategory::NotFound, 'Trendyol kaynağı bulunamadı.', 'not_found'),
            $status === 429 => SyncResult::failure(SyncErrorCategory::RateLimited, 'Trendyol isteği geçici olarak sınırlandırdı.', 'rate_limited', retryable: true),
            $status >= 500 => SyncResult::failure(SyncErrorCategory::RemoteServer, 'Trendyol geçici bir sunucu hatası döndürdü.', 'remote_server_error', retryable: true),
            default => SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol isteği reddetti.', 'request_rejected'),
        };
    }
}
