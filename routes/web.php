<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BudgetCategoryController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\MoneyController;
use App\Http\Controllers\PayoffPlanController;
use App\Http\Controllers\RecurringBillController;
use App\Http\Controllers\SinkingFundController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskSectionController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VisionBoardItemController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/today', TodayController::class)->name('today');
    Route::post('/vision-board-items', [VisionBoardItemController::class, 'store'])->name('vision-board-items.store');
    Route::patch('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'update'])->name('vision-board-items.update');
    Route::delete('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'destroy'])->name('vision-board-items.destroy');
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
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::get('/accounts/{account}/transactions', [AccountController::class, 'transactions'])->name('accounts.transactions');
    Route::get('/money', [MoneyController::class, 'index'])->name('money');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('/transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
    Route::post('/budget-categories', [BudgetCategoryController::class, 'store'])->name('budget-categories.store');
    Route::patch('/budget-categories/{budgetCategory}', [BudgetCategoryController::class, 'update'])->name('budget-categories.update');
    Route::delete('/budget-categories/{budgetCategory}', [BudgetCategoryController::class, 'destroy'])->name('budget-categories.destroy');
    Route::post('/recurring-bills', [RecurringBillController::class, 'store'])->name('recurring-bills.store');
    Route::patch('/recurring-bills/{recurringBill}', [RecurringBillController::class, 'update'])->name('recurring-bills.update');
    Route::delete('/recurring-bills/{recurringBill}', [RecurringBillController::class, 'destroy'])->name('recurring-bills.destroy');
    Route::post('/recurring-bills/{recurringBill}/pay', [RecurringBillController::class, 'pay'])->name('recurring-bills.pay');
    Route::get('/set-asides', [SinkingFundController::class, 'index'])->name('set-asides');
    Route::post('/sinking-funds', [SinkingFundController::class, 'store'])->name('sinking-funds.store');
    Route::patch('/sinking-funds/{sinkingFund}', [SinkingFundController::class, 'update'])->name('sinking-funds.update');
    Route::delete('/sinking-funds/{sinkingFund}', [SinkingFundController::class, 'destroy'])->name('sinking-funds.destroy');
    Route::get('/debt', [DebtController::class, 'index'])->name('debt');
    Route::post('/debts', [DebtController::class, 'store'])->name('debts.store');
    Route::patch('/debts/{debt}', [DebtController::class, 'update'])->name('debts.update');
    Route::delete('/debts/{debt}', [DebtController::class, 'destroy'])->name('debts.destroy');
    Route::post('/debts/{debt}/payment', [DebtController::class, 'makePayment'])->name('debts.payment');
    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::post('/ledger/accounts', [LedgerController::class, 'storeAccount'])->name('ledger.accounts.store');
    Route::patch('/ledger/accounts/{account}', [LedgerController::class, 'updateAccount'])->name('ledger.accounts.update');
    Route::delete('/ledger/accounts/{account}', [LedgerController::class, 'destroyAccount'])->name('ledger.accounts.destroy');
    Route::post('/ledger/transactions', [LedgerController::class, 'storeTransaction'])->name('ledger.transactions.store');
    Route::get('/payoff-plan', [PayoffPlanController::class, 'index'])->name('payoff-plan');
    Route::post('/payoff-plan/debts', [PayoffPlanController::class, 'storeDebt'])->name('payoff-plan.debts.store');
    Route::patch('/payoff-plan/debts/{debt}', [PayoffPlanController::class, 'updateDebt'])->name('payoff-plan.debts.update');
    Route::delete('/payoff-plan/debts/{debt}', [PayoffPlanController::class, 'destroyDebt'])->name('payoff-plan.debts.destroy');
    Route::post('/payoff-plan/debts/{debt}/link', [PayoffPlanController::class, 'linkDebt'])->name('payoff-plan.debts.link');
    Route::post('/payoff-plan/debts/{debt}/unlink', [PayoffPlanController::class, 'unlinkDebt'])->name('payoff-plan.debts.unlink');
    Route::patch('/payoff-plan/settings', [PayoffPlanController::class, 'updateSettings'])->name('payoff-plan.settings.update');
    Route::inertia('/photos', 'Stub')->name('photos');

    // Legacy redirect — keeps old dashboard links working
    Route::redirect('/dashboard', '/today')->name('dashboard');
});

require __DIR__.'/settings.php';
