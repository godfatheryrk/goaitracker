@props([
    'variant' => 'info',
])

<div role="alert" {{ $attributes->merge(['class' => 'alert alert-' . $variant]) }}>
    {{ $slot }}
</div>
