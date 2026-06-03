@props([
    'variant' => 'primary',
    'type' => 'submit',
    'href' => null,
])

@php
    // Full literal class strings (NOT 'btn-' . $variant) so Tailwind's content
    // scanner sees btn-primary / btn-ghost / btn-error verbatim and compiles them.
    $variants = [
        'primary' => 'btn btn-primary',
        'ghost' => 'btn btn-ghost',
        'error' => 'btn btn-error',
        'neutral' => 'btn btn-neutral',
    ];
    $classes = $variants[$variant] ?? $variants['primary'];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
