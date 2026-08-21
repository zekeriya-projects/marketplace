<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkPublishProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) === true;
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['required_unless:select_all,true', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['required', 'uuid', 'distinct'],
            'select_all' => ['required', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'channel_account_ids' => ['required', 'array', 'min:1', 'max:20'],
            'channel_account_ids.*' => ['required', 'uuid', 'distinct'],
            'trendyol_template_id' => ['nullable', 'uuid', Rule::exists('trendyol_listing_templates', 'id')->where('tenant_id', $this->user()?->active_tenant_id)],
        ];
    }
}
