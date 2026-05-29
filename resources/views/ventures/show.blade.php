@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $venture->title }}</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('ai_unavailable'))
                <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    {{ session('ai_unavailable') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="font-medium text-sm text-gray-500 uppercase tracking-wide">Description</h3>
                    <p class="mt-2 whitespace-pre-line text-gray-800">
                        {{ $venture->description ?? '—' }}
                    </p>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex items-baseline justify-between">
                        <h3 class="font-medium text-sm text-gray-500 uppercase tracking-wide">Steps</h3>
                        <p class="text-sm text-gray-600" data-progress-text>
                            {{ $completed }} of {{ $total }} completed
                        </p>
                    </div>

                    @if ($venture->steps->isEmpty())
                        <div class="mt-4 space-y-3">
                            <p class="text-sm text-gray-600">No steps yet.</p>
                            <div class="flex items-center gap-4">
                                <a href="{{ route('steps.create', $venture) }}"
                                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                                          rounded-md font-semibold text-xs text-white uppercase tracking-widest
                                          hover:bg-gray-700">
                                    + Add your first step
                                </a>
                                <form method="POST" action="{{ route('steps.suggestions.preview', $venture) }}" class="inline-block">
                                    @csrf
                                    <button type="submit" class="text-sm text-gray-700 hover:text-gray-900">
                                        Suggest more with AI
                                    </button>
                                </form>
                            </div>
                        </div>
                    @else
                        <ol class="mt-3 space-y-2 list-decimal list-inside text-gray-800">
                            @foreach ($venture->steps as $step)
                                <li class="flex items-start gap-3">
                                    <form method="POST"
                                          action="{{ route('steps.completion', [$venture, $step]) }}"
                                          data-toggle-completion
                                          class="flex items-start gap-2 flex-1">
                                        @csrf
                                        @method('PATCH')
                                        <label class="flex items-start gap-2 flex-1 cursor-pointer">
                                            <input type="checkbox"
                                                   name="is_completed"
                                                   value="1"
                                                   {{ $step->is_completed ? 'checked' : '' }}
                                                   class="mt-1 rounded border-gray-300">
                                            <span data-step-body
                                                  class="{{ $step->is_completed ? 'line-through text-gray-400' : '' }}">
                                                {{ $step->body }}
                                            </span>
                                        </label>
                                        <noscript>
                                            <button type="submit"
                                                    class="text-xs text-gray-700 hover:text-gray-900 underline">
                                                Save
                                            </button>
                                        </noscript>
                                    </form>
                                    <a href="{{ route('steps.edit', [$venture, $step]) }}"
                                       class="text-xs text-gray-600 hover:text-gray-900">
                                        Edit
                                    </a>
                                    <form method="POST"
                                          action="{{ route('steps.destroy', [$venture, $step]) }}"
                                          onsubmit="return confirm('Delete this step?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-xs text-red-600 hover:text-red-800">
                                            Delete
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ol>

                        <div class="mt-4 flex items-center gap-4">
                            <a href="{{ route('steps.create', $venture) }}"
                               class="text-sm text-gray-700 hover:text-gray-900">
                                + Add step
                            </a>
                            <form method="POST" action="{{ route('steps.suggestions.preview', $venture) }}" class="inline-block">
                                @csrf
                                <button type="submit" class="text-sm text-gray-700 hover:text-gray-900">
                                    Suggest more with AI
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
