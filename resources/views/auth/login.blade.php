@extends('layouts.guest')

@section('content')
    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-ui.input label="Email" name="email" type="email" :value="old('email')"
                    required autofocus autocomplete="username" />

        <x-ui.input label="Password" name="password" type="password"
                    required autocomplete="current-password" />

        <div class="mt-2">
            <label for="remember" class="label cursor-pointer justify-start gap-2">
                <input id="remember" type="checkbox" name="remember" class="checkbox checkbox-sm">
                <span class="text-sm">Remember me</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4 gap-4">
            <a href="{{ route('register') }}" class="link link-hover text-sm">
                Need an account?
            </a>

            <x-ui.button>Log in</x-ui.button>
        </div>
    </form>
@endsection
