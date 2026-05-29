@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">My ventures</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($ventures->isEmpty())
                        <p class="text-sm text-gray-600">
                            You haven't created any ventures yet.
                        </p>
                        <div class="mt-4">
                            <a href="{{ route('ventures.create') }}"
                               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                                      rounded-md font-semibold text-xs text-white uppercase tracking-widest
                                      hover:bg-gray-700">
                                Create your first venture
                            </a>
                        </div>
                    @else
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-medium text-sm text-gray-500 uppercase tracking-wide">
                                Your ventures
                            </h3>
                            <a href="{{ route('ventures.create') }}"
                               class="text-sm text-gray-700 hover:text-gray-900">
                                + New venture
                            </a>
                        </div>

                        <ul class="mt-4 divide-y divide-gray-100">
                            @foreach ($ventures as $venture)
                                <li class="py-3 flex items-start justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <a href="{{ route('ventures.show', $venture) }}"
                                           class="block font-medium text-gray-900 hover:text-gray-700 truncate">
                                            {{ $venture->title }}
                                        </a>
                                        {{-- Stacked metadata (shared row contract):
                                             line 1 = step progress (S-04, below)
                                             line 2 = Total: X.XX cost (S-06, reserved — leave intact if merged)
                                             line 3 = deadline-pressure marker (S-05, below) --}}
                                        <p class="mt-1 text-xs text-gray-500">
                                            @if ($venture->steps_count === 0)
                                                —
                                            @else
                                                {{ $venture->completed_steps_count }} of {{ $venture->steps_count }} steps completed
                                            @endif
                                        </p>
                                        {{-- line 2 reserved for S-06's `Total: X.XX` cost slot --}}
                                        @if ($venture->pressured_steps_count > 0)
                                            <p class="mt-1 text-xs text-red-600 font-medium">⚠ Deadline pressure</p>
                                        @endif
                                    </div>
                                    <form method="POST"
                                          action="{{ route('ventures.destroy', $venture) }}"
                                          onsubmit="return confirm('Delete this venture? This will also remove all its steps.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-xs text-red-600 hover:text-red-800">
                                            Delete
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
