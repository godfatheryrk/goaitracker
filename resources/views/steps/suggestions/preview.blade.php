@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Suggest more steps for: {{ $venture->title }}
    </h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="text-sm text-gray-600">
                        Uncheck any suggestions you don't want to keep. We'll add the rest to the end of your step list.
                    </p>

                    @error('suggestions')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <form method="POST" action="{{ route('steps.suggestions.store', $venture) }}">
                        @csrf

                        <ol class="mt-4 space-y-3 list-decimal list-inside">
                            @foreach ($suggestions as $i => $body)
                                <li class="flex items-start gap-2">
                                    <input type="checkbox"
                                           name="suggestions[{{ $i }}][keep]"
                                           value="1"
                                           checked
                                           id="suggestion-{{ $i }}"
                                           class="mt-1 rounded border-gray-300">
                                    <input type="hidden" name="suggestions[{{ $i }}][body]" value="{{ $body }}">
                                    <label for="suggestion-{{ $i }}" class="ml-2 text-gray-800">{{ $body }}</label>
                                </li>
                            @endforeach
                        </ol>

                        <div class="flex items-center justify-end mt-6 space-x-4">
                            <a href="{{ route('ventures.show', $venture) }}"
                               class="text-sm text-gray-600 hover:text-gray-900">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                                           rounded-md font-semibold text-xs text-white uppercase tracking-widest
                                           hover:bg-gray-700">
                                Keep selected
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
