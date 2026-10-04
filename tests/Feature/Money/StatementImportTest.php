<?php

use App\Models\Money\Account;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionSource;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->card = Debt::factory()->for($this->user)->create(['name' => 'Platinum', 'type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving]);
    CardDetail::factory()->for($this->card)->create(['statement_day' => 10, 'revolving_balance' => 100_000]);
});

function manualCharge(User $user, Debt $card, string $date, int $amount, ?string $note = 'Lunch'): Transaction
{
    return Transaction::factory()->for($user)->onCard($card)->create(['date' => $date, 'amount' => $amount, 'note' => $note, 'category' => TransactionCategory::Food]);
}

function previewStatement(User $user, Debt $card, string $csv)
{
    return test()->actingAs($user)->postJson('/money/transactions/import/preview', ['debt_id' => $card->id, 'csv' => $csv]);
}

function importStatement(User $user, Debt $card, string $csv)
{
    return test()->actingAs($user)->post('/money/transactions/import', ['debt_id' => $card->id, 'csv' => $csv]);
}

test('a charge on the statement is new, matched to my entry, or already imported', function (): void {
    $lunch = manualCharge($this->user, $this->card, '2026-10-12', 25_050);

    $response = previewStatement($this->user, $this->card, "2026-10-13,JOLLIBEE,250.50\n2026-10-20,Grab,100\n2026-10-14,Other,999")->assertOk();

    $response->assertJsonPath('rows.0.status', 'matches')
        ->assertJsonPath('rows.0.matched.id', $lunch->id)
        ->assertJsonPath('rows.0.matched.category', 'food')
        ->assertJsonPath('rows.1.status', 'new')
        ->assertJsonPath('rows.2.status', 'new')
        ->assertJsonPath('counts', ['new' => 2, 'matches' => 1, 'imported' => 0]);
});

test('a match needs the same amount within three days', function (): void {
    manualCharge($this->user, $this->card, '2026-10-12', 25_050);

    previewStatement($this->user, $this->card, "2026-10-15,Three days,250.50\n2026-10-16,Four days,250.50\n2026-10-12,Other amount,250.51")
        ->assertJsonPath('rows.0.status', 'matches')
        ->assertJsonPath('rows.1.status', 'new')
        ->assertJsonPath('rows.2.status', 'new');
});

test('one of my entries matches only one statement row, the closest in date first', function (): void {
    $early = manualCharge($this->user, $this->card, '2026-10-10', 10_000);
    $late = manualCharge($this->user, $this->card, '2026-10-13', 10_000);

    previewStatement($this->user, $this->card, "2026-10-12,First,100\n2026-10-12,Second,100\n2026-10-12,Third,100")
        ->assertJsonPath('rows.0.matched.id', $late->id)
        ->assertJsonPath('rows.1.matched.id', $early->id)
        ->assertJsonPath('rows.2.status', 'new');
});

test('other cards and account expenses are never matched', function (): void {
    $other = Debt::factory()->for($this->user)->create(['type' => DebtType::CreditCard]);
    manualCharge($this->user, $other, '2026-10-12', 25_050);
    Transaction::factory()->for($this->user)->create(['account_id' => Account::factory()->for($this->user), 'date' => '2026-10-12', 'amount' => 25_050]);

    previewStatement($this->user, $this->card, '2026-10-12,Lunch,250.50')->assertJsonPath('rows.0.status', 'new');
});

test('importing adds the new rows and links the matches without counting them twice', function (): void {
    $lunch = manualCharge($this->user, $this->card, '2026-10-12', 25_050);
    $balance = Account::factory()->for($this->user)->create(['balance' => 500_000])->balance;

    importStatement($this->user, $this->card, "2026-10-13,JOLLIBEE,250.50\n2026-10-20,Grab,100")->assertRedirect();

    $imported = Transaction::where('source', TransactionSource::StatementImport)->orderBy('id')->get();
    expect($imported)->toHaveCount(2)
        ->and($imported[0]->only(['matched_transaction_id', 'amount', 'note']))->toBe(['matched_transaction_id' => $lunch->id, 'amount' => 25_050, 'note' => 'JOLLIBEE'])
        ->and($imported[0]->category)->toBe(TransactionCategory::Food)
        ->and($imported[1]->only(['matched_transaction_id', 'amount', 'debt_id', 'account_id']))->toBe(['matched_transaction_id' => null, 'amount' => 10_000, 'debt_id' => $this->card->id, 'account_id' => null])
        ->and($imported[1]->category)->toBe(TransactionCategory::Other)
        ->and(Account::where('user_id', $this->user->id)->first()->balance)->toBe($balance);

    $this->actingAs($this->user)->get('/money/transactions?month=2026-10')->assertInertia(fn ($page) => $page
        ->has('transactions', 2)
        ->where('total', 25_050 + 10_000)
        ->where('transactions.1.onStatement', true)
        ->where('transactions.0.onStatement', false));
});

test('importing the same statement again adds nothing', function (): void {
    manualCharge($this->user, $this->card, '2026-10-12', 25_050);
    $csv = "2026-10-13,JOLLIBEE,250.50\n2026-10-20,Grab,100";

    importStatement($this->user, $this->card, $csv);
    $count = Transaction::count();
    importStatement($this->user, $this->card, $csv);

    expect(Transaction::count())->toBe($count);
    previewStatement($this->user, $this->card, $csv)->assertJsonPath('counts', ['new' => 0, 'matches' => 0, 'imported' => 2]);
});

test('two identical charges on a statement both come in, and only the extra one on a later statement', function (): void {
    importStatement($this->user, $this->card, "2026-10-12,Coffee,150\n2026-10-12,Coffee,150");
    expect(Transaction::count())->toBe(2);

    previewStatement($this->user, $this->card, "2026-10-12,Coffee,150\n2026-10-12,Coffee,150\n2026-10-12,Coffee,150")
        ->assertJsonPath('counts', ['new' => 1, 'matches' => 0, 'imported' => 2]);
});

test('imported rows that matched do not feed the projected statement twice', function (): void {
    manualCharge($this->user, $this->card, '2026-10-12', 25_000);
    importStatement($this->user, $this->card, '2026-10-12,Lunch,250');

    $card = $this->card->fresh(['cardDetail', 'paymentPlans.debt', 'transactions']);

    expect(app(Projection::class)->projectedNextStatement($card, Carbon::today()))->toBe(100_000 + 25_000);
});

test('deleting my entry leaves the statement row as a real charge', function (): void {
    $lunch = manualCharge($this->user, $this->card, '2026-10-12', 25_000);
    importStatement($this->user, $this->card, '2026-10-12,Lunch,250');

    $this->actingAs($this->user)->delete("/money/transactions/{$lunch->id}")->assertRedirect();

    $this->actingAs($this->user)->get('/money/transactions?month=2026-10')->assertInertia(fn ($page) => $page->has('transactions', 1)->where('total', 25_000));
});

test('imported rows do not change which payer the form remembers', function (): void {
    importStatement($this->user, $this->card, '2026-10-12,Lunch,250');

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page->where('lastPaidWith', null));
});

test('the import needs one of my cards and some charges', function (): void {
    $loan = Debt::factory()->for($this->user)->create();
    $theirs = Debt::factory()->create(['type' => DebtType::CreditCard]);
    $csv = '2026-10-12,Lunch,250';

    $this->actingAs($this->user)->postJson('/money/transactions/import/preview', ['debt_id' => $loan->id, 'csv' => $csv])->assertJsonValidationErrors('debt_id');
    $this->actingAs($this->user)->postJson('/money/transactions/import/preview', ['debt_id' => $theirs->id, 'csv' => $csv])->assertJsonValidationErrors('debt_id');
    $this->actingAs($this->user)->postJson('/money/transactions/import/preview', ['debt_id' => $this->card->id, 'csv' => ''])->assertJsonValidationErrors('csv');
    $this->actingAs($this->user)->postJson('/money/transactions/import/preview', ['debt_id' => $this->card->id, 'csv' => "Date,Amount\nnothing here"])->assertJsonValidationErrors('csv');
    expect(Transaction::count())->toBe(0);
});

test('a statement of more than 500 rows is refused', function (): void {
    $csv = implode("\n", array_fill(0, 501, '2026-10-12,Coffee,10'));

    $this->actingAs($this->user)->postJson('/money/transactions/import', ['debt_id' => $this->card->id, 'csv' => $csv])->assertJsonValidationErrors('csv');
    expect(Transaction::count())->toBe(0);
});

test('the preview lists the rows it skipped', function (): void {
    previewStatement($this->user, $this->card, "2026-10-12,Lunch,250\n2026-10-13,Payment,-5000")
        ->assertJsonPath('rows.0.status', 'new')
        ->assertJsonPath('skipped.0.line', 2)
        ->assertJsonPath('skipped.0.reason', 'A payment or refund, not a charge');
});

test('guests cannot import', function (): void {
    $this->postJson('/money/transactions/import/preview', [])->assertUnauthorized();
    $this->postJson('/money/transactions/import', [])->assertUnauthorized();
});
