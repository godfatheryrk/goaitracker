<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\StepsController;
use App\Http\Controllers\VenturesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [VenturesController::class, 'create'])->name('dashboard');

    Route::get('ventures/create', [VenturesController::class, 'create'])->name('ventures.create');
    Route::post('ventures', [VenturesController::class, 'store'])->name('ventures.store');
    Route::get('ventures/{venture}', [VenturesController::class, 'show'])
        ->whereNumber('venture')
        ->name('ventures.show');

    Route::get('ventures/{venture}/steps/create', [StepsController::class, 'create'])
        ->whereNumber('venture')
        ->name('steps.create');
    Route::post('ventures/{venture}/steps', [StepsController::class, 'store'])
        ->whereNumber('venture')
        ->name('steps.store');
    Route::get('ventures/{venture}/steps/{step}/edit', [StepsController::class, 'edit'])
        ->whereNumber('venture')
        ->whereNumber('step')
        ->name('steps.edit');
    Route::patch('ventures/{venture}/steps/{step}', [StepsController::class, 'update'])
        ->whereNumber('venture')
        ->whereNumber('step')
        ->name('steps.update');
    Route::delete('ventures/{venture}/steps/{step}', [StepsController::class, 'destroy'])
        ->whereNumber('venture')
        ->whereNumber('step')
        ->name('steps.destroy');
    Route::patch('ventures/{venture}/steps/{step}/completion', [StepsController::class, 'toggleCompletion'])
        ->whereNumber('venture')
        ->whereNumber('step')
        ->name('steps.completion');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
