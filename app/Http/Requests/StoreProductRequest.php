<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProduct;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProductRequest extends FormRequest
{
    use ValidatesProduct;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) === true;
    }
}
