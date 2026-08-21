<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;

final class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $warehouse = $this->route('warehouse');

        return $warehouse instanceof Warehouse && $this->user()?->can('adjust', $warehouse) === true;
    }

    public function rules(): array
    {
        return [
            'quantity_delta' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
            'note' => ['required', 'string', 'max:1000'],
        ];
    }
}
