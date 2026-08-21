<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesProduct
{
    protected function prepareForValidation(): void
    {
        $variants = collect($this->input('variants', []))->map(function (mixed $variant): mixed {
            if (! is_array($variant)) {
                return $variant;
            }

            foreach (['id', 'sku', 'barcode', 'variant_template_id'] as $field) {
                if (($variant[$field] ?? null) === '') {
                    $variant[$field] = null;
                }
            }

            return $variant;
        })->all();

        $this->merge([
            'product_type' => $this->input('product_type', 'simple'),
            'vat_rate' => $this->input('vat_rate', 20),
            'excise_tax_rate' => $this->input('excise_tax_rate', 0),
            'communication_tax_rate' => $this->input('communication_tax_rate', 0),
            'disable_external_sync' => $this->boolean('disable_external_sync'),
            'category_id' => $this->input('category_id') === '' ? null : $this->input('category_id'),
            'brand_id' => $this->input('brand_id') === '' ? null : $this->input('brand_id'),
            'brand' => $this->input('brand') === '' ? null : $this->input('brand'),
            'description' => $this->input('description') === '' ? null : $this->input('description'),
            'short_name' => $this->input('short_name') === '' ? null : $this->input('short_name'),
            'invoice_name' => $this->input('invoice_name') === '' ? null : $this->input('invoice_name'),
            'custom_code_1' => $this->input('custom_code_1') === '' ? null : $this->input('custom_code_1'),
            'custom_code_2' => $this->input('custom_code_2') === '' ? null : $this->input('custom_code_2'),
            'vat_exemption_code' => $this->input('vat_exemption_code') === '' ? null : $this->input('vat_exemption_code'),
            'expiration_date' => $this->input('expiration_date') === '' ? null : $this->input('expiration_date'),
            'variants' => $variants,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('tenant_id', $this->user()?->active_tenant_id)],
            'brand_id' => ['nullable', 'uuid', Rule::exists('brands', 'id')->where('tenant_id', $this->user()?->active_tenant_id)],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'invoice_name' => ['nullable', 'string', 'max:255'],
            'custom_code_1' => ['nullable', 'string', 'max:100'],
            'custom_code_2' => ['nullable', 'string', 'max:100'],
            'compare_at_price' => ['nullable', 'string', 'regex:/^\d{1,10}([\.,]\d{1,2})?$/'],
            'purchase_price' => ['nullable', 'string', 'regex:/^\d{1,10}([\.,]\d{1,2})?$/'],
            'desi' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'desi_2' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'vat_rate' => ['required', 'integer', Rule::in([0, 1, 10, 20])],
            'excise_tax_rate' => ['required', 'numeric', 'between:0,100'],
            'communication_tax_rate' => ['required', 'numeric', 'between:0,100'],
            'disable_external_sync' => ['required', 'boolean'],
            'vat_exemption_code' => ['nullable', 'string', 'max:50'],
            'expiration_date' => ['nullable', 'date_format:Y-m-d'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'product_type' => ['required', Rule::in(['simple', 'variable'])],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'uuid', 'distinct'],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:100', 'distinct:ignore_case'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.base_price' => ['required', 'string', 'regex:/^\d{1,10}([\.,]\d{1,2})?$/'],
            'variants.*.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'variants.*.status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'variants.*.variant_template_id' => [Rule::requiredIf($this->input('product_type') === 'variable'), 'nullable', 'uuid', Rule::exists('variant_templates', 'id')->where(fn ($query) => $query->where('tenant_id', $this->user()?->active_tenant_id)->where('status', 'active'))],
            'variants.*.option_values' => ['nullable', 'array', 'max:10'],
            'variants.*.option_values.*' => ['required', 'string', 'max:100'],
        ];
    }
}
