<?php

use App\Models\Money\IncomeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('I can add a monthly income source in pesos', function (): void {
    $this->actingAs($this->user)
        ->post('/money/income-sources', ['name' => 'Salary', 'amount' => 30000.5, 'schedule' => 'monthly', 'pay_day_1' => 30, 'pay_day_2' => 15])
        ->assertRedirect();

    $source = $this->user->incomeSources()->sole();
    expect($source->amount)->toBe(3_000_050)->and($source->pay_day_2)->toBeNull();
});

test('a semi-monthly source needs a different second pay day', function (): void {
    $payload = ['name' => 'Salary', 'amount' => 10000, 'schedule' => 'semi_monthly', 'pay_day_1' => 15];

    $this->actingAs($this->user)->post('/money/income-sources', $payload)->assertSessionHasErrors('pay_day_2');
    $this->actingAs($this->user)->post('/money/income-sources', [...$payload, 'pay_day_2' => 15])->assertSessionHasErrors('pay_day_2');
    $this->actingAs($this->user)->post('/money/income-sources', [...$payload, 'pay_day_2' => 30])->assertSessionHasNoErrors();

    expect($this->user->incomeSources()->sole()->payDays())->toBe([15, 30]);
});

test('I can update and delete my income source', function (): void {
    $source = IncomeSource::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch("/money/income-sources/{$source->id}", ['name' => 'Raise', 'amount' => 40000, 'schedule' => 'monthly', 'pay_day_1' => 28])
        ->assertRedirect();
    expect($source->fresh()->only(['name', 'amount', 'pay_day_1']))->toBe(['name' => 'Raise', 'amount' => 4_000_000, 'pay_day_1' => 28]);

    $this->actingAs($this->user)->delete("/money/income-sources/{$source->id}")->assertRedirect();
    expect(IncomeSource::count())->toBe(0);
});

test('I cannot change someone else\'s income source', function (): void {
    $source = IncomeSource::factory()->create();

    $this->actingAs($this->user)->patch("/money/income-sources/{$source->id}", ['name' => 'x', 'amount' => 1, 'schedule' => 'monthly', 'pay_day_1' => 1])->assertForbidden();
    $this->actingAs($this->user)->delete("/money/income-sources/{$source->id}")->assertForbidden();
});

test('guests cannot add income sources', function (): void {
    $this->post('/money/income-sources', [])->assertRedirect('/login');
});
