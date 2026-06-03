@props([
    'variant' => 'info',
])

@php
    // Full literal class strings so Tailwind compiles every variant (the scanner
    // can't see 'alert-' . $variant built at runtime).
    $variants = [
        'info' => 'alert alert-info',
        'success' => 'alert alert-success',
        'warning' => 'alert alert-warning',
        'error' => 'alert alert-error',
    ];
    $classes = $variants[$variant] ?? $variants['info'];
@endphp

<div role="alert" {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</div>
