@props([
    'label' => null,
    'name',
    'type' => 'text',
    'value' => null,
    'id' => null,
    'error' => null,
])

@php
    $fieldId = $id ?? $name;
    $message = $error ?? $errors->first($name);
@endphp

<fieldset class="fieldset">
    @if ($label)
        <label class="fieldset-legend" for="{{ $fieldId }}">{{ $label }}</label>
    @endif

    @if ($type === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $name }}"
                  {{ $attributes->merge(['class' => 'textarea textarea-bordered w-full']) }}>{{ $value }}</textarea>
    @else
        <input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}"
               {{ $attributes->merge(['class' => 'input input-bordered w-full']) }}>
    @endif

    @if ($message)
        <p class="fieldset-label text-error">{{ $message }}</p>
    @endif
</fieldset>
