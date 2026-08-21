<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePlatformTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_platform_admin === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('tenants', 'slug')],
            'owner_email' => ['required', 'email', Rule::exists('users', 'email')],
            'subscription_plan' => ['required', Rule::exists('subscription_plans', 'code')->where('status', 'active')],
            'subscription_status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'cancelled'])],
        ];
    }
}
