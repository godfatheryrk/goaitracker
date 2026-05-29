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
     * Normalize description so empty string becomes null — the show view
     * renders `{{ $venture->description ?? '—' }}`, so an empty string
     * would render as an empty paragraph instead of the em-dash fallback.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('description') === '') {
            $this->merge(['description' => null]);
        }
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
