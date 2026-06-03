@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">Start a new venture</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="mb-4 text-sm text-gray-600">
                        Describe what you want to accomplish. We'll suggest a 7-step starting plan you can edit.
                    </p>

                    <form method="POST" action="{{ route('ventures.store') }}">
                        @csrf

                        <div>
                            <label for="title" class="block font-medium text-sm text-gray-700">Title</label>
                            <input id="title" type="text" name="title" value="{{ old('title') }}"
                                   required autofocus maxlength="120"
                                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            @error('title')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-4">
                            <label for="description" class="block font-medium text-sm text-gray-700">Description</label>
                            <textarea id="description" name="description" rows="4" maxlength="2000"
                                      class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('description') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Richer descriptions yield better step suggestions.
                            </p>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-end mt-6">
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                                           rounded-md font-semibold text-xs text-white uppercase tracking-widest
                                           hover:bg-gray-700">
                                Create venture
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
