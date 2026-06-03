@extends('layouts.guest')

@section('content')
    <p class="mb-4 text-sm text-base-content/70">
        Sign up with just your email and a password — we'll derive a display name from your email.
    </p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <x-ui.input label="Email" name="email" type="email" :value="old('email')"
                    required autofocus autocomplete="username" />

        <x-ui.input label="Password" name="password" type="password"
                    required autocomplete="new-password" />

        <x-ui.input label="Confirm password" name="password_confirmation" type="password"
                    required autocomplete="new-password" />

        <div class="flex items-center justify-end mt-4 gap-4">
            <a href="{{ route('login') }}" class="link link-hover text-sm">
                Already registered?
            </a>

            <x-ui.button>Register</x-ui.button>
        </div>
    </form>
@endsection
