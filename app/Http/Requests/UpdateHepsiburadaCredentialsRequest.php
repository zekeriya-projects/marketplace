<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ChannelAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateHepsiburadaCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ChannelAccount && $this->user()?->can('update', $account) === true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'environment' => ['required', Rule::in(['production', 'stage'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');
            if ($account instanceof ChannelAccount && $account->channel()->where('code', 'hepsiburada')->doesntExist()) {
                $validator->errors()->add('merchant_id', 'Kimlik bilgileri yalnızca Hepsiburada hesaplarında kaydedilebilir.');
            }
        }];
    }
}
