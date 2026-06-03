@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-base-content leading-tight">My ventures</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <x-ui.card>
                @if ($ventures->isEmpty())
                    <p class="text-sm text-base-content/70">
                        You haven't created any ventures yet.
                    </p>
                    <div class="mt-4">
                        <x-ui.button :href="route('ventures.create')" class="btn-sm">
                            Create your first venture
                        </x-ui.button>
                    </div>
                @else
                    <div class="flex items-baseline justify-between">
                        <h3 class="font-medium text-sm text-base-content/60 uppercase tracking-wide">
                            Your ventures
                        </h3>
                        <x-ui.button :href="route('ventures.create')" variant="primary" class="btn-outline btn-sm">
                            + New venture
                        </x-ui.button>
                    </div>

                    <ul class="mt-4 divide-y divide-base-300">
                        @foreach ($ventures as $venture)
                            <li class="py-3 flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <a href="{{ route('ventures.show', $venture) }}"
                                       class="block font-medium text-base-content hover:text-base-content/70 truncate">
                                        {{ $venture->title }}
                                    </a>
                                    {{-- Stacked metadata (shared row contract):
                                         line 1 = step progress (S-04)
                                         line 2 = total cost (S-06)
                                         line 3 = deadline-pressure marker (S-05) --}}
                                    <p class="mt-1 text-xs text-base-content/60">
                                        @if ($venture->steps_count === 0)
                                            —
                                        @else
                                            {{ $venture->completed_steps_count }} of {{ $venture->steps_count }} steps completed
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-base-content/60">
                                        Total: {{ number_format($venture->total_cost ?? 0, 2) }}
                                    </p>
                                    @if ($venture->pressured_steps_count > 0)
                                        <p class="mt-1">
                                            <x-ui.badge variant="warning" class="badge-sm gap-1">⚠ Deadline pressure</x-ui.badge>
                                        </p>
                                    @endif
                                </div>
                                <form method="POST"
                                      action="{{ route('ventures.destroy', $venture) }}"
                                      onsubmit="return confirm('Delete this venture? This will also remove all its steps.')">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="error" class="btn-outline btn-sm">
                                        Delete
                                    </x-ui.button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
