@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">
        Edit expense in: {{ $venture->title }}
    </h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <x-ui.card>
                <form method="POST" action="{{ route('expenses.update', [$venture, $expense]) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')

                    <x-ui.input label="Amount" name="amount" type="number"
                                :value="old('amount', $expense->amount)" required autofocus step="0.01" min="0" />

                    <x-ui.input label="Description" name="description" type="textarea"
                                :value="old('description', $expense->description)" rows="2" maxlength="200" required />

                    <x-ui.input label="Date" name="date" type="date"
                                :value="old('date', $expense->date->toDateString())" required />

                    <div class="flex items-center justify-end gap-4 pt-2">
                        <x-ui.button :href="route('ventures.show', $venture)" variant="ghost">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit">Save</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
