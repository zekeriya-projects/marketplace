<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = $this->route('brand');

        return $brand instanceof Brand ? $this->user()?->can('update', $brand) === true : $this->user()?->can('create', Brand::class) === true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->active_tenant_id;
        $brand = $this->route('brand');

        return ['name' => ['required', 'string', 'max:150'], 'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('brands')->where('tenant_id', $tenantId)->ignore($brand)], 'description' => ['nullable', 'string', 'max:2000'], 'status' => ['required', Rule::in(['active', 'draft', 'archived'])]];
    }
}
