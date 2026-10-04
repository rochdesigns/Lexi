<?php

use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Models\Money\PaymentPlan;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->debt = Debt::factory()->for($this->user)->create();
});

function paymentPayload(array $overrides = []): array
{
    return ['payment_plan_id' => null, 'date' => '2026-10-05', 'amount' => 1500.25, 'is_extra' => false, ...$overrides];
}

test('a payment is recorded as centavos', function (): void {
    $plan = PaymentPlan::factory()->for($this->debt)->create();

    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/payments", paymentPayload(['payment_plan_id' => $plan->id, 'is_extra' => true]))
        ->assertRedirect();

    $payment = DebtPayment::firstOrFail();
    expect($payment->amount)->toBe(150_025)
        ->and($payment->payment_plan_id)->toBe($plan->id)
        ->and($payment->is_extra)->toBeTrue()
        ->and($payment->date->toDateString())->toBe('2026-10-05');
});

test('a payment needs a positive amount and a date', function (): void {
    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/payments", paymentPayload(['amount' => 0, 'date' => '']))
        ->assertSessionHasErrors(['amount', 'date']);
});

test('a payment cannot point at another debt\'s plan', function (): void {
    $otherPlan = PaymentPlan::factory()->create();

    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/payments", paymentPayload(['payment_plan_id' => $otherPlan->id]))
        ->assertSessionHasErrors('payment_plan_id');

    expect(DebtPayment::count())->toBe(0);
});

test('paying an informal debt brings its balance down and never below zero', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['type' => DebtType::Informal, 'outstanding_balance' => 500_000, 'due_day' => null]);

    $this->actingAs($this->user)->post("/money/debts/{$debt->id}/payments", paymentPayload(['amount' => 2000]));
    expect($debt->fresh()->outstanding_balance)->toBe(300_000);

    $this->actingAs($this->user)->post("/money/debts/{$debt->id}/payments", paymentPayload(['amount' => 9000]));
    expect($debt->fresh()->outstanding_balance)->toBe(0);
});

test('paying a debt with a schedule leaves its balance to the schedule', function (): void {
    $this->actingAs($this->user)->post("/money/debts/{$this->debt->id}/payments", paymentPayload());

    expect($this->debt->fresh()->outstanding_balance)->toBeNull();
});

test('deleting a payment on an informal debt puts the balance back', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['type' => DebtType::Informal, 'outstanding_balance' => 300_000, 'due_day' => null]);
    $payment = $debt->payments()->create(['date' => '2026-10-05', 'amount' => 200_000, 'is_extra' => false]);

    $this->actingAs($this->user)->delete("/money/payments/{$payment->id}")->assertRedirect();

    expect(DebtPayment::count())->toBe(0)->and($debt->fresh()->outstanding_balance)->toBe(500_000);
});

test('other people cannot record or delete payments on my debt', function (): void {
    $stranger = User::factory()->create();
    $payment = $this->debt->payments()->create(['date' => '2026-10-05', 'amount' => 100, 'is_extra' => false]);

    $this->actingAs($stranger)->post("/money/debts/{$this->debt->id}/payments", paymentPayload())->assertForbidden();
    $this->actingAs($stranger)->delete("/money/payments/{$payment->id}")->assertForbidden();

    expect(DebtPayment::count())->toBe(1);
});
