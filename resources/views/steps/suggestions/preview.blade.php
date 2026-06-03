@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">
        Suggest more steps for: {{ $venture->title }}
    </h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <x-ui.card>
                <p class="text-sm text-base-content/70">
                    Uncheck any suggestions you don't want to keep. We'll add the rest to the end of your step list.
                </p>

                @error('suggestions')
                    <x-ui.alert variant="error" class="mt-3">{{ $message }}</x-ui.alert>
                @enderror

                <form method="POST" action="{{ route('steps.suggestions.store', $venture) }}">
                    @csrf

                    <ul class="mt-4 space-y-2">
                        @foreach ($suggestions as $i => $body)
                            <li>
                                <label for="suggestion-{{ $i }}"
                                       class="flex items-start gap-3 cursor-pointer rounded-lg p-2 hover:bg-base-200">
                                    <input type="checkbox"
                                           name="suggestions[{{ $i }}][keep]"
                                           value="1"
                                           checked
                                           id="suggestion-{{ $i }}"
                                           class="checkbox checkbox-sm mt-0.5 shrink-0">
                                    <input type="hidden" name="suggestions[{{ $i }}][body]" value="{{ $body }}">
                                    <span class="text-base-content/80">{{ $body }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex items-center justify-end gap-4 mt-6">
                        <x-ui.button :href="route('ventures.show', $venture)" variant="ghost">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit">Keep selected</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
