<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Integrations\Ticimax\TicimaxUrlGuard;
use App\Models\ChannelAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

final class UpdateTicimaxCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ChannelAccount && $this->user()?->can('update', $account) === true;
    }

    public function rules(): array
    {
        return ['store_url' => ['required', 'url:https', 'max:255'], 'member_code' => ['required', 'string', 'max:255']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');
            if ($account instanceof ChannelAccount && $account->channel()->where('code', 'ticimax')->doesntExist()) {
                $validator->errors()->add('store_url', 'Kimlik bilgileri yalnızca Ticimax hesaplarında kaydedilebilir.');

                return;
            }
            try {
                app(TicimaxUrlGuard::class)->assertSafe((string) $this->input('store_url'));
            } catch (Throwable $exception) {
                $validator->errors()->add('store_url', $exception->getMessage());
            }
        }];
    }
}
