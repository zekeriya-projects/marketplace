<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Warehouse::class) === true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->get();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('warehouses')->where('tenant_id', $tenant->getKey())],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
