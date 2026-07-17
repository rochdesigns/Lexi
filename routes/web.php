<?php

use App\Http\Controllers\TaskController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/today', TodayController::class)->name('today');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');

    // Stub routes — content added in future sprints
    Route::inertia('/calendar', 'Stub')->name('calendar');
    Route::inertia('/tasks', 'Stub')->name('tasks');
    Route::inertia('/health', 'Stub')->name('health');
    Route::inertia('/habits', 'Stub')->name('habits');
    Route::inertia('/notes', 'Stub')->name('notes');
    Route::inertia('/journal', 'Stub')->name('journal');
    Route::inertia('/meals', 'Stub')->name('meals');
    Route::inertia('/groceries', 'Stub')->name('groceries');
    Route::inertia('/pets', 'Stub')->name('pets');
    Route::inertia('/goals', 'Stub')->name('goals');
    Route::inertia('/accounts', 'Stub')->name('accounts');
    Route::inertia('/budgets', 'Stub')->name('budgets');
    Route::inertia('/set-asides', 'Stub')->name('set-asides');
    Route::inertia('/debt', 'Stub')->name('debt');
    Route::inertia('/photos', 'Stub')->name('photos');

    // Legacy redirect — keeps old dashboard links working
    Route::redirect('/dashboard', '/today')->name('dashboard');
});

require __DIR__.'/settings.php';
