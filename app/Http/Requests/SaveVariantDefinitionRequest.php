<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VariantDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveVariantDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $definition = $this->route('variant_definition');

        return $definition instanceof VariantDefinition ? $this->user()?->can('update', $definition) === true : $this->user()?->can('create', VariantDefinition::class) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('values'))) {
            $this->merge(['values' => collect(explode(',', $this->input('values')))->map(fn ($value) => trim($value))->filter()->unique(fn ($value) => mb_strtolower($value))->values()->all()]);
        }
    }

    public function rules(): array
    {
        $definition = $this->route('variant_definition');

        return ['name' => ['required', 'string', 'max:80', Rule::unique('variant_definitions')->where('tenant_id', $this->user()?->active_tenant_id)->ignore($definition)], 'values' => ['required', 'array', 'min:1', 'max:100'], 'values.*' => ['required', 'string', 'max:100'], 'status' => ['required', Rule::in(['active', 'draft', 'archived'])]];
    }
}
