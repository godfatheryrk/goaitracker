@extends('layouts.guest')

@section('content')
    @if (session('status'))
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
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
                   required autocomplete="current-password"
                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4 block">
            <label for="remember" class="inline-flex items-center">
                <input id="remember" type="checkbox" name="remember"
                       class="rounded border-gray-300 text-indigo-600 shadow-sm">
                <span class="ms-2 text-sm text-gray-600">Remember me</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            <a href="{{ route('register') }}"
               class="underline text-sm text-gray-600 hover:text-gray-900">
                Need an account?
            </a>

            <button type="submit"
                    class="ms-4 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                           rounded-md font-semibold text-xs text-white uppercase tracking-widest
                           hover:bg-gray-700">
                Log in
            </button>
        </div>
    </form>
@endsection
