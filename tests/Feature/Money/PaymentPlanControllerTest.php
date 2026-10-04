<?php

use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->debt = Debt::factory()->for($this->user)->create();
});

function planPayload(array $overrides = []): array
{
    return [
        'description' => 'Laptop',
        'principal' => null,
        'monthly_amount' => 2499.5,
        'total_months' => 12,
        'start_month' => '2026-11',
        'interest_type' => null,
        'in_minimum' => false,
        ...$overrides,
    ];
}

test('a plan stores centavos and the first of the start month, with principal defaulting to the total', function (): void {
    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/plans", planPayload())
        ->assertRedirect();

    $plan = PaymentPlan::firstOrFail();

    expect($plan->debt_id)->toBe($this->debt->id)
        ->and($plan->monthly_amount)->toBe(249_950)
        ->and($plan->principal)->toBe(249_950 * 12)
        ->and($plan->start_month->toDateString())->toBe('2026-11-01')
        ->and($plan->interest_type)->toBeNull()
        ->and($plan->status)->toBe(DebtStatus::Active);
});

test('an amortized plan works out its monthly payment from principal, rate and term', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::Amortized, 'monthly_interest_rate' => 1.0]);

    $this->actingAs($this->user)
        ->post("/money/debts/{$debt->id}/plans", planPayload(['principal' => 12000, 'monthly_amount' => null]))
        ->assertSessionHasNoErrors();

    expect(PaymentPlan::firstOrFail()->monthly_amount)->toBe(106_619);
});

test('an amortized plan needs a principal and a rate on the debt', function (): void {
    $noRate = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::Amortized, 'monthly_interest_rate' => null]);

    $this->actingAs($this->user)
        ->post("/money/debts/{$noRate->id}/plans", planPayload(['principal' => 12000, 'monthly_amount' => null]))
        ->assertSessionHasErrors('interest_type');

    $withRate = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::Amortized, 'monthly_interest_rate' => 1.0]);
    $this->actingAs($this->user)
        ->post("/money/debts/{$withRate->id}/plans", planPayload(['principal' => null, 'monthly_amount' => null]))
        ->assertSessionHasErrors('principal');

    expect(PaymentPlan::count())->toBe(0);
});

test('a 0% or add-on plan needs a monthly amount, and add-on needs the amount borrowed', function (): void {
    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/plans", planPayload(['monthly_amount' => null]))
        ->assertSessionHasErrors('monthly_amount');

    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/plans", planPayload(['interest_type' => 'add_on']))
        ->assertSessionHasErrors('principal');
});

test('a plan cannot use revolving interest', function (): void {
    $this->actingAs($this->user)
        ->post("/money/debts/{$this->debt->id}/plans", planPayload(['interest_type' => 'revolving']))
        ->assertSessionHasErrors('interest_type');
});

test('only a card plan can count toward the minimum payment', function (): void {
    $this->actingAs($this->user)->post("/money/debts/{$this->debt->id}/plans", planPayload(['in_minimum' => true]));
    expect(PaymentPlan::firstOrFail()->in_minimum)->toBeFalse();

    $card = Debt::factory()->for($this->user)->create();
    CardDetail::factory()->for($card)->create();
    $this->actingAs($this->user)->post("/money/debts/{$card->id}/plans", planPayload(['in_minimum' => true]));
    expect($card->paymentPlans()->first()->in_minimum)->toBeTrue();
});

test('a plan can be updated and marked paid off', function (): void {
    $plan = PaymentPlan::factory()->for($this->debt)->create();

    $this->actingAs($this->user)
        ->patch("/money/plans/{$plan->id}", planPayload(['status' => 'paid_off']))
        ->assertRedirect();

    expect($plan->fresh()->status)->toBe(DebtStatus::PaidOff)->and($plan->fresh()->description)->toBe('Laptop');
});

test('a plan can be deleted', function (): void {
    $plan = PaymentPlan::factory()->for($this->debt)->create();

    $this->actingAs($this->user)->delete("/money/plans/{$plan->id}")->assertRedirect();

    expect(PaymentPlan::count())->toBe(0);
});

test('other people cannot add to, change or delete my plans', function (): void {
    $stranger = User::factory()->create();
    $plan = PaymentPlan::factory()->for($this->debt)->create();

    $this->actingAs($stranger)->post("/money/debts/{$this->debt->id}/plans", planPayload())->assertForbidden();
    $this->actingAs($stranger)->patch("/money/plans/{$plan->id}", planPayload())->assertForbidden();
    $this->actingAs($stranger)->delete("/money/plans/{$plan->id}")->assertForbidden();

    expect(PaymentPlan::count())->toBe(1);
});

function previewPayload(array $overrides = []): array
{
    return ['principal' => null, 'monthly_amount' => 1000, 'total_months' => 6, 'interest_type' => null, ...$overrides];
}

test('the preview shows a plan with no interest costing exactly what is paid', function (): void {
    $this->actingAs($this->user)
        ->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload())
        ->assertOk()
        ->assertJsonPath('summary.interestType', 'none')
        ->assertJsonPath('summary.totalRepayment', 600_000)
        ->assertJsonPath('summary.totalInterest', 0)
        ->assertJsonPath('missing', null);

    expect(PaymentPlan::count())->toBe(0);
});

test('the preview shows the fixed interest on an add-on plan and that paying early does not save it', function (): void {
    $this->actingAs($this->user)
        ->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload(['interest_type' => 'add_on', 'principal' => 10000, 'monthly_amount' => 1900]))
        ->assertOk()
        ->assertJsonPath('summary.totalRepayment', 1_140_000)
        ->assertJsonPath('summary.totalInterest', 140_000)
        ->assertJsonPath('summary.earlyPayoffSavesInterest', false)
        ->assertJsonPath('summary.repaysLessThanBorrowed', false);
});

test('the preview warns when an add-on plan repays less than was borrowed', function (): void {
    $this->actingAs($this->user)
        ->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload(['interest_type' => 'add_on', 'principal' => 10000, 'monthly_amount' => 1000]))
        ->assertJsonPath('summary.repaysLessThanBorrowed', true);
});

test('the preview works out an amortized payment and its interest', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::Amortized, 'monthly_interest_rate' => 1.0]);

    $response = $this->actingAs($this->user)
        ->postJson("/money/debts/{$debt->id}/plans/preview", previewPayload(['principal' => 12000, 'monthly_amount' => null, 'total_months' => 12]))
        ->assertOk()
        ->assertJsonPath('summary.monthlyAmount', 106_619)
        ->assertJsonPath('summary.earlyPayoffSavesInterest', true);

    expect($response->json('summary.totalInterest'))->toBe($response->json('summary.totalRepayment') - 1_200_000)
        ->and($response->json('summary.totalInterest'))->toBeGreaterThan(70_000)->toBeLessThan(80_000);
});

test('the preview treats an installment on a card as having no interest', function (): void {
    $card = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::Revolving, 'monthly_interest_rate' => 3.5]);

    $this->actingAs($this->user)
        ->postJson("/money/debts/{$card->id}/plans/preview", previewPayload())
        ->assertJsonPath('summary.interestType', 'none')
        ->assertJsonPath('summary.totalInterest', 0);
});

test('the preview says what is missing instead of guessing', function (): void {
    $noRate = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::Amortized, 'monthly_interest_rate' => null]);

    $this->actingAs($this->user)->postJson("/money/debts/{$noRate->id}/plans/preview", previewPayload(['principal' => 12000]))
        ->assertJsonPath('summary', null)->assertJsonPath('missing', 'rate');
    $this->actingAs($this->user)->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload(['total_months' => null]))
        ->assertJsonPath('missing', 'months');
    $this->actingAs($this->user)->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload(['monthly_amount' => null]))
        ->assertJsonPath('missing', 'monthly');
    $this->actingAs($this->user)->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload(['interest_type' => 'add_on']))
        ->assertJsonPath('missing', 'principal');
});

test('other people cannot preview a plan on my debt', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson("/money/debts/{$this->debt->id}/plans/preview", previewPayload())
        ->assertForbidden();
});
