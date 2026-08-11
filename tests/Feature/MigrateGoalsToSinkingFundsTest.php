<?php

use App\Models\SinkingFund;
use App\Models\User;

test('migrating goals to sinking funds copies fields, does not touch transactions, and is reversible', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash', 'balance' => 1000]);

    $goal = $user->goals()->create([
        'name' => 'Test Goal', 'icon' => 'flag', 'color' => '#111111', 'bg' => '#eeeeee',
        'saved' => 500, 'target' => 1000, 'per_month' => 100, 'by_date' => '2027-01-01', 'position' => 0,
    ]);

    $noDateGoal = $user->goals()->create([
        'name' => 'No Date Goal', 'icon' => 'flag', 'color' => '#222222', 'bg' => '#dddddd',
        'saved' => 0, 'target' => 500, 'per_month' => 0, 'by_date' => null, 'position' => 1,
    ]);

    $otherUserGoal = $otherUser->goals()->create([
        'name' => 'Other User Goal', 'icon' => 'flag', 'color' => '#444444', 'bg' => '#bbbbbb',
        'saved' => 10, 'target' => 20, 'per_month' => 5, 'by_date' => null, 'position' => 0,
    ]);

    $transaction = $user->transactions()->create([
        'account_id' => $account->id, 'goal_id' => $goal->id, 'type' => 'expense',
        'amount' => 500, 'transacted_at' => '2026-08-01',
    ]);

    $existingFund = $user->sinkingFunds()->create([
        'name' => 'Existing Fund', 'icon' => 'savings', 'color' => '#333333', 'bg' => '#cccccc',
        'saved' => 0, 'target' => 100, 'per_month' => 10, 'due_date' => null, 'position' => 5,
    ]);
    // otherUser has a set-aside at a HIGH position — if the migration computed positions
    // globally instead of per-user, otherUser's migrated fund would collide with or be
    // misordered relative to this one.
    $otherExistingFund = $otherUser->sinkingFunds()->create([
        'name' => 'Other Existing Fund', 'icon' => 'savings', 'color' => '#555555', 'bg' => '#aaaaaa',
        'saved' => 0, 'target' => 50, 'per_month' => 5, 'due_date' => null, 'position' => 40,
    ]);

    $migration = include database_path('migrations/2026_08_11_190000_migrate_goals_to_sinking_funds_table.php');
    $migration->up();

    $fund = SinkingFund::where('migrated_goal_id', $goal->id)->first();
    expect($fund)->not->toBeNull();
    expect($fund->name)->toBe('Test Goal');
    expect((float) $fund->saved)->toBe(500.0);
    expect((float) $fund->target)->toBe(1000.0);
    expect((float) $fund->per_month)->toBe(100.0);
    expect($fund->due_date->toDateString())->toBe('2027-01-01');
    // Positions append after whatever set-asides already existed (Existing Fund is position 5),
    // and the two migrated funds for this user land on consecutive positions.
    expect($fund->position)->toBeGreaterThan($existingFund->position);

    $noDateFund = SinkingFund::where('migrated_goal_id', $noDateGoal->id)->first();
    expect($noDateFund)->not->toBeNull();
    expect($noDateFund->due_date)->toBeNull();
    expect($noDateFund->position)->not->toBe($fund->position);

    $otherFund = SinkingFund::where('migrated_goal_id', $otherUserGoal->id)->first();
    expect($otherFund)->not->toBeNull();
    // otherUser's migrated fund must be scoped to otherUser's own positions (40), not user's (5).
    expect($otherFund->position)->toBeGreaterThan($otherExistingFund->position);

    // The transaction is untouched — sinking_fund_id was never set, goal_id was never cleared.
    $transaction->refresh();
    expect($transaction->sinking_fund_id)->toBeNull();
    expect($transaction->goal_id)->toBe($goal->id);

    $migration->down();

    expect(SinkingFund::where('migrated_goal_id', $goal->id)->exists())->toBeFalse();
    expect(SinkingFund::where('migrated_goal_id', $noDateGoal->id)->exists())->toBeFalse();
    expect(SinkingFund::where('migrated_goal_id', $otherUserGoal->id)->exists())->toBeFalse();
    expect(SinkingFund::find($existingFund->id))->not->toBeNull(); // untouched by the reversal
    expect(SinkingFund::find($otherExistingFund->id))->not->toBeNull();

    $transaction->refresh();
    expect($transaction->goal_id)->toBe($goal->id); // still preserved after rollback

    $goal->refresh();
    expect($goal->exists)->toBeTrue(); // goals themselves are never touched, either direction
});
