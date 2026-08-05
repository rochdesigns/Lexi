<?php

use App\Models\Account;
use App\Models\Debt;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;

test('settings store financial independence planning fields', function () {
    $user = User::factory()->create();

    $setting = Setting::create([
        'user_id' => $user->id,
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'fi_number' => 25000000,
        'fi_expected_return_rate' => 7.5,
        'emergency_fund_target_months' => 4,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $fresh = $setting->fresh();

    expect((float) $fresh->fi_number)->toBe(25000000.0)
        ->and((float) $fresh->fi_expected_return_rate)->toBe(7.5)
        ->and($fresh->emergency_fund_target_months)->toBe(4)
        ->and((float) $fresh->investment_monthly_contribution_target)->toBe(15000.0);
});

test('financial independence fields fall back to sensible defaults', function () {
    $user = User::factory()->create();

    $setting = Setting::create(['user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30])->fresh();

    expect($setting->fi_number)->toBeNull()
        ->and((float) $setting->fi_expected_return_rate)->toBe(6.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and($setting->investment_monthly_contribution_target)->toBeNull();
});

test('accounts store cost basis and emergency fund flag', function () {
    $user = User::factory()->create();

    $investment = Account::factory()->investment()->for($user)->create([
        'balance' => 120000,
        'cost_basis' => 100000,
    ])->fresh();

    $emergency = Account::factory()->emergencyFund()->for($user)->create()->fresh();

    expect((float) $investment->cost_basis)->toBe(100000.0)
        ->and($investment->type)->toBe('investment')
        ->and($investment->is_emergency_fund)->toBeFalse()
        ->and($emergency->is_emergency_fund)->toBeTrue()
        ->and($emergency->type)->toBe('cash');
});

test('a transaction can be linked to a debt and is unlinked when the debt is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
        'type' => 'expense',
        'amount' => 500,
    ]);

    expect($transaction->fresh()->debt->id)->toBe($debt->id)
        ->and($debt->transactions()->pluck('id')->all())->toBe([$transaction->id]);

    $debt->delete();

    expect($transaction->fresh()->debt_id)->toBeNull();
});

test('debt payments record the principal and interest split and cascade with their transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
    ]);

    $payment = \App\Models\DebtPayment::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'amount' => 5000,
        'principal_amount' => 4200,
        'interest_amount' => 800,
        'paid_at' => '2026-08-01',
    ]);

    expect((float) $payment->fresh()->principal_amount)->toBe(4200.0)
        ->and($payment->fresh()->paid_at->toDateString())->toBe('2026-08-01')
        ->and($debt->debtPayments()->count())->toBe(1)
        ->and($payment->transaction->id)->toBe($transaction->id);

    $transaction->delete();

    expect(\App\Models\DebtPayment::find($payment->id))->toBeNull();
});
