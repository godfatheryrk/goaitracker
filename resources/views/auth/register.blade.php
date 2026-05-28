@extends('layouts.guest')

@section('content')
    <p class="mb-4 text-sm text-gray-600">
        Sign up with just your email and a password — we'll derive a display name from your email.
    </p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div>
            <label for="email" class="block font-medium text-sm text-gray-700">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="username"
                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4">
            <label for="password" class="block font-medium text-sm text-gray-700">Password</label>
            <input id="password" type="password" name="password"
                   required autocomplete="new-password"
                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4">
            <label for="password_confirmation" class="block font-medium text-sm text-gray-700">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   required autocomplete="new-password"
                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
        </div>

        <div class="flex items-center justify-end mt-4">
            <a href="{{ route('login') }}"
               class="underline text-sm text-gray-600 hover:text-gray-900">
                Already registered?
            </a>

            <button type="submit"
                    class="ms-4 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                           rounded-md font-semibold text-xs text-white uppercase tracking-widest
                           hover:bg-gray-700">
                Register
            </button>
        </div>
    </form>
@endsection
