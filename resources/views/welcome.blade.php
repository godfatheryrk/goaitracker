<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'GOAITracker') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
    <div class="hero bg-base-200 min-h-screen">
        <div class="hero-content text-center">
            <div class="max-w-md">
                <h1 class="text-5xl font-bold text-base-content">{{ config('app.name', 'GOAITracker') }}</h1>
                <p class="py-6 text-base-content/70">
                    Turn a blank page into a plan. Describe your venture and get an AI-suggested
                    step list you can edit, track, and cost — all in one place.
                </p>

                <div class="flex items-center justify-center gap-4">
                    @auth
                        <x-ui.button :href="route('dashboard')">Dashboard</x-ui.button>
                    @else
                        <x-ui.button :href="route('login')">Log in</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('register')">Register</x-ui.button>
                    @endauth
                </div>
            </div>
        </div>
    </div>
</body>
</html>
