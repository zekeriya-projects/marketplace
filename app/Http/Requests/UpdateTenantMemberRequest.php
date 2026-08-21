<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');

        return $tenant instanceof Tenant && $this->user()?->can('manageMembers', $tenant) === true;
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(TenantRole::class)]];
    }
}
