<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\Money\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'GCash', 'balance' => 1_000_000]);
    $this->card = Debt::factory()->for($this->user)->create(['name' => 'Platinum', 'type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving]);
});

function entry(array $overrides = []): array
{
    return ['date' => '2026-10-15', 'amount' => 250.5, 'category' => 'food', 'note' => 'Lunch', ...$overrides];
}

test('adding an account expense takes it off the balance', function (): void {
    $this->actingAs($this->user)->post('/money/transactions', entry(['account_id' => $this->account->id]))->assertRedirect();

    $transaction = $this->user->transactions()->sole();
    expect($transaction->amount)->toBe(25_050)
        ->and($transaction->category->value)->toBe('food')
        ->and($transaction->account_id)->toBe($this->account->id)
        ->and($transaction->debt_id)->toBeNull()
        ->and($transaction->source->value)->toBe('manual')
        ->and($this->account->fresh()->balance)->toBe(974_950);
});

test('a card charge leaves every account balance alone', function (): void {
    $this->actingAs($this->user)->post('/money/transactions', entry(['debt_id' => $this->card->id]))->assertRedirect();

    expect($this->user->transactions()->sole()->debt_id)->toBe($this->card->id)
        ->and($this->account->fresh()->balance)->toBe(1_000_000);
});

test('changing the amount only moves the difference', function (): void {
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => 25_000]);
    $this->account->update(['balance' => 975_000]);

    $this->actingAs($this->user)->patch("/money/transactions/{$transaction->id}", entry(['account_id' => $this->account->id, 'amount' => 400]))->assertRedirect();

    expect($transaction->fresh()->amount)->toBe(40_000)->and($this->account->fresh()->balance)->toBe(960_000);
});

test('moving an expense to another account puts the money back on the first', function (): void {
    $other = Account::factory()->for($this->user)->create(['balance' => 500_000]);
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => 25_000]);
    $this->account->update(['balance' => 975_000]);

    $this->actingAs($this->user)->patch("/money/transactions/{$transaction->id}", entry(['account_id' => $other->id, 'amount' => 250]))->assertRedirect();

    expect($this->account->fresh()->balance)->toBe(1_000_000)->and($other->fresh()->balance)->toBe(475_000);
});

test('moving an expense onto a card puts the money back on the account', function (): void {
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => 25_000]);
    $this->account->update(['balance' => 975_000]);

    $this->actingAs($this->user)->patch("/money/transactions/{$transaction->id}", entry(['debt_id' => $this->card->id]))->assertRedirect();

    expect($transaction->fresh()->only(['account_id', 'debt_id']))->toBe(['account_id' => null, 'debt_id' => $this->card->id])
        ->and($this->account->fresh()->balance)->toBe(1_000_000);
});

test('deleting an expense puts the money back', function (): void {
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => 25_000]);
    $this->account->update(['balance' => 975_000]);

    $this->actingAs($this->user)->delete("/money/transactions/{$transaction->id}")->assertRedirect();

    expect(Transaction::count())->toBe(0)->and($this->account->fresh()->balance)->toBe(1_000_000);
});

test('an expense needs exactly one of an account or a card', function (): void {
    $this->actingAs($this->user)->post('/money/transactions', entry())->assertSessionHasErrors(['account_id', 'debt_id']);
    $this->actingAs($this->user)->post('/money/transactions', entry(['account_id' => $this->account->id, 'debt_id' => $this->card->id]))->assertSessionHasErrors();
    expect(Transaction::count())->toBe(0);
});

test('an expense cannot use someone else\'s account or card, or a loan', function (): void {
    $other = User::factory()->create();
    $theirAccount = Account::factory()->for($other)->create();
    $theirCard = Debt::factory()->for($other)->create(['type' => DebtType::CreditCard]);
    $loan = Debt::factory()->for($this->user)->create();

    $this->actingAs($this->user)->post('/money/transactions', entry(['account_id' => $theirAccount->id]))->assertSessionHasErrors('account_id');
    $this->actingAs($this->user)->post('/money/transactions', entry(['debt_id' => $theirCard->id]))->assertSessionHasErrors('debt_id');
    $this->actingAs($this->user)->post('/money/transactions', entry(['debt_id' => $loan->id]))->assertSessionHasErrors('debt_id');
    expect($theirAccount->fresh()->balance)->toBe(1_000_000);
});

test('the amount, date and category are checked', function (): void {
    $payload = entry(['account_id' => $this->account->id]);

    $this->actingAs($this->user)->post('/money/transactions', [...$payload, 'amount' => 0])->assertSessionHasErrors('amount');
    $this->actingAs($this->user)->post('/money/transactions', [...$payload, 'date' => null])->assertSessionHasErrors('date');
    $this->actingAs($this->user)->post('/money/transactions', [...$payload, 'category' => 'gambling'])->assertSessionHasErrors('category');
    expect(Transaction::count())->toBe(0)->and($this->account->fresh()->balance)->toBe(1_000_000);
});

test('I cannot change or delete someone else\'s expense', function (): void {
    $transaction = Transaction::factory()->create();
    $balance = $transaction->account->balance;

    $this->actingAs($this->user)->patch("/money/transactions/{$transaction->id}", entry(['account_id' => $this->account->id]))->assertForbidden();
    $this->actingAs($this->user)->delete("/money/transactions/{$transaction->id}")->assertForbidden();
    expect($transaction->account->fresh()->balance)->toBe($balance);
});

test('guests cannot add expenses', function (): void {
    $this->post('/money/transactions', [])->assertRedirect('/login');
});

test('the page lists the month newest first with totals by category', function (): void {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-03', 'amount' => 10_000, 'category' => 'food', 'note' => 'Coffee']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-14', 'amount' => 50_000, 'category' => 'transport']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-14', 'amount' => 5_000, 'category' => 'food']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-09-30', 'amount' => 99_000]);
    Transaction::factory()->create(['date' => '2026-10-10', 'amount' => 77_000]);

    $this->actingAs($this->user)->get('/money/transactions')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Money/Transactions')
        ->where('month', '2026-10')
        ->has('transactions', 3)
        ->where('transactions.0.amount', 5_000)
        ->where('transactions.2.note', 'Coffee')
        ->where('transactions.0.paidWith', 'GCash')
        ->where('total', 65_000)
        ->where('categoryTotals', [
            ['category' => 'transport', 'total' => 50_000, 'items' => [['name' => 'No note', 'amount' => 50_000]]],
            ['category' => 'food', 'total' => 15_000, 'items' => [['name' => 'Coffee', 'amount' => 10_000], ['name' => 'No note', 'amount' => 5_000]]],
        ]));
});

test('each category breaks down by what the money went on, largest first', function (): void {
    $bill = Bill::factory()->for($this->user)->create(['name' => 'Meralco']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-03', 'amount' => 4_000, 'category' => 'food', 'note' => 'Coffee']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-04', 'amount' => 6_000, 'category' => 'food', 'note' => 'Coffee']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-05', 'amount' => 25_000, 'category' => 'food', 'note' => 'Groceries run']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-05', 'amount' => 30_000, 'category' => 'home', 'bill_id' => $bill->id, 'note' => null]);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page
        ->where('categoryTotals.1.items', [['name' => 'Meralco', 'amount' => 30_000]])
        ->where('categoryTotals.0.items', [['name' => 'Groceries run', 'amount' => 25_000], ['name' => 'Coffee', 'amount' => 10_000]]));
});

test('another month can be opened and a bad month shows this one', function (): void {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-09-30', 'amount' => 99_000]);

    $this->actingAs($this->user)->get('/money/transactions?month=2026-09')->assertInertia(fn ($page) => $page->where('month', '2026-09')->where('total', 99_000));
    $this->actingAs($this->user)->get('/money/transactions?month=nope')->assertInertia(fn ($page) => $page->where('month', '2026-10')->where('total', 0));
});

test('the form offers my accounts and cards and remembers the last one used', function (): void {
    Account::factory()->create(['name' => 'Not mine']);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page
        ->has('accounts', 1)
        ->where('accounts.0.name', 'GCash')
        ->has('cards', 1)
        ->where('cards.0.name', 'Platinum')
        ->where('lastPaidWith', null));

    Transaction::factory()->for($this->user)->onCard($this->card)->create();

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page->where('lastPaidWith', ['accountId' => null, 'debtId' => $this->card->id]));
});

test('debt payments paid from an account are listed for the month without touching the totals', function (): void {
    $this->card->payments()->create(['account_id' => $this->account->id, 'date' => '2026-10-10', 'amount' => 500_000, 'is_extra' => false]);
    $this->card->payments()->create(['account_id' => null, 'date' => '2026-10-11', 'amount' => 100_000, 'is_extra' => false]);
    $this->card->payments()->create(['account_id' => $this->account->id, 'date' => '2026-09-30', 'amount' => 300_000, 'is_extra' => false]);

    $this->actingAs($this->user)->get('/money/transactions?month=2026-10')
        ->assertInertia(fn ($page) => $page
            ->has('debtPayments', 1)
            ->where('debtPayments.0.amount', 500_000)
            ->where('debtPayments.0.debtName', 'Platinum')
            ->where('debtPayments.0.paidWith', 'GCash')
            ->where('total', 0));
});

test('another user\'s debt payments never show', function (): void {
    $other = Debt::factory()->for(User::factory()->create())->create();
    $other->payments()->create(['account_id' => $this->account->id, 'date' => '2026-10-10', 'amount' => 1_000, 'is_extra' => false]);

    $this->actingAs($this->user)->get('/money/transactions')
        ->assertInertia(fn ($page) => $page->has('debtPayments', 0));
});

test('a user can add their own category and use it on an expense', function (): void {
    $this->actingAs($this->user)->post('/money/categories', ['name' => '  Pets '])->assertRedirect();

    $custom = $this->user->customCategories()->sole();
    expect($custom->name)->toBe('Pets');

    $this->actingAs($this->user)->post('/money/transactions', entry([
        'account_id' => $this->account->id,
        'category' => 'food',
        'custom_category_id' => $custom->id,
    ]))->assertRedirect();

    $transaction = $this->user->transactions()->sole();
    expect($transaction->category->value)->toBe('other')
        ->and($transaction->custom_category_id)->toBe($custom->id);

    $this->actingAs($this->user)->get('/money/transactions')
        ->assertInertia(fn ($page) => $page
            ->where('customCategories.0.name', 'Pets')
            ->where('transactions.0.categoryName', 'Pets')
            ->where('categoryTotals.0.category', 'Pets'));
});

test('a custom category name cannot repeat a built-in or an existing one', function (string $name): void {
    $this->user->customCategories()->create(['name' => 'Pets']);

    $this->actingAs($this->user)->post('/money/categories', ['name' => $name])->assertSessionHasErrors('name');

    expect($this->user->customCategories()->count())->toBe(1);
})->with(['Food', 'groceries', 'pets', '   ', 'a name that is far too long to be a category']);

test('removing a custom category puts its entries back to other', function (): void {
    $custom = $this->user->customCategories()->create(['name' => 'Pets']);
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'custom_category_id' => $custom->id, 'category' => 'other']);

    $this->actingAs($this->user)->delete("/money/categories/{$custom->id}")->assertRedirect();

    expect($this->user->customCategories()->count())->toBe(0)
        ->and($transaction->fresh()->custom_category_id)->toBeNull()
        ->and($transaction->fresh()->category->value)->toBe('other');
});

test('categories belong to their owner', function (): void {
    $theirs = User::factory()->create()->customCategories()->create(['name' => 'Secret']);

    $this->actingAs($this->user)->post('/money/transactions', entry([
        'account_id' => $this->account->id,
        'custom_category_id' => $theirs->id,
    ]))->assertSessionHasErrors('custom_category_id');

    $this->actingAs($this->user)->delete("/money/categories/{$theirs->id}")->assertForbidden();
});

test('a custom category can be picked when editing an expense', function (): void {
    $custom = $this->user->customCategories()->create(['name' => 'Pets']);
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id]);

    $this->actingAs($this->user)->patch("/money/transactions/{$transaction->id}", entry([
        'account_id' => $this->account->id,
        'category' => 'other',
        'custom_category_id' => $custom->id,
    ]))->assertRedirect();

    expect($transaction->fresh()->custom_category_id)->toBe($custom->id);
});

test('a transfer moves money between two accounts and is not spending', function (): void {
    $maya = Account::factory()->for($this->user)->create(['name' => 'Maya', 'balance' => 0]);

    $this->actingAs($this->user)->post('/money/transactions', [
        'kind' => 'transfer',
        'date' => '2026-10-15',
        'amount' => 400,
        'note' => 'Top up',
        'account_id' => $this->account->id,
        'to_account_id' => $maya->id,
    ])->assertRedirect();

    $transfer = $this->user->transactions()->sole();
    expect($transfer->kind->value)->toBe('transfer')
        ->and($transfer->category->value)->toBe('transfer')
        ->and($transfer->amount)->toBe(40_000)
        ->and($this->account->fresh()->balance)->toBe(960_000)
        ->and($maya->fresh()->balance)->toBe(40_000);

    $this->actingAs($this->user)->get('/money/transactions')
        ->assertInertia(fn ($page) => $page
            ->where('transactions.0.kind', 'transfer')
            ->where('transactions.0.toAccountName', 'Maya')
            ->where('total', 0)
            ->where('received', 0)
            ->has('categoryTotals', 0));
});

test('a transfer needs two different accounts of your own', function (array $overrides): void {
    $maya = Account::factory()->for($this->user)->create();
    $theirs = Account::factory()->for(User::factory()->create())->create();
    $overrides = collect($overrides)->map(fn ($value) => match ($value) {
        'from' => $this->account->id,
        'maya' => $maya->id,
        'theirs' => $theirs->id,
        default => $value,
    })->all();

    $this->actingAs($this->user)->post('/money/transactions', [
        'kind' => 'transfer', 'date' => '2026-10-15', 'amount' => 100, 'account_id' => $this->account->id, ...$overrides,
    ])->assertSessionHasErrors();

    expect($this->user->transactions()->count())->toBe(0)
        ->and($this->account->fresh()->balance)->toBe(1_000_000);
})->with([
    'same account' => [['to_account_id' => 'from']],
    'someone else\'s account' => [['to_account_id' => 'theirs']],
    'no destination' => [[]],
    'with a category' => [['to_account_id' => 'maya', 'category' => 'food']],
    'with a card' => [['to_account_id' => 'maya', 'debt_id' => 1]],
]);

test('editing a transfer moves the balances again, and deleting puts them back', function (): void {
    $maya = Account::factory()->for($this->user)->create(['balance' => 0]);
    $cash = Account::factory()->for($this->user)->create(['balance' => 0]);
    $this->actingAs($this->user)->post('/money/transactions', ['kind' => 'transfer', 'date' => '2026-10-15', 'amount' => 100, 'account_id' => $this->account->id, 'to_account_id' => $maya->id]);
    $transfer = $this->user->transactions()->sole();

    $this->actingAs($this->user)->patch("/money/transactions/{$transfer->id}", ['date' => '2026-10-15', 'amount' => 250, 'account_id' => $this->account->id, 'to_account_id' => $cash->id])->assertRedirect();

    expect($this->account->fresh()->balance)->toBe(975_000)
        ->and($maya->fresh()->balance)->toBe(0)
        ->and($cash->fresh()->balance)->toBe(25_000);

    $this->actingAs($this->user)->delete("/money/transactions/{$transfer->id}")->assertRedirect();

    expect($this->account->fresh()->balance)->toBe(1_000_000)
        ->and($cash->fresh()->balance)->toBe(0);
});

test('an expense cannot carry a destination account', function (): void {
    $maya = Account::factory()->for($this->user)->create();

    $this->actingAs($this->user)->post('/money/transactions', entry(['account_id' => $this->account->id, 'to_account_id' => $maya->id]))
        ->assertSessionHasErrors('to_account_id');
});

test('the transactions list can be filtered to one account', function (): void {
    $other = Account::factory()->for($this->user)->create(['name' => 'BDO']);
    $third = Account::factory()->for($this->user)->create(['name' => 'Maya']);
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-10', 'amount' => 10_000, 'account_id' => $this->account->id]);
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-11', 'amount' => 25_000, 'account_id' => $other->id]);
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-12', 'amount' => 5_000, 'kind' => 'transfer', 'category' => 'transfer', 'account_id' => $other->id, 'to_account_id' => $this->account->id]);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page
        ->where('accountFilter', null)
        ->has('transactions', 3));
    $this->actingAs($this->user)->get('/money/transactions?account='.$this->account->id)->assertInertia(fn ($page) => $page
        ->where('accountFilter', $this->account->id)
        ->has('transactions', 2)
        ->where('total', 10_000));
    $this->actingAs($this->user)->get('/money/transactions?account='.$third->id)->assertInertia(fn ($page) => $page
        ->has('transactions', 0));
});

test('the account filter also narrows debt payments to the account they were paid from', function (): void {
    $other = Account::factory()->for($this->user)->create(['name' => 'BDO']);
    DebtPayment::factory()->for($this->card)->create(['date' => '2026-10-10', 'account_id' => $this->account->id]);
    DebtPayment::factory()->for($this->card)->create(['date' => '2026-10-11', 'account_id' => $other->id]);

    $this->actingAs($this->user)->get('/money/transactions?account='.$other->id)->assertInertia(fn ($page) => $page
        ->has('debtPayments', 1)
        ->where('debtPayments.0.paidWith', 'BDO'));
});

test('the account filter ignores an account that is not mine', function (): void {
    $stranger = Account::factory()->create();
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-10', 'account_id' => $this->account->id]);

    $this->actingAs($this->user)->get('/money/transactions?account='.$stranger->id)->assertInertia(fn ($page) => $page
        ->where('accountFilter', null)
        ->has('transactions', 1));
});

test('view all lists transactions and debt payments from every month', function (): void {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-10-10']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'date' => '2026-03-02']);
    $debt = Debt::factory()->for($this->user)->create();
    DebtPayment::factory()->for($debt)->create(['account_id' => $this->account->id, 'date' => '2026-01-05']);

    $this->actingAs($this->user)->get('/money/transactions')
        ->assertInertia(fn ($page) => $page->where('all', false)->has('transactions', 1)->has('debtPayments', 0));

    $this->actingAs($this->user)->get('/money/transactions?month=all')
        ->assertInertia(fn ($page) => $page->where('all', true)->where('month', '2026-10')->has('transactions', 2)->has('debtPayments', 1));
});
