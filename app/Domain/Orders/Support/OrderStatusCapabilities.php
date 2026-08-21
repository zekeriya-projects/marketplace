<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use App\Domain\Orders\Enums\OrderStatus;
use App\Models\ChannelAccount;

final class OrderStatusCapabilities
{
    /** @return array<string,string> */
    public function mappings(ChannelAccount $account): array
    {
        $code = $account->relationLoaded('channel') ? $account->channel->code : $account->channel()->value('code');
        if ($code === 'trendyol') {
            return ['processing' => 'Picking'];
        }
        $defaults = ['pending' => 'pending', 'confirmed' => 'on-hold', 'processing' => 'processing', 'delivered' => 'completed', 'cancelled' => 'cancelled', 'returned' => 'refunded'];
        $custom = is_array($account->settings['order_status_mappings'] ?? null) ? $account->settings['order_status_mappings'] : [];

        return [...$defaults, ...collect($custom)->filter(fn ($value, $key) => in_array($key, array_column(OrderStatus::cases(), 'value'), true) && is_string($value) && preg_match('/^[a-z0-9_-]{1,50}$/i', $value))->all()];
    }

    public function externalStatus(ChannelAccount $account, OrderStatus $status): ?string
    {
        return $this->mappings($account)[$status->value] ?? null;
    }

    /** @return list<array{value:string,label:string,provider_status:string,meaning:string}> */
    public function available(ChannelAccount $account, OrderStatus $current): array
    {
        $labels = ['pending' => 'Yeni', 'confirmed' => 'Onaylandı', 'processing' => 'Hazırlanıyor', 'shipped' => 'Kargoya teslim edildi', 'delivered' => 'Teslim edildi', 'cancelled' => 'İptal edildi', 'returned' => 'İade edildi'];

        return collect($this->mappings($account))->reject(fn ($external, $status) => $status === $current->value)->map(fn ($external, $status): array => ['value' => $status, 'label' => $labels[$status], 'provider_status' => $external, 'meaning' => "Pazaryerine “{$external}” olarak gönderilir."])->values()->all();
    }
}
