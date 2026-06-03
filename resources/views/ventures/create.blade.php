@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">Start a new venture</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <x-ui.card>
                <p class="text-sm text-base-content/70">
                    Describe what you want to accomplish. We'll suggest a 7-step starting plan you can edit.
                </p>

                <form method="POST" action="{{ route('ventures.store') }}" class="mt-2 space-y-3">
                    @csrf

                    <x-ui.input label="Title" name="title" :value="old('title')"
                                required autofocus maxlength="120" />

                    <div>
                        <x-ui.input label="Description" name="description" type="textarea"
                                    :value="old('description')" rows="4" maxlength="2000" />
                        <p class="mt-1 text-xs text-base-content/60">
                            Richer descriptions yield better step suggestions.
                        </p>
                    </div>

                    <div class="flex items-center justify-end pt-2">
                        <x-ui.button type="submit">Create venture</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
