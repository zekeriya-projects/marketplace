<?php

declare(strict_types=1);

namespace App\Integrations\Ticimax;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Ticimax\Contracts\TicimaxSoapTransport;
use App\Integrations\Ticimax\DTO\TicimaxCredentials;
use InvalidArgumentException;
use SoapFault;
use Throwable;

final class TicimaxClient
{
    public function __construct(private readonly TicimaxSoapTransport $transport, private readonly TicimaxUrlGuard $guard) {}

    public function testConnection(TicimaxCredentials $credentials): SyncResult
    {
        try {
            $this->guard->assertSafe($credentials->storeUrl);
            $this->transport->call($credentials->productWsdl(), 'SelectUrunCount', [
                'UyeKodu' => $credentials->memberCode,
                'f' => $this->productFilter(),
            ]);

            return SyncResult::success();
        } catch (InvalidArgumentException) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Ticimax mağaza adresi güvenli veya geçerli değil.', 'invalid_store_url');
        } catch (SoapFault $exception) {
            return $this->soapFailure($exception);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Ticimax web servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }
    }

    public function updateInventory(TicimaxCredentials $credentials, int $variantId, int $quantity): SyncResult
    {
        try {
            $this->guard->assertSafe($credentials->storeUrl);
            $this->transport->call($credentials->productWsdl(), 'StokAdediGuncelle', [
                'UyeKodu' => $credentials->memberCode,
                'Urunler' => ['Varyasyon' => [['ID' => $variantId, 'StokAdedi' => $quantity]]],
            ]);

            return SyncResult::success();
        } catch (SoapFault $exception) {
            return $this->soapFailure($exception);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Ticimax ürün servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }
    }

    /** @param array<string, mixed> $variant @param array<string, bool> $settings */
    public function updateVariant(TicimaxCredentials $credentials, array $variant, array $settings): SyncResult
    {
        try {
            $this->guard->assertSafe($credentials->storeUrl);
            $response = $this->transport->call($credentials->productWsdl(), 'VaryasyonGuncelle', [
                'UyeKodu' => $credentials->memberCode,
                'Varyasyon' => $variant,
                'VaryasyonAyar' => $settings,
            ]);

            return $response === 0 || data_get($response, 'VaryasyonGuncelleResult') === 0
                ? SyncResult::failure(SyncErrorCategory::Validation, 'Ticimax varyasyon güncellemesini reddetti.', 'update_rejected')
                : SyncResult::success();
        } catch (SoapFault $exception) {
            return $this->soapFailure($exception);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Network, 'Ticimax ürün servisine ulaşılamadı.', 'connection_failed', retryable: true);
        }
    }

    /** @return array<string, int> */
    private function productFilter(): array
    {
        return ['Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1, 'Vitrin' => -1, 'KategoriID' => 0, 'MarkaID' => 0, 'UrunKartiID' => 0];
    }

    private function soapFailure(SoapFault $exception): SyncResult
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'uyekodu') || str_contains($message, 'yetki') || str_contains($message, 'unauthorized')) {
            return SyncResult::failure(SyncErrorCategory::Authentication, 'Ticimax üye kodunu veya web servis yetkilerini reddetti.', 'authentication_failed');
        }

        return SyncResult::failure(SyncErrorCategory::Validation, 'Ticimax web servis isteğini reddetti.', 'request_rejected');
    }
}
