<?php

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;

test('a bill has many bill periods', function () {
    $bill = Bill::factory()->create();
    BillPeriod::factory()->for($bill)->create(['month' => 1]);
    BillPeriod::factory()->for($bill)->create(['month' => 2]);

    expect($bill->periods)->toHaveCount(2);
});

test('deleting a bill cascades its periods', function () {
    $bill = Bill::factory()->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $bill->delete();

    expect(BillPeriod::find($period->id))->toBeNull();
});

test('an envelope has many envelope periods', function () {
    $envelope = Envelope::factory()->create();
    EnvelopePeriod::factory()->for($envelope)->create();

    expect($envelope->periods)->toHaveCount(1);
});

test('deleting an envelope cascades its periods and nulls tagged transactions', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create();
    $transaction = Transaction::factory()->for($user)->create(['envelope_id' => $envelope->id]);

    $envelope->delete();

    expect(EnvelopePeriod::find($period->id))->toBeNull();
    expect($transaction->fresh()->envelope_id)->toBeNull();
});

test('bill and envelope active flags default to true and cast to boolean', function () {
    $bill = Bill::factory()->create();
    $envelope = Envelope::factory()->create();

    expect($bill->active)->toBeTrue();
    expect($envelope->active)->toBeTrue();
});

test('a user has many debt focus bills and envelopes', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create();
    Envelope::factory()->for($user)->create();

    expect($user->debtFocusBills)->toHaveCount(1);
    expect($user->debtFocusEnvelopes)->toHaveCount(1);
});
