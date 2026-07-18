<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskSectionController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/today', TodayController::class)->name('today');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');

    Route::post('/task-sections', [TaskSectionController::class, 'store'])->name('task-sections.store');
    Route::post('/task-sections/reorder', [TaskSectionController::class, 'reorder'])->name('task-sections.reorder');
    Route::patch('/task-sections/{taskSection}', [TaskSectionController::class, 'update'])->name('task-sections.update');
    Route::delete('/task-sections/{taskSection}', [TaskSectionController::class, 'destroy'])->name('task-sections.destroy');

    // Stub routes — content added in future sprints
    Route::inertia('/calendar', 'Stub')->name('calendar');
    Route::inertia('/health', 'Stub')->name('health');
    Route::inertia('/habits', 'Stub')->name('habits');
    Route::inertia('/notes', 'Stub')->name('notes');
    Route::inertia('/journal', 'Stub')->name('journal');
    Route::inertia('/meals', 'Stub')->name('meals');
    Route::inertia('/groceries', 'Stub')->name('groceries');
    Route::inertia('/pets', 'Stub')->name('pets');
    Route::inertia('/goals', 'Stub')->name('goals');
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::inertia('/budgets', 'Stub')->name('budgets');
    Route::inertia('/set-asides', 'Stub')->name('set-asides');
    Route::inertia('/debt', 'Stub')->name('debt');
    Route::inertia('/photos', 'Stub')->name('photos');

    // Legacy redirect — keeps old dashboard links working
    Route::redirect('/dashboard', '/today')->name('dashboard');
});

require __DIR__.'/settings.php';
