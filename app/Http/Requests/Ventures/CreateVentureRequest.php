<?php

namespace App\Http\Requests\Ventures;

use Illuminate\Foundation\Http\FormRequest;

class CreateVentureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
