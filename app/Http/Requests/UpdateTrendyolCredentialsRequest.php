<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ChannelAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateTrendyolCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ChannelAccount && $this->user()?->can('update', $account) === true;
    }

    public function rules(): array
    {
        return ['seller_id' => ['required', 'string', 'regex:/^[0-9]+$/', 'max:20'], 'api_key' => ['required', 'string', 'max:255'], 'api_secret' => ['required', 'string', 'max:255'], 'environment' => ['required', Rule::in(['production', 'stage'])]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');
            if ($account instanceof ChannelAccount && $account->channel()->where('code', 'trendyol')->doesntExist()) {
                $validator->errors()->add('seller_id', 'Credentials can only be configured here for Trendyol accounts.');
            }
        }];
    }
}
