<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Channel;
use App\Models\ChannelAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreChannelAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChannelAccount::class) === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->get()->getKey();

        return [
            'channel_id' => ['required', 'uuid', Rule::exists(Channel::class, 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('code', ['woocommerce', 'trendyol', 'hepsiburada']))],
            'name' => ['required', 'string', 'max:255', Rule::unique(ChannelAccount::class)->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('channel_id', $this->input('channel_id')))],
        ];
    }
}
