@props([
    'variant' => 'neutral',
])

<span {{ $attributes->merge(['class' => 'badge badge-' . $variant]) }}>
    {{ $slot }}
</span>
