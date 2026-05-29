<?php

namespace App\Http\Requests\Steps;

use Illuminate\Foundation\Http\FormRequest;

class SuggestStepsRequest extends FormRequest
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
            'suggestions' => ['required', 'array', 'min:1', 'max:7'],
            'suggestions.*.body' => ['required', 'string', 'min:1', 'max:200'],
            'suggestions.*.keep' => ['nullable'],
        ];
    }
}
