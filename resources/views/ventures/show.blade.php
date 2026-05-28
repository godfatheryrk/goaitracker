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
                    <h3 class="font-medium text-sm text-gray-500 uppercase tracking-wide">Steps</h3>

                    @if ($venture->steps->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">No steps yet.</p>
                    @else
                        <ul class="mt-3 space-y-2 list-decimal list-inside text-gray-800">
                            @foreach ($venture->steps as $step)
                                <li>{{ $step->body }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
