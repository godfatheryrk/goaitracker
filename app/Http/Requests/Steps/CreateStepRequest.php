<?php

namespace App\Http\Requests\Steps;

use Illuminate\Foundation\Http\FormRequest;

class CreateStepRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:1', 'max:200'],
        ];
    }
}
