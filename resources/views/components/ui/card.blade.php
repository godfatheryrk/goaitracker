@props([
    'title' => null,
])

<div {{ $attributes->merge(['class' => 'card bg-base-100 shadow-sm']) }}>
    <div class="card-body">
        @if ($title)
            <h2 class="card-title">{{ $title }}</h2>
        @endif
        {{ $slot }}
    </div>
</div>
