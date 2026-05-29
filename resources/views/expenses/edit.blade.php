@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Edit expense in: {{ $venture->title }}
    </h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('expenses.update', [$venture, $expense]) }}">
                        @csrf
                        @method('PATCH')

                        <div>
                            <label for="amount" class="block font-medium text-sm text-gray-700">Amount</label>
                            <input type="number" step="0.01" min="0" id="amount" name="amount" required autofocus
                                   value="{{ old('amount', $expense->amount) }}"
                                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            @error('amount')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-4">
                            <label for="description" class="block font-medium text-sm text-gray-700">Description</label>
                            <textarea id="description" name="description" rows="2" maxlength="200" required
                                      class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('description', $expense->description) }}</textarea>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-4">
                            <label for="date" class="block font-medium text-sm text-gray-700">Date</label>
                            <input type="date" id="date" name="date" required
                                   value="{{ old('date', $expense->date->toDateString()) }}"
                                   class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            @error('date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-end mt-6 space-x-4">
                            <a href="{{ route('ventures.show', $venture) }}"
                               class="text-sm text-gray-600 hover:text-gray-900">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                                           rounded-md font-semibold text-xs text-white uppercase tracking-widest
                                           hover:bg-gray-700">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
