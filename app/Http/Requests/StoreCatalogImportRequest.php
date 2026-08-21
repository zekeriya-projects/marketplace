<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CatalogImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCatalogImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CatalogImport::class) === true;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['excel', 'xml'])],
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,xml'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $extension = strtolower((string) $this->file('file')?->getClientOriginalExtension());
            $allowed = $this->input('format') === 'xml' ? ['xml'] : ['xlsx', 'xls', 'csv'];
            if (! in_array($extension, $allowed, true)) {
                $validator->errors()->add('file', 'Dosya uzantısı seçilen aktarım türüyle eşleşmiyor.');
            }
        }];
    }
}
