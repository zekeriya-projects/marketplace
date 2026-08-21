<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlatformMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_platform_admin === true;
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(TenantRole::class)]];
    }
}
