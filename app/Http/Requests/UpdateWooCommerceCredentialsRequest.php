<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Models\ChannelAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class UpdateWooCommerceCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ChannelAccount && $this->user()?->can('update', $account) === true;
    }

    public function rules(): array
    {
        return [
            'store_url' => ['required', 'url:http,https', 'max:2048'],
            'consumer_key' => ['required', 'string', 'max:255', 'regex:/^ck_[A-Za-z0-9]{20,}$/'],
            'consumer_secret' => ['required', 'string', 'max:255', 'regex:/^cs_[A-Za-z0-9]{20,}$/'],
            'shipped_status' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');
            if ($account instanceof ChannelAccount && $account->channel()->where('code', 'woocommerce')->doesntExist()) {
                $validator->errors()->add('store_url', 'Credentials can only be configured here for WooCommerce accounts.');
            }

            try {
                app(WooCommerceUrlGuard::class)->assertConfigurable((string) $this->input('store_url'));
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('store_url', $exception->getMessage());
            }
        }];
    }
}
