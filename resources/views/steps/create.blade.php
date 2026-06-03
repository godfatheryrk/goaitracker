@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">
        Add step to: {{ $venture->title }}
    </h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <x-ui.card>
                <form method="POST" action="{{ route('steps.store', $venture) }}" class="space-y-3">
                    @csrf

                    <x-ui.input label="Step" name="body" type="textarea"
                                :value="old('body')" rows="3" maxlength="200" required autofocus />

                    <div>
                        <x-ui.input label="Deadline (optional)" name="deadline" type="date"
                                    :value="old('deadline')" />
                        <p class="mt-1 text-xs text-base-content/60">
                            Leave blank for no deadline.
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-4 pt-2">
                        <x-ui.button :href="route('ventures.show', $venture)" variant="ghost">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit">Add</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
