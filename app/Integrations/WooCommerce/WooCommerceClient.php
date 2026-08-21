<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\WooCommerce\DTO\WooCommerceCredentials;
use App\Integrations\WooCommerce\DTO\WooCommerceOrderPage;
use App\Integrations\WooCommerce\DTO\WooCommerceProductPage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

final class WooCommerceClient
{
    public function __construct(private readonly WooCommerceUrlGuard $urlGuard) {}

    public function testConnection(WooCommerceCredentials $credentials): SyncResult
    {
        try {
            $response = $this->request($credentials)
                ->connectTimeout(5)
                ->timeout(15)
                ->get('/data');
        } catch (InvalidArgumentException $exception) {
            return SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url');
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'The WooCommerce store could not be reached.', 'connection_failed', retryable: true);
        }

        return match (true) {
            $response->successful() => SyncResult::success(),
            in_array($response->status(), [401, 403], true) => SyncResult::failure(SyncErrorCategory::Authentication, 'WooCommerce rejected the API credentials or permissions.', 'authentication_failed'),
            $response->status() === 404 => SyncResult::failure(SyncErrorCategory::NotFound, 'WooCommerce REST API v3 was not found at this store URL.', 'api_not_found'),
            $response->status() === 429 => SyncResult::failure(SyncErrorCategory::RateLimited, 'WooCommerce temporarily rate limited the connection test.', 'rate_limited', retryable: true),
            $response->serverError() => SyncResult::failure(SyncErrorCategory::RemoteServer, 'The WooCommerce store returned a temporary server error.', 'remote_server_error', retryable: true),
            default => SyncResult::failure(SyncErrorCategory::Unknown, 'WooCommerce returned an unexpected response.', 'unexpected_response'),
        };
    }

    public function pullProductsPage(WooCommerceCredentials $credentials, int $page, int $perPage = 50): WooCommerceProductPage
    {
        try {
            $response = $this->request($credentials)->get('/products', ['page' => $page, 'per_page' => $perPage, 'orderby' => 'id', 'order' => 'asc']);
        } catch (InvalidArgumentException $exception) {
            return new WooCommerceProductPage(SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'));
        } catch (ConnectionException) {
            return new WooCommerceProductPage(SyncResult::failure(SyncErrorCategory::Network, 'The WooCommerce store could not be reached.', 'connection_failed', retryable: true));
        }

        $result = $this->responseResult($response->status());
        $products = $response->successful() && is_array($response->json()) ? $response->json() : [];

        return new WooCommerceProductPage($result, $products, max(1, (int) $response->header('X-WP-TotalPages', '1')));
    }

    /** @return array{result: SyncResult, products: list<array<string, mixed>>} */
    public function searchProducts(WooCommerceCredentials $credentials, string $search, int $page = 1): array
    {
        try {
            $query = ['page' => max(1, $page), 'per_page' => 20, 'orderby' => 'date', 'order' => 'desc'];
            if ($search !== '') {
                $query['search'] = $search;
            }
            $response = $this->request($credentials)->get('/products', $query);
        } catch (InvalidArgumentException $exception) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'), 'products' => []];
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'WooCommerce ürünlerine ulaşılamadı.', 'connection_failed', retryable: true), 'products' => []];
        }

        return ['result' => $this->responseResult($response->status()), 'products' => $response->successful() && is_array($response->json()) ? $response->json() : []];
    }

    public function pullOrdersPage(WooCommerceCredentials $credentials, int $page, string $modifiedAfter, string $modifiedBefore, int $perPage = 50): WooCommerceOrderPage
    {
        try {
            $response = $this->request($credentials)->get('/orders', [
                'page' => $page, 'per_page' => $perPage, 'orderby' => 'modified', 'order' => 'asc',
                'modified_after' => $modifiedAfter, 'modified_before' => $modifiedBefore, 'dates_are_gmt' => 'true',
            ]);
        } catch (InvalidArgumentException $exception) {
            return new WooCommerceOrderPage(SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'));
        } catch (ConnectionException) {
            return new WooCommerceOrderPage(SyncResult::failure(SyncErrorCategory::Network, 'WooCommerce orders could not be reached.', 'connection_failed', retryable: true));
        }

        $orders = $response->successful() && is_array($response->json()) ? $response->json() : [];

        return new WooCommerceOrderPage($this->responseResult($response->status()), $orders, max(1, (int) $response->header('X-WP-TotalPages', '1')));
    }

    /** @return array{result: SyncResult, order: array<string, mixed>} */
    public function pullOrder(WooCommerceCredentials $credentials, string $orderId): array
    {
        try {
            $response = $this->request($credentials)->get("/orders/{$orderId}");
        } catch (InvalidArgumentException $exception) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'), 'order' => []];
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'WooCommerce sipariş servisine ulaşılamadı.', 'connection_failed', retryable: true), 'order' => []];
        }

        return ['result' => $this->responseResult($response->status()), 'order' => $response->successful() && is_array($response->json()) ? $response->json() : []];
    }

    /** @return array{result: SyncResult, currency: ?string} */
    public function pullCurrentCurrency(WooCommerceCredentials $credentials): array
    {
        try {
            $response = $this->request($credentials)->get('/data/currencies/current');
        } catch (InvalidArgumentException $exception) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'), 'currency' => null];
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'WooCommerce currency could not be read.', 'connection_failed', retryable: true), 'currency' => null];
        }
        $result = $this->responseResult($response->status());
        $code = $response->successful() ? strtoupper((string) $response->json('code')) : null;

        return ['result' => $result, 'currency' => is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null];
    }

    /** @param array<string, mixed> $payload */
    public function updateProduct(WooCommerceCredentials $credentials, string $productId, ?string $variationId, array $payload): SyncResult
    {
        try {
            $endpoint = $variationId === null ? "/products/{$productId}" : "/products/{$productId}/variations/{$variationId}";
            $response = $this->request($credentials)->put($endpoint, $payload);
        } catch (InvalidArgumentException $exception) {
            return SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url');
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'The WooCommerce store could not be reached.', 'connection_failed', retryable: true);
        }

        return $this->responseResult($response->status());
    }

    public function updateOrderStatus(WooCommerceCredentials $credentials, string $orderId, string $status): SyncResult
    {
        try {
            $response = $this->request($credentials)->put("/orders/{$orderId}", ['status' => $status]);
        } catch (InvalidArgumentException $exception) {
            return SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url');
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'WooCommerce sipariş servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }

        return $this->responseResult($response->status());
    }

    /** @param array<string, mixed> $payload
     * @return array{result: SyncResult, payload: array<string, mixed>}
     */
    public function createProduct(WooCommerceCredentials $credentials, array $payload): array
    {
        return $this->create($credentials, '/products', $payload);
    }

    /** @param array<string, mixed> $payload
     * @return array{result: SyncResult, payload: array<string, mixed>}
     */
    public function createVariation(WooCommerceCredentials $credentials, string $productId, array $payload): array
    {
        return $this->create($credentials, "/products/{$productId}/variations", $payload);
    }

    /** @return array{result: SyncResult, variations: list<array<string, mixed>>} */
    public function pullVariations(WooCommerceCredentials $credentials, string $productId): array
    {
        $variations = [];
        $page = 1;

        do {
            try {
                $response = $this->request($credentials)->get("/products/{$productId}/variations", ['page' => $page, 'per_page' => 100, 'orderby' => 'id', 'order' => 'asc']);
            } catch (InvalidArgumentException $exception) {
                return ['result' => SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'), 'variations' => []];
            } catch (ConnectionException) {
                return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'WooCommerce variations could not be reached.', 'connection_failed', retryable: true), 'variations' => []];
            }

            $result = $this->responseResult($response->status());
            if (! $result->successful) {
                return ['result' => $result, 'variations' => []];
            }
            $payload = $response->json();
            if (is_array($payload)) {
                $variations = [...$variations, ...$payload];
            }
            $totalPages = max(1, (int) $response->header('X-WP-TotalPages', '1'));
            $page++;
        } while ($page <= $totalPages);

        return ['result' => SyncResult::success(), 'variations' => $variations];
    }

    private function request(WooCommerceCredentials $credentials): PendingRequest
    {
        $this->urlGuard->assertSafe($credentials->storeUrl);

        $baseUrl = $this->baseUrl($credentials->storeUrl);
        $request = Http::baseUrl($baseUrl)->acceptJson();

        if (parse_url($baseUrl, PHP_URL_SCHEME) === 'http') {
            $oauth = new WooCommerceOAuth1;
            $signatureBaseUrl = $this->signatureOrigin($credentials->storeUrl);
            $request->withRequestMiddleware(
                fn (RequestInterface $request): RequestInterface => $oauth->sign(
                    $request,
                    $credentials->consumerKey,
                    $credentials->consumerSecret,
                    $signatureBaseUrl,
                ),
            );
        } else {
            $request->withBasicAuth($credentials->consumerKey, $credentials->consumerSecret);
        }

        return $request->connectTimeout(5)->timeout(30);
    }

    private function baseUrl(string $storeUrl): string
    {
        $parts = parse_url($storeUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHosts = config('integrations.woocommerce.local_hosts', []);
        if (app()->environment('local') && config('integrations.woocommerce.allow_local_urls') === true && in_array($host, $localHosts, true) && ! isset($parts['port'])) {
            $bridge = (string) config('integrations.woocommerce.local_host_bridge', 'host.docker.internal');
            $storeUrl = preg_replace_callback(
                '#^([a-z]+://)'.preg_quote($host, '#').'(?=[:/]|$)#i',
                static fn (array $matches): string => $matches[1].$bridge,
                $storeUrl,
            ) ?? $storeUrl;
        }

        return rtrim($storeUrl, '/').'/wp-json/wc/v3';
    }

    private function signatureOrigin(string $storeUrl): string
    {
        $parts = parse_url($storeUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function responseResult(int $status): SyncResult
    {
        return match (true) {
            $status >= 200 && $status < 300 => SyncResult::success(),
            in_array($status, [401, 403], true) => SyncResult::failure(SyncErrorCategory::Authentication, 'WooCommerce rejected the API credentials or permissions.', 'authentication_failed'),
            $status === 404 => SyncResult::failure(SyncErrorCategory::NotFound, 'The requested WooCommerce REST API resource was not found.', 'api_not_found'),
            $status === 429 => SyncResult::failure(SyncErrorCategory::RateLimited, 'WooCommerce temporarily rate limited the request.', 'rate_limited', retryable: true),
            $status >= 500 => SyncResult::failure(SyncErrorCategory::RemoteServer, 'The WooCommerce store returned a temporary server error.', 'remote_server_error', retryable: true),
            default => SyncResult::failure(SyncErrorCategory::Unknown, 'WooCommerce returned an unexpected response.', 'unexpected_response'),
        };
    }

    /** @param array<string, mixed> $payload
     * @return array{result: SyncResult, payload: array<string, mixed>}
     */
    private function create(WooCommerceCredentials $credentials, string $endpoint, array $payload): array
    {
        try {
            $response = $this->request($credentials)->post($endpoint, $payload);
        } catch (InvalidArgumentException $exception) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Validation, $exception->getMessage(), 'unsafe_store_url'), 'payload' => []];
        } catch (ConnectionException) {
            return ['result' => SyncResult::failure(SyncErrorCategory::Network, 'The WooCommerce store could not be reached.', 'connection_failed', retryable: true), 'payload' => []];
        }

        $body = $response->successful() && is_array($response->json()) ? $response->json() : [];

        return ['result' => $this->responseResult($response->status()), 'payload' => $body];
    }
}
