<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\PayoffPlanController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskSectionController;
use App\Http\Controllers\TodayController;
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
    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::post('/ledger/accounts', [LedgerController::class, 'storeAccount'])->name('ledger.accounts.store');
    Route::patch('/ledger/accounts/{account}', [LedgerController::class, 'updateAccount'])->name('ledger.accounts.update');
    Route::delete('/ledger/accounts/{account}', [LedgerController::class, 'destroyAccount'])->name('ledger.accounts.destroy');
    Route::post('/ledger/transactions', [LedgerController::class, 'storeTransaction'])->name('ledger.transactions.store');
    Route::patch('/ledger/transactions/{transaction}', [LedgerController::class, 'updateTransaction'])->name('ledger.transactions.update');
    Route::delete('/ledger/transactions/{transaction}', [LedgerController::class, 'destroyTransaction'])->name('ledger.transactions.destroy');
    Route::post('/ledger/debt-payment/preview', [LedgerController::class, 'previewDebtPayment'])->name('ledger.debt-payment.preview');
    Route::get('/payoff-plan', [PayoffPlanController::class, 'index'])->name('payoff-plan');
    Route::post('/payoff-plan/debts', [PayoffPlanController::class, 'storeDebt'])->name('payoff-plan.debts.store');
    Route::post('/payoff-plan/debts/preview', [PayoffPlanController::class, 'previewDebt'])->name('payoff-plan.debts.preview');
    Route::patch('/payoff-plan/debts/{debt}', [PayoffPlanController::class, 'updateDebt'])->name('payoff-plan.debts.update');
    Route::delete('/payoff-plan/debts/{debt}', [PayoffPlanController::class, 'destroyDebt'])->name('payoff-plan.debts.destroy');
    Route::post('/payoff-plan/debts/{debt}/link', [PayoffPlanController::class, 'linkDebt'])->name('payoff-plan.debts.link');
    Route::post('/payoff-plan/debts/{debt}/unlink', [PayoffPlanController::class, 'unlinkDebt'])->name('payoff-plan.debts.unlink');
    Route::post('/payoff-plan/debts/{debt}/extra', [PayoffPlanController::class, 'sendExtra'])->name('payoff-plan.debts.extra');
    Route::patch('/payoff-plan/settings', [PayoffPlanController::class, 'updateSettings'])->name('payoff-plan.settings.update');
    Route::patch('/payoff-plan/priority', [PayoffPlanController::class, 'setPriorityDebt'])->name('payoff-plan.priority.update');
    Route::get('/forecast', [ForecastController::class, 'index'])->name('forecast.index');
    Route::patch('/forecast/income', [ForecastController::class, 'updateIncome'])->name('forecast.income.update');
    Route::get('/budget', [BudgetController::class, 'index'])->name('budget.index');
    Route::post('/budget/bills', [BudgetController::class, 'storeBill'])->name('budget.bills.store');
    Route::patch('/budget/bills/{bill}', [BudgetController::class, 'updateBill'])->name('budget.bills.update');
    Route::delete('/budget/bills/{bill}', [BudgetController::class, 'destroyBill'])->name('budget.bills.destroy');
    Route::post('/budget/envelopes', [BudgetController::class, 'storeEnvelope'])->name('budget.envelopes.store');
    Route::patch('/budget/envelopes/{envelope}', [BudgetController::class, 'updateEnvelope'])->name('budget.envelopes.update');
    Route::delete('/budget/envelopes/{envelope}', [BudgetController::class, 'destroyEnvelope'])->name('budget.envelopes.destroy');
    Route::post('/budget/bill-periods/{billPeriod}/pay', [BudgetController::class, 'markBillPaid'])->name('budget.bill-periods.pay');
    Route::post('/budget/bill-periods/pay', [BudgetController::class, 'markBillsPaid'])->name('budget.bill-periods.pay-many');
    Route::delete('/budget/bill-periods/{billPeriod}/pay', [BudgetController::class, 'unmarkBillPaid'])->name('budget.bill-periods.unpay');
    Route::post('/budget/deal', [BudgetController::class, 'dealAcrossEnvelopes'])->name('budget.deal');
    Route::post('/budget/envelope-periods/{envelopePeriod}/cover', [BudgetController::class, 'coverOverspend'])->name('budget.envelope-periods.cover');
    Route::post('/budget/envelope-periods/{envelopePeriod}/sweep/confirm', [BudgetController::class, 'confirmSweep'])->name('budget.envelope-periods.sweep.confirm');
    Route::post('/budget/envelope-periods/{envelopePeriod}/sweep/decline', [BudgetController::class, 'declineSweep'])->name('budget.envelope-periods.sweep.decline');
    Route::post('/budget/extra-payment/confirm', [BudgetController::class, 'confirmExtraPayment'])->name('budget.extra-payment.confirm');
    Route::post('/budget/debt-minimums/{debt}/pay', [BudgetController::class, 'payDebtMinimum'])->name('budget.debt-minimums.pay');
    Route::inertia('/photos', 'Stub')->name('photos');

    // Legacy redirect — keeps old dashboard links working
    Route::redirect('/dashboard', '/today')->name('dashboard');
});

require __DIR__.'/settings.php';
