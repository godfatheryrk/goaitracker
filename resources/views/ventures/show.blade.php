@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">{{ $venture->title }}</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('ai_unavailable'))
                <x-ui.alert variant="warning">
                    {{ session('ai_unavailable') }}
                </x-ui.alert>
            @endif

            <x-ui.card>
                <h3 class="font-medium text-sm text-base-content/60 uppercase tracking-wide">Description</h3>
                <p class="mt-2 whitespace-pre-line text-base-content/80">
                    {{ $venture->description ?? '—' }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-baseline justify-between border-b border-base-300 pb-3">
                    <h3 class="font-medium text-sm text-base-content/60 uppercase tracking-wide">Steps</h3>
                    <p class="grow-0 shrink-0 text-sm text-base-content/70" data-progress-text>
                        {{ $completed }} of {{ $total }} completed
                    </p>
                </div>

                @if ($venture->steps->isEmpty())
                    <div class="mt-4 space-y-3">
                        <p class="text-sm text-base-content/70">No steps yet.</p>
                        <div class="flex items-center gap-4">
                            <x-ui.button :href="route('steps.create', $venture)" class="btn-sm">
                                + Add your first step
                            </x-ui.button>
                            <form method="POST" action="{{ route('steps.suggestions.preview', $venture) }}" class="inline-block">
                                @csrf
                                <x-ui.button type="submit" variant="primary" class="btn-outline btn-sm">
                                    Suggest more with AI
                                </x-ui.button>
                            </form>
                        </div>
                    </div>
                @else
                    <ul class="mt-4 space-y-2 text-base-content/80">
                        @foreach ($venture->steps as $step)
                            <li class="flex items-start justify-between gap-3">
                                <form method="POST"
                                      action="{{ route('steps.completion', [$venture, $step]) }}"
                                      data-toggle-completion
                                      class="flex items-start gap-2 flex-1 min-w-0">
                                    @csrf
                                    @method('PATCH')
                                    <label class="flex items-start gap-2 flex-1 min-w-0 cursor-pointer">
                                        <input type="checkbox"
                                               name="is_completed"
                                               value="1"
                                               {{ $step->is_completed ? 'checked' : '' }}
                                               class="checkbox checkbox-sm mt-0.5 shrink-0">
                                        <span class="flex flex-col min-w-0">
                                            <span data-step-body
                                                  class="leading-6 {{ $step->is_completed ? 'line-through text-base-content/40' : '' }}">
                                                {{ $step->body }}
                                            </span>
                                            @if ($step->deadline)
                                                @php
                                                    // data-pressure-class is deadline-only (completion-agnostic),
                                                    // so the live toggle can restore emphasis on un-complete even
                                                    // for a step that loaded completed. Whether it is *shown* now
                                                    // is the separate `! is_completed` render gate below.
                                                    $pressure = $step->deadlinePressure();
                                                    $pressureClass = match ($pressure) {
                                                        'overdue' => 'text-error font-medium',
                                                        'imminent' => 'text-warning font-medium',
                                                        default => '',
                                                    };
                                                    $label = $pressure === 'overdue' ? 'Overdue' : 'Due';
                                                @endphp
                                                <span data-deadline-badge
                                                      data-pressure-class="{{ $pressureClass }}"
                                                      class="text-xs text-base-content/60 {{ $step->is_completed ? '' : $pressureClass }}">
                                                    {{ $label }} {{ $step->deadline->format('M j') }}
                                                </span>
                                            @endif
                                        </span>
                                    </label>
                                    <noscript>
                                        <button type="submit" class="link link-hover text-xs leading-6">
                                            Save
                                        </button>
                                    </noscript>
                                </form>
                                <div class="flex items-center gap-2 shrink-0">
                                    <x-ui.button :href="route('steps.edit', [$venture, $step])"
                                                 variant="neutral" class="btn-outline btn-xs">
                                        Edit
                                    </x-ui.button>
                                    <form method="POST"
                                          action="{{ route('steps.destroy', [$venture, $step]) }}"
                                          onsubmit="return confirm('Delete this step?')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="error" class="btn-outline btn-xs">
                                            Delete
                                        </x-ui.button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4 flex items-center gap-4">
                        <x-ui.button :href="route('steps.create', $venture)" variant="primary" class="btn-outline btn-sm">
                            + Add step
                        </x-ui.button>
                        <form method="POST" action="{{ route('steps.suggestions.preview', $venture) }}" class="inline-block">
                            @csrf
                            <x-ui.button type="submit" variant="primary" class="btn-outline btn-sm">
                                Suggest more with AI
                            </x-ui.button>
                        </form>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-baseline justify-between border-b border-base-300 pb-3">
                    <h3 class="font-medium text-sm text-base-content/60 uppercase tracking-wide">Expenses</h3>
                    <p class="grow-0 shrink-0 text-base font-semibold text-base-content">Total: {{ number_format($totalCost ?? 0, 2) }}</p>
                </div>

                @if ($venture->expenses->isEmpty())
                    <div class="mt-4 space-y-3">
                        <p class="text-sm text-base-content/70">No expenses yet.</p>
                        <x-ui.button :href="route('expenses.create', $venture)" class="btn-sm">
                            + Add expense
                        </x-ui.button>
                    </div>
                @else
                    <ul class="mt-3 divide-y divide-base-300">
                        @foreach ($venture->expenses as $expense)
                            <li class="py-2 flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-base-content/80">{{ $expense->description }}</p>
                                    <p class="text-xs text-base-content/60">{{ $expense->date->toDateString() }} · {{ $expense->amount }}</p>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <x-ui.button :href="route('expenses.edit', [$venture, $expense])"
                                                 variant="neutral" class="btn-outline btn-xs">Edit</x-ui.button>
                                    <form method="POST"
                                          action="{{ route('expenses.destroy', [$venture, $expense]) }}"
                                          onsubmit="return confirm('Delete this expense?')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="error" class="btn-outline btn-xs">Delete</x-ui.button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4">
                        <x-ui.button :href="route('expenses.create', $venture)" variant="primary" class="btn-outline btn-sm">
                            + Add expense
                        </x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
