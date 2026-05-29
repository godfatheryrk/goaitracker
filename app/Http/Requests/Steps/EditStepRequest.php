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
     * Body-only whitelist — the FormRequest layer of source-immutability
     * defense (Step::$fillable is the other layer). Any future audit-only
     * field belongs here, not on CreateStepRequest.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:200'],
        ];
    }
}
