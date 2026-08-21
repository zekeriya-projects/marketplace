<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveTrendyolListingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) === true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->active_tenant_id;

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('trendyol_listing_templates')->where('tenant_id', $tenantId)],
            'category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'trendyol_category_id' => ['required', 'integer', 'min:1'],
            'trendyol_brand_id' => ['required', 'integer', 'min:1'],
            'image_url' => ['nullable', 'url:https', 'max:2048'],
            'vat_rate' => ['required', 'integer', 'in:0,1,10,20'],
            'dimensional_weight' => ['required', 'numeric', 'min:0'],
            'origin' => ['required', 'string', 'size:2'],
            'attributes' => ['present', 'array'],
            'attributes.*.attributeId' => ['required', 'integer'],
            'attributes.*.attributeValueId' => ['nullable', 'integer'],
            'attributes.*.customAttributeValue' => ['nullable', 'string', 'max:255'],
            'required_attribute_ids' => ['present', 'array'],
            'required_attribute_ids.*' => ['integer', 'distinct'],
        ];
    }
}
