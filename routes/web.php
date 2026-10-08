<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\Money\AccountController;
use App\Http\Controllers\Money\BillController;
use App\Http\Controllers\Money\CalendarController as MoneyCalendarController;
use App\Http\Controllers\Money\CustomCategoryController;
use App\Http\Controllers\Money\DebtController;
use App\Http\Controllers\Money\DebtPaymentController;
use App\Http\Controllers\Money\GoalController;
use App\Http\Controllers\Money\IncomeSourceController;
use App\Http\Controllers\Money\OverviewController as MoneyOverviewController;
use App\Http\Controllers\Money\PaymentAllocationController;
use App\Http\Controllers\Money\PaymentPlanController;
use App\Http\Controllers\Money\PlanController as MoneyPlanController;
use App\Http\Controllers\Money\RecoveryController;
use App\Http\Controllers\Money\StatementImportController;
use App\Http\Controllers\Money\TransactionsController as MoneyTransactionsController;
use App\Http\Controllers\Money\WhatIfController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskSectionController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\VisionBoardItemController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::inertia('/privacy', 'legal/Privacy')->name('privacy');
Route::inertia('/terms', 'legal/Terms')->name('terms');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/today', TodayController::class)->name('today');
    Route::post('/vision-board-items', [VisionBoardItemController::class, 'store'])->name('vision-board-items.store');
    Route::patch('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'update'])->name('vision-board-items.update');
    Route::delete('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'destroy'])->name('vision-board-items.destroy');
    Route::get('/vision-board-items/{visionBoardItem}/image', [VisionBoardItemController::class, 'image'])
        ->middleware('signed')
        ->name('vision-board-items.image');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');

    Route::post('/task-sections', [TaskSectionController::class, 'store'])->name('task-sections.store');
    Route::post('/task-sections/reorder', [TaskSectionController::class, 'reorder'])->name('task-sections.reorder');
    Route::patch('/task-sections/{taskSection}', [TaskSectionController::class, 'update'])->name('task-sections.update');
    Route::delete('/task-sections/{taskSection}', [TaskSectionController::class, 'destroy'])->name('task-sections.destroy');

    // Stub routes — content added in future sprints
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    Route::inertia('/health', 'Stub')->name('health');
    Route::inertia('/habits', 'Stub')->name('habits');
    Route::inertia('/notes', 'Stub')->name('notes');
    Route::inertia('/journal', 'Stub')->name('journal');
    Route::inertia('/meals', 'Stub')->name('meals');
    Route::inertia('/groceries', 'Stub')->name('groceries');
    Route::inertia('/pets', 'Stub')->name('pets');
    Route::prefix('money')->name('money.')->group(function (): void {
        Route::get('/', MoneyOverviewController::class)->name('overview');
        Route::get('/debts', [DebtController::class, 'index'])->name('debts.index');
        Route::post('/debts', [DebtController::class, 'store'])->name('debts.store');
        Route::post('/debts/allocate', PaymentAllocationController::class)->name('debts.allocate');
        Route::get('/debts/{debt}', [DebtController::class, 'show'])->name('debts.show');
        Route::post('/debts/{debt}/clearing', [DebtController::class, 'clearing'])->name('debts.clearing');
        Route::patch('/debts/{debt}', [DebtController::class, 'update'])->name('debts.update');
        Route::delete('/debts/{debt}', [DebtController::class, 'destroy'])->name('debts.destroy');
        Route::post('/debts/{debt}/plans/preview', [PaymentPlanController::class, 'preview'])->name('plans.preview');
        Route::post('/debts/{debt}/plans', [PaymentPlanController::class, 'store'])->name('plans.store');
        Route::patch('/plans/{paymentPlan}', [PaymentPlanController::class, 'update'])->name('plans.update');
        Route::delete('/plans/{paymentPlan}', [PaymentPlanController::class, 'destroy'])->name('plans.destroy');
        Route::post('/debts/{debt}/payments', [DebtPaymentController::class, 'store'])->name('payments.store');
        Route::delete('/payments/{debtPayment}', [DebtPaymentController::class, 'destroy'])->name('payments.destroy');
        Route::post('/income-sources', [IncomeSourceController::class, 'store'])->name('income-sources.store');
        Route::patch('/income-sources/{incomeSource}', [IncomeSourceController::class, 'update'])->name('income-sources.update');
        Route::delete('/income-sources/{incomeSource}', [IncomeSourceController::class, 'destroy'])->name('income-sources.destroy');
        Route::patch('/recovery', [RecoveryController::class, 'update'])->name('recovery.update');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('/accounts/{account}/reconcile', [AccountController::class, 'reconcile'])->name('accounts.reconcile');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::post('/bills', [BillController::class, 'store'])->name('bills.store');
        Route::patch('/bills/{bill}', [BillController::class, 'update'])->name('bills.update');
        Route::delete('/bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');
        Route::get('/calendar', MoneyCalendarController::class)->name('calendar');
        Route::get('/transactions', [MoneyTransactionsController::class, 'index'])->name('transactions');
        Route::post('/transactions', [MoneyTransactionsController::class, 'store'])->name('transactions.store');
        Route::post('/transactions/import/preview', [StatementImportController::class, 'preview'])->name('transactions.import.preview');
        Route::post('/transactions/import', [StatementImportController::class, 'store'])->name('transactions.import.store');
        Route::patch('/transactions/{transaction}', [MoneyTransactionsController::class, 'update'])->name('transactions.update');
        Route::delete('/transactions/{transaction}', [MoneyTransactionsController::class, 'destroy'])->name('transactions.destroy');
        Route::post('/categories', [CustomCategoryController::class, 'store'])->name('categories.store');
        Route::delete('/categories/{customCategory}', [CustomCategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/plan', MoneyPlanController::class)->name('plan');
        Route::post('/plan/what-if/afford', [WhatIfController::class, 'afford'])->name('what-if.afford');
        Route::post('/plan/what-if/extra-cash', [WhatIfController::class, 'extraCash'])->name('what-if.extra-cash');
        Route::post('/plan/what-if/conversion', [WhatIfController::class, 'conversion'])->name('what-if.conversion');
        Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
        Route::patch('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
        Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');
    });
    Route::inertia('/photos', 'Stub')->name('photos');

    // Legacy redirect — keeps old dashboard links working
    Route::redirect('/dashboard', '/today')->name('dashboard');
});

require __DIR__.'/settings.php';
