<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_platform_admin === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('subscription_plans', 'code')->ignore($this->route('plan'))],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'monthly_price_amount' => ['required', 'integer', 'min:0'], 'yearly_price_amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])], 'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_featured' => ['required', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'entitlements' => ['array', 'max:50'], 'entitlements.*.feature_code' => ['required', 'alpha_dash', 'max:80'],
            'entitlements.*.label' => ['required', 'string', 'max:255'], 'entitlements.*.enabled' => ['required', 'boolean'],
            'entitlements.*.limit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
