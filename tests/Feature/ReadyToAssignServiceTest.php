<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EnvelopeActivityService;
use App\Services\EnvelopeAvailabilityService;
use App\Services\EnvelopeService;
use App\Services\ReadyToAssignService;

test('ready to assign is on-budget balance minus assigned, excluding investment accounts', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 1000]);
    Account::factory()->for($user)->create(['type' => 'credit', 'balance' => -200]);
    Account::factory()->for($user)->create(['type' => 'investment', 'balance' => 5000]);

    $rta = (new ReadyToAssignService(new EnvelopeService, new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService)))->compute($user);

    expect($rta)->toBe(800.0);
});

test('assigning to an envelope reduces ready to assign', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 1000]);
    BudgetCategory::factory()->for($user)->create(['limit_amount' => 300]);

    $rta = (new ReadyToAssignService(new EnvelopeService, new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService)))->compute($user);

    expect($rta)->toBe(700.0);
});

test('overspending drops ready to assign immediately by draining the account balance, without changing assigned', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 1000]);
    BudgetCategory::factory()->for($user)->create(['limit_amount' => 300]);
    $category = BudgetCategory::where('user_id', $user->id)->first();
    Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'budget_category_id' => $category->id,
        'type' => 'expense', 'amount' => 100, 'transacted_at' => now()->toDateString(),
    ]);
    $account->decrement('balance', 100);

    $rta = (new ReadyToAssignService(new EnvelopeService, new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService)))->compute($user);

    expect($rta)->toBe(600.0);
});
