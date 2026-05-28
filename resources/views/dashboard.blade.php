@extends('layouts.app')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Dashboard</h2>
@endsection

@section('content')
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    Welcome, <strong>{{ auth()->user()->name }}</strong>
                    <span class="text-sm text-gray-500">({{ auth()->user()->email }})</span>.
                </div>
            </div>
        </div>
    </div>
@endsection
