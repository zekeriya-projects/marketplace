<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category ? $this->user()?->can('update', $category) === true : $this->user()?->can('create', Category::class) === true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->active_tenant_id;
        $category = $this->route('category');

        return ['name' => ['required', 'string', 'max:150'], 'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('categories')->where('tenant_id', $tenantId)->ignore($category)], 'parent_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('tenant_id', $tenantId), Rule::notIn([$category?->id])], 'description' => ['nullable', 'string', 'max:2000'], 'status' => ['required', Rule::in(['active', 'draft', 'archived'])]];
    }
}
