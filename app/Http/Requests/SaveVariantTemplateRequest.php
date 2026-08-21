<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VariantTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveVariantTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('variant_template');

        return $model instanceof VariantTemplate ? $this->user()?->can('update', $model) === true : $this->user()?->can('create', VariantTemplate::class) === true;
    }

    public function rules(): array
    {
        $tenant = $this->user()?->active_tenant_id;
        $model = $this->route('variant_template');

        return ['name' => ['required', 'string', 'max:150', Rule::unique('variant_templates')->where('tenant_id', $tenant)->ignore($model)], 'description' => ['nullable', 'string', 'max:2000'], 'status' => ['required', Rule::in(['active', 'draft', 'archived'])], 'definition_ids' => ['required', 'array', 'min:1', 'max:10'], 'definition_ids.*' => ['uuid', 'distinct', Rule::exists('variant_definitions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant)->where('status', 'active'))]];
    }
}
