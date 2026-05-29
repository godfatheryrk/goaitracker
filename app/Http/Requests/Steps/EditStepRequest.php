<?php

namespace App\Http\Requests\Steps;

use Illuminate\Foundation\Http\FormRequest;

class EditStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whitelist — the FormRequest layer of source-immutability defense
     * (Step::$fillable is the other layer). 'body' and the optional 'deadline'
     * are the only assignable fields; 'source'/'is_completed' are deliberately
     * absent. An empty 'deadline' submit clears the deadline (nullable).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:200'],
            'deadline' => ['nullable', 'date'],
        ];
    }
}
