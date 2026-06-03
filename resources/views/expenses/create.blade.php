@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">
        Add expense to: {{ $venture->title }}
    </h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <x-ui.card>
                <form method="POST" action="{{ route('expenses.store', $venture) }}" class="space-y-3">
                    @csrf

                    <x-ui.input label="Amount" name="amount" type="number"
                                :value="old('amount')" required autofocus step="0.01" min="0" />

                    <x-ui.input label="Description" name="description" type="textarea"
                                :value="old('description')" rows="2" maxlength="200" required />

                    <x-ui.input label="Date" name="date" type="date"
                                :value="old('date', now()->toDateString())" required />

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
