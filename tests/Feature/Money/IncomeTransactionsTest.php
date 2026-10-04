<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\IncomeSource;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\Money\Transaction;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'GCash', 'balance' => 100_000]);
});

function income(array $overrides = []): array
{
    return ['kind' => 'income', 'date' => '2026-10-15', 'amount' => 10000, 'note' => 'Salary', ...$overrides];
}

test('income is paid into an account and adds to its balance', function (): void {
    $this->actingAs($this->user)->post('/money/transactions', income(['account_id' => $this->account->id]))->assertRedirect();

    $received = $this->user->transactions()->sole();
    expect($received->kind->value)->toBe('income')
        ->and($received->category->value)->toBe('income')
        ->and($received->amount)->toBe(1_000_000)
        ->and($received->account_id)->toBe($this->account->id)
        ->and($this->account->fresh()->balance)->toBe(1_100_000);
});

test('income needs an account and cannot go on a card or have a category', function (): void {
    $card = Debt::factory()->for($this->user)->create(['type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving]);

    $this->actingAs($this->user)->post('/money/transactions', income())->assertSessionHasErrors('account_id');
    $this->actingAs($this->user)->post('/money/transactions', income(['debt_id' => $card->id]))->assertSessionHasErrors('debt_id');
    $this->actingAs($this->user)->post('/money/transactions', income(['account_id' => $this->account->id, 'category' => 'food']))->assertSessionHasErrors('category');
    expect(Transaction::count())->toBe(0)->and($this->account->fresh()->balance)->toBe(100_000);
});

test('income can point at my own income source and pay day, not someone else\'s', function (): void {
    $mine = IncomeSource::factory()->for($this->user)->create();
    $theirs = IncomeSource::factory()->create();

    $this->actingAs($this->user)->post('/money/transactions', income(['account_id' => $this->account->id, 'income_source_id' => $theirs->id]))->assertSessionHasErrors('income_source_id');
    $this->actingAs($this->user)->post('/money/transactions', income(['account_id' => $this->account->id, 'income_source_id' => $mine->id, 'pay_date' => '2026-10-30']))->assertSessionHasNoErrors();

    expect($this->user->transactions()->sole()->only(['income_source_id']))->toBe(['income_source_id' => $mine->id])
        ->and($this->user->transactions()->sole()->pay_date->toDateString())->toBe('2026-10-30');
});

test('an expense cannot name an income source or use the income category', function (): void {
    $source = IncomeSource::factory()->for($this->user)->create();
    $expense = ['date' => '2026-10-15', 'amount' => 100, 'category' => 'food', 'account_id' => $this->account->id];

    $this->actingAs($this->user)->post('/money/transactions', [...$expense, 'income_source_id' => $source->id])->assertSessionHasErrors('income_source_id');
    $this->actingAs($this->user)->post('/money/transactions', [...$expense, 'category' => 'income'])->assertSessionHasErrors('category');
});

test('editing income moves only the difference and deleting it takes it back', function (): void {
    $other = Account::factory()->for($this->user)->create(['balance' => 0]);
    $this->actingAs($this->user)->post('/money/transactions', income(['account_id' => $this->account->id]));
    $received = $this->user->transactions()->sole();

    $this->actingAs($this->user)->patch("/money/transactions/{$received->id}", income(['account_id' => $this->account->id, 'amount' => 12000]))->assertRedirect();
    expect($this->account->fresh()->balance)->toBe(1_300_000);

    $this->actingAs($this->user)->patch("/money/transactions/{$received->id}", income(['account_id' => $other->id, 'amount' => 12000]))->assertRedirect();
    expect($this->account->fresh()->balance)->toBe(100_000)->and($other->fresh()->balance)->toBe(1_200_000);

    $this->actingAs($this->user)->delete("/money/transactions/{$received->id}")->assertRedirect();
    expect($other->fresh()->balance)->toBe(0)->and(Transaction::count())->toBe(0);
});

test('income stays income when edited, so it cannot pick up a category', function (): void {
    $received = Transaction::factory()->for($this->user)->income()->create(['account_id' => $this->account->id]);

    $this->actingAs($this->user)->patch("/money/transactions/{$received->id}", ['kind' => 'expense', 'date' => '2026-10-15', 'amount' => 5, 'category' => 'food', 'account_id' => $this->account->id])
        ->assertSessionHasErrors('category');
    expect($received->fresh()->kind->value)->toBe('income');
});

test('the page lists income with spending but totals them apart', function (): void {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-10', 'amount' => 30_000, 'category' => 'food']);
    Transaction::factory()->for($this->user)->income()->create(['account_id' => $this->account->id, 'date' => '2026-10-12', 'amount' => 1_000_000]);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page
        ->has('transactions', 2)
        ->where('transactions.0.kind', 'income')
        ->where('transactions.1.kind', 'expense')
        ->where('total', 30_000)
        ->where('received', 1_000_000)
        ->where('categoryTotals', [['category' => 'food', 'total' => 30_000]]));
});

test('logging income does not change which payer the form remembers', function (): void {
    Transaction::factory()->for($this->user)->income()->create(['account_id' => $this->account->id]);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page->where('lastPaidWith', null));
});

test('the received link opens the form with that income filled in', function (): void {
    $source = IncomeSource::factory()->for($this->user)->create(['name' => 'Salary', 'amount' => 2_500_000]);
    $theirs = IncomeSource::factory()->create();

    $this->actingAs($this->user)->get("/money/transactions?income={$source->id}&date=2026-10-30")->assertInertia(fn ($page) => $page
        ->where('prefill', ['incomeSourceId' => $source->id, 'name' => 'Salary', 'amount' => 2_500_000, 'date' => '2026-10-30'])
        ->has('incomeSources', 1));
    $this->actingAs($this->user)->get("/money/transactions?income={$source->id}&date=nonsense")->assertInertia(fn ($page) => $page->where('prefill.date', '2026-10-15'));
    $this->actingAs($this->user)->get("/money/transactions?income={$theirs->id}")->assertInertia(fn ($page) => $page->where('prefill', null));
});

function paydayWorld(User $user, Account $account): void
{
    $loan = Debt::factory()->for($user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 1_900_000, 'due_day' => 25]);
}

function forecastShortfalls(User $user): array
{
    return app(Projection::class)->shortfalls(
        $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $user->bills()->get(),
        $user->incomeSources()->with('receipts')->get(),
        $user->accounts()->get(),
        Carbon::today(),
    );
}

test('a pay day that has landed is not counted again in the forecast', function (): void {
    paydayWorld($this->user, $this->account);
    $source = IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000, 'pay_day_1' => 18]);

    // Not received: the 10,000.00 pay day on the 18th is expected, so 1,000.00 + 10,000.00 covers the 5,000.00 loan.
    expect(array_column(forecastShortfalls($this->user), 'name'))->toBe(['Rent']);
    expect(forecastShortfalls($this->user)[0]['short'])->toBe(1_900_000 - (100_000 + 1_000_000 - 500_000));

    // Received: the balance now holds it, and the 18th must not add it a second time.
    $this->actingAs($this->user)->post('/money/transactions', income(['account_id' => $this->account->id, 'amount' => 10000, 'income_source_id' => $source->id, 'pay_date' => '2026-10-18']));

    expect($this->account->fresh()->balance)->toBe(1_100_000)
        ->and(forecastShortfalls($this->user)[0])->toMatchArray(['name' => 'Rent', 'short' => 1_900_000 - (1_100_000 - 500_000)]);
});

test('income logged without a pay day settles a pay day within three days', function (): void {
    $source = IncomeSource::factory()->for($this->user)->create(['pay_day_1' => 18]);
    $received = fn (): bool => collect(app(Projection::class)->upcoming(collect(), collect(), IncomeSource::with('receipts')->get(), Carbon::today()))->firstWhere('kind', 'income')['received'];

    expect($received())->toBeFalse();

    $receipt = Transaction::factory()->for($this->user)->income()->create(['account_id' => $this->account->id, 'income_source_id' => $source->id, 'date' => '2026-10-14']);
    expect($received())->toBeFalse();

    $receipt->update(['date' => '2026-10-15']);
    expect($received())->toBeTrue();
});

test('a pay day is only settled by income for that source and that pay day', function (): void {
    $source = IncomeSource::factory()->for($this->user)->create(['pay_day_1' => 18]);
    $another = IncomeSource::factory()->for($this->user)->create(['name' => 'Another', 'pay_day_1' => 28]);
    Transaction::factory()->for($this->user)->income()->create(['account_id' => $this->account->id, 'income_source_id' => $source->id, 'date' => '2026-10-15', 'pay_date' => '2026-11-18']);

    $items = collect(app(Projection::class)->upcoming(collect(), collect(), IncomeSource::with('receipts')->get(), Carbon::today(), 20));

    expect($items->firstWhere('sourceId', $source->id)['received'])->toBeFalse()
        ->and($items->firstWhere('sourceId', $another->id)['received'])->toBeFalse();
});

test('the overview and the calendar show which pay days have landed', function (): void {
    $source = IncomeSource::factory()->for($this->user)->create(['name' => 'Salary', 'pay_day_1' => 18]);
    Transaction::factory()->for($this->user)->income()->create(['account_id' => $this->account->id, 'income_source_id' => $source->id, 'date' => '2026-10-18', 'pay_date' => '2026-10-18']);

    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('upcoming.0.sourceId', $source->id)
        ->where('upcoming.0.received', true));
    $this->actingAs($this->user)->get('/money/calendar')->assertInertia(fn ($page) => $page
        ->where('days.0.date', '2026-10-18')
        ->where('days.0.items.0.received', true)
        ->where('days.0.items.0.sourceId', $source->id));
});
