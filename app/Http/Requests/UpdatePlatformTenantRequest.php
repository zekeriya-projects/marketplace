<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlatformTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_platform_admin === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'alpha_dash', 'max:255', Rule::unique('tenants', 'slug')->ignore($this->route('tenant'))],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'subscription_plan' => ['required', Rule::exists('subscription_plans', 'code')->where('status', 'active')],
            'subscription_status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'cancelled'])],
        ];
    }
}
