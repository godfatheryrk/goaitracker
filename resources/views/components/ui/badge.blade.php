@props([
    'variant' => 'neutral',
])

@php
    // Full literal class strings so Tailwind compiles every variant (the scanner
    // can't see 'badge-' . $variant built at runtime).
    $variants = [
        'neutral' => 'badge badge-neutral',
        'primary' => 'badge badge-primary',
        'success' => 'badge badge-success',
        'warning' => 'badge badge-warning',
        'error' => 'badge badge-error',
    ];
    $classes = $variants[$variant] ?? $variants['neutral'];
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
