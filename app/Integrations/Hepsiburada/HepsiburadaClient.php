<?php

declare(strict_types=1);

namespace App\Integrations\Hepsiburada;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Hepsiburada\DTO\HepsiburadaCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HepsiburadaClient
{
    public function testConnection(HepsiburadaCredentials $credentials): SyncResult
    {
        $baseUrl = config("services.hepsiburada.endpoints.{$credentials->environment}");
        if (! is_string($baseUrl) || $baseUrl === '') {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Hepsiburada ortamı geçersiz.', 'invalid_environment');
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withBasicAuth($credentials->username, $credentials->password)
                ->withHeaders(['User-Agent' => "{$credentials->merchantId} - MarketplaceSaaS"])
                ->connectTimeout(5)
                ->timeout(15)
                ->get("/listings/merchantid/{$credentials->merchantId}", ['limit' => 1, 'offset' => 0]);
        } catch (ConnectionException) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Hepsiburada servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }

        return match (true) {
            $response->successful() => SyncResult::success(),
            in_array($response->status(), [401, 403], true) => SyncResult::failure(SyncErrorCategory::Authentication, 'Hepsiburada API kullanıcı adı, şifre veya servis yetkisini reddetti.', 'authentication_failed'),
            $response->status() === 404 => SyncResult::failure(SyncErrorCategory::NotFound, 'Hepsiburada satıcı hesabı bulunamadı.', 'merchant_not_found'),
            $response->status() === 429 => SyncResult::failure(SyncErrorCategory::RateLimited, 'Hepsiburada isteği geçici olarak sınırlandırdı.', 'rate_limited', retryable: true),
            $response->serverError() => SyncResult::failure(SyncErrorCategory::RemoteServer, 'Hepsiburada geçici bir sunucu hatası döndürdü.', 'remote_server_error', retryable: true),
            default => SyncResult::failure(SyncErrorCategory::Validation, 'Hepsiburada bağlantı isteğini reddetti.', 'request_rejected'),
        };
    }
}
