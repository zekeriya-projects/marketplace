<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ChannelAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublishTrendyolListingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['required_attribute_ids' => $this->input('required_attribute_ids', [])]);
    }

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ChannelAccount && $this->user()?->can('update', $account) === true;
    }

    public function rules(): array
    {
        return ['variant_id' => ['required', 'uuid'], 'brand_id' => ['required', 'integer', 'min:1'], 'category_id' => ['required', 'integer', 'min:1'], 'image_url' => ['required', 'url:https', 'max:2048'], 'vat_rate' => ['required', 'integer', 'in:0,1,10,20'], 'dimensional_weight' => ['required', 'numeric', 'min:0'], 'origin' => ['required', 'string', 'size:2'], 'attributes' => ['present', 'array'], 'attributes.*.attributeId' => ['required', 'integer'], 'attributes.*.attributeValueId' => ['nullable', 'integer'], 'attributes.*.customAttributeValue' => ['nullable', 'string', 'max:255'], 'template_name' => ['nullable', 'string', 'max:150', Rule::unique('trendyol_listing_templates', 'name')->where('tenant_id', $this->user()?->active_tenant_id)], 'central_category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('tenant_id', $this->user()?->active_tenant_id)], 'required_attribute_ids' => ['present', 'array'], 'required_attribute_ids.*' => ['integer', 'distinct']];
    }
}
