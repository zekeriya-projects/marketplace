<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_platform_admin === true;
    }

    public function rules(): array
    {
        return [
            'subscription_plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('status', 'active')],
            'status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'cancelled'])],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly', 'manual'])],
            'starts_at' => ['required', 'date'], 'trial_ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'current_period_ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
