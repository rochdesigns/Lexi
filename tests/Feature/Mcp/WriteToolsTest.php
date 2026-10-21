<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\Write\CreateDebt;
use App\Mcp\Tools\Money\Write\RecordDebtPayment;
use App\Mcp\Tools\Money\Write\RecordTransaction;
use App\Mcp\Tools\Money\Write\UpdateTransaction;
use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\CustomCategory;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\IncomeSource;
use App\Models\Money\Transaction;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    $keys = passportTestKeys();

    config(['passport.private_key' => $keys['private'], 'passport.public_key' => $keys['public']]);
});

/**
 * @param  array<int, string>  $scopes
 */
function signedIn(array $scopes = ['mcp:use', 'mcp:write']): User
{
    $user = Passport::actingAs(User::factory()->create(), $scopes, 'api');

    return $user;
}

test('a read-only token is not offered the write tools', function (): void {
    signedIn(['mcp:use']);

    $this->postJson('/mcp/money', rpc('tools/list'))
        ->assertOk()
        ->assertSee('list_accounts')
        ->assertDontSee(['record_transaction', 'record_debt_payment', 'update_transaction']);
});

test('a token with the write scope is offered the write tools', function (): void {
    signedIn();

    $this->postJson('/mcp/money', rpc('tools/list'))
        ->assertOk()
        ->assertSee(['record_transaction', 'record_debt_payment', 'update_transaction']);
});

test('a read-only token cannot call a write tool by name', function (): void {
    $user = signedIn(['mcp:use']);
    $account = Account::factory()->for($user)->create(['balance' => 100_000]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 5_000, 'category' => 'food', 'accountId' => $account->id],
    ]))->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($account->fresh()->balance)->toBe(100_000)->and($user->transactions()->count())->toBe(0);
});

test('recording an expense adds the entry and takes it from the account', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 100_000]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 25_050, 'category' => 'food', 'accountId' => $account->id, 'note' => 'Lunch', 'date' => '2026-10-01'],
    ]))->assertOk()
        ->assertJsonPath('result.structuredContent.transaction.amount', 25_050)
        ->assertJsonPath('result.structuredContent.accountBalanceAfter', 74_950);

    expect($account->fresh()->balance)->toBe(74_950);
    $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'kind' => 'expense', 'amount' => 25_050, 'category' => 'food', 'source' => 'manual', 'note' => 'Lunch']);
});

test('recording income adds to the account and can settle an income source', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 10_000]);
    $source = IncomeSource::factory()->for($user)->create();

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['kind' => 'income', 'amountCentavos' => 500_000, 'accountId' => $account->id, 'incomeSourceId' => $source->id],
    ]))->assertOk()->assertJsonPath('result.structuredContent.accountBalanceAfter', 510_000);

    $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'kind' => 'income', 'category' => 'income', 'income_source_id' => $source->id]);
});

test('recording a transfer moves money between two accounts', function (): void {
    $user = signedIn();
    $from = Account::factory()->for($user)->create(['balance' => 100_000]);
    $to = Account::factory()->for($user)->create(['balance' => 5_000]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['kind' => 'transfer', 'amountCentavos' => 40_000, 'accountId' => $from->id, 'toAccountId' => $to->id, 'note' => 'Top up'],
    ]))->assertOk()
        ->assertJsonPath('result.structuredContent.accountBalanceAfter', 60_000)
        ->assertJsonPath('result.structuredContent.toAccountBalanceAfter', 45_000)
        ->assertJsonPath('result.structuredContent.transaction.toAccountId', $to->id);

    expect($from->fresh()->balance)->toBe(60_000)->and($to->fresh()->balance)->toBe(45_000);
    $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'kind' => 'transfer', 'category' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 40_000]);
});

test('a bad transfer is rejected and nothing moves', function (array $arguments): void {
    $user = signedIn();
    $from = Account::factory()->for($user)->create(['balance' => 10_000]);
    $to = Account::factory()->for($user)->create(['balance' => 10_000]);
    $card = Debt::factory()->for($user)->create(['type' => DebtType::CreditCard]);
    $arguments = array_map(fn ($value) => match ($value) {
        'FROM' => $from->id,
        'TO' => $to->id,
        'CARD' => $card->id,
        default => $value,
    }, ['kind' => 'transfer', 'amountCentavos' => 100, ...$arguments]);

    $this->postJson('/mcp/money', rpc('tools/call', ['name' => 'record_transaction', 'arguments' => $arguments]))
        ->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($user->transactions()->count())->toBe(0)
        ->and($from->fresh()->balance)->toBe(10_000)
        ->and($to->fresh()->balance)->toBe(10_000);
})->with([
    'the same account on both sides' => [['accountId' => 'FROM', 'toAccountId' => 'FROM']],
    'no destination' => [['accountId' => 'FROM']],
    'no source' => [['toAccountId' => 'TO']],
    'a category' => [['accountId' => 'FROM', 'toAccountId' => 'TO', 'category' => 'food']],
    'a card' => [['accountId' => 'FROM', 'toAccountId' => 'TO', 'debtId' => 'CARD']],
]);

test('an expense or income cannot carry a destination account', function (): void {
    $user = signedIn();
    $from = Account::factory()->for($user)->create(['balance' => 10_000]);
    $to = Account::factory()->for($user)->create(['balance' => 10_000]);

    foreach ([
        ['amountCentavos' => 100, 'category' => 'food', 'accountId' => $from->id, 'toAccountId' => $to->id],
        ['kind' => 'income', 'amountCentavos' => 100, 'accountId' => $from->id, 'toAccountId' => $to->id],
    ] as $arguments) {
        $this->postJson('/mcp/money', rpc('tools/call', ['name' => 'record_transaction', 'arguments' => $arguments]))
            ->assertOk()->assertJsonMissingPath('result.structuredContent');
    }

    expect($user->transactions()->count())->toBe(0)->and($to->fresh()->balance)->toBe(10_000);
});

test('an expense can be charged to a card, which leaves account balances alone', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 10_000]);
    $card = Debt::factory()->for($user)->create(['type' => DebtType::CreditCard]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 3_000, 'category' => 'shopping', 'debtId' => $card->id],
    ]))->assertOk();

    expect($account->fresh()->balance)->toBe(10_000);
    $this->assertDatabaseHas('transactions', ['debt_id' => $card->id, 'amount' => 3_000]);
});

test('bad input is rejected and nothing is written', function (array $arguments): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 10_000]);
    $card = Debt::factory()->for($user)->create(['type' => DebtType::CreditCard]);
    $arguments = array_map(fn ($value) => $value === 'ACCOUNT' ? $account->id : ($value === 'CARD' ? $card->id : $value), $arguments);

    $this->postJson('/mcp/money', rpc('tools/call', ['name' => 'record_transaction', 'arguments' => $arguments]))
        ->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($user->transactions()->count())->toBe(0)->and($account->fresh()->balance)->toBe(10_000);
})->with([
    'zero amount' => [['amountCentavos' => 0, 'category' => 'food', 'accountId' => 'ACCOUNT']],
    'unknown category' => [['amountCentavos' => 100, 'category' => 'yachts', 'accountId' => 'ACCOUNT']],
    'the income category on an expense' => [['amountCentavos' => 100, 'category' => 'income', 'accountId' => 'ACCOUNT']],
    'no account or card' => [['amountCentavos' => 100, 'category' => 'food']],
    'both account and card' => [['amountCentavos' => 100, 'category' => 'food', 'accountId' => 'ACCOUNT', 'debtId' => 'CARD']],
    'income without an account' => [['kind' => 'income', 'amountCentavos' => 100]],
    'a category on income' => [['kind' => 'income', 'amountCentavos' => 100, 'accountId' => 'ACCOUNT', 'category' => 'food']],
]);

test('another user\'s account, card, income source and debt cannot be used', function (): void {
    $user = signedIn();
    $mine = Account::factory()->for($user)->create(['balance' => 10_000]);
    $other = User::factory()->create();
    $account = Account::factory()->for($other)->create(['balance' => 10_000]);
    $card = Debt::factory()->for($other)->create(['type' => DebtType::CreditCard]);
    $source = IncomeSource::factory()->for($other)->create();
    $loan = Debt::factory()->for($other)->create(['outstanding_balance' => 50_000]);

    foreach ([
        ['record_transaction', ['amountCentavos' => 100, 'category' => 'food', 'accountId' => $account->id]],
        ['record_transaction', ['amountCentavos' => 100, 'category' => 'food', 'debtId' => $card->id]],
        ['record_transaction', ['kind' => 'income', 'amountCentavos' => 100, 'accountId' => $account->id]],
        ['record_transaction', ['kind' => 'transfer', 'amountCentavos' => 100, 'accountId' => $account->id, 'toAccountId' => $mine->id]],
        ['record_transaction', ['kind' => 'transfer', 'amountCentavos' => 100, 'accountId' => $mine->id, 'toAccountId' => $account->id]],
        ['record_debt_payment', ['amountCentavos' => 100, 'debtId' => $loan->id]],
    ] as [$name, $arguments]) {
        $this->postJson('/mcp/money', rpc('tools/call', ['name' => $name, 'arguments' => $arguments]))
            ->assertOk()->assertJsonMissingPath('result.structuredContent');
    }

    expect($account->fresh()->balance)->toBe(10_000)
        ->and($loan->fresh()->outstanding_balance)->toBe(50_000)
        ->and($other->transactions()->count())->toBe(0)
        ->and($loan->payments()->count())->toBe(0);
});

test('recording a debt payment adds history and lowers an informal debt\'s balance', function (): void {
    $user = signedIn();
    $debt = Debt::factory()->for($user)->create(['type' => DebtType::Informal, 'outstanding_balance' => 50_000]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_debt_payment',
        'arguments' => ['debtId' => $debt->id, 'amountCentavos' => 20_000, 'isExtra' => true, 'date' => '2026-10-02'],
    ]))->assertOk()
        ->assertJsonPath('result.structuredContent.payment.amount', 20_000)
        ->assertJsonPath('result.structuredContent.outstandingBalanceAfter', 30_000);

    $this->assertDatabaseHas('debt_payments', ['debt_id' => $debt->id, 'amount' => 20_000, 'is_extra' => true]);
    expect($debt->payments()->first()->date->toDateString())->toBe('2026-10-02');
});

test('recording a debt payment from an account takes it out of that account', function (): void {
    $user = signedIn();
    $debt = Debt::factory()->for($user)->create(['type' => DebtType::CreditCard]);
    $account = Account::factory()->for($user)->create(['balance' => 100_000]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_debt_payment',
        'arguments' => ['debtId' => $debt->id, 'amountCentavos' => 40_000, 'accountId' => $account->id],
    ]))->assertOk()->assertJsonPath('result.structuredContent.payment.accountId', $account->id);

    expect($account->fresh()->balance)->toBe(60_000);
});

test('a debt payment cannot be paid from another user\'s account', function (): void {
    $user = signedIn();
    $debt = Debt::factory()->for($user)->create(['type' => DebtType::CreditCard]);
    $account = Account::factory()->create(['balance' => 100_000]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_debt_payment',
        'arguments' => ['debtId' => $debt->id, 'amountCentavos' => 40_000, 'accountId' => $account->id],
    ]))->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($account->fresh()->balance)->toBe(100_000)->and($debt->payments()->count())->toBe(0);
});

test('the recording tools are annotated as additive, never destructive', function (string $tool): void {
    expect((new $tool)->toArray()['annotations'])->toMatchArray(['destructiveHint' => false, 'idempotentHint' => false])
        ->not->toHaveKey('readOnlyHint');
})->with([RecordTransaction::class, RecordDebtPayment::class, CreateDebt::class]);

test('the edit tool is annotated as destructive and idempotent', function (): void {
    expect((new UpdateTransaction)->toArray()['annotations'])->toMatchArray(['destructiveHint' => true, 'idempotentHint' => true])
        ->not->toHaveKey('readOnlyHint');
});

test('the server exposes exactly the reviewed write tools, and none of them is also a read tool', function (): void {
    $names = collect(MoneyServer::WRITE_TOOLS)->map(fn (string $tool): string => (new $tool)->name())->sort()->values()->all();

    expect($names)->toBe(['create_debt', 'record_debt_payment', 'record_transaction', 'update_transaction'])
        ->and(array_intersect(MoneyServer::TOOLS, MoneyServer::WRITE_TOOLS))->toBe([])
        ->and(MoneyServer::WRITE_TOOLS)->toBe([RecordTransaction::class, RecordDebtPayment::class, CreateDebt::class, UpdateTransaction::class]);
});

/**
 * @param  array<string, mixed>  $arguments
 */
function createDebt(mixed $test, array $arguments): TestResponse
{
    return $test->postJson('/mcp/money', rpc('tools/call', ['name' => 'create_debt', 'arguments' => $arguments]));
}

test('an informal debt is created with its balance', function (): void {
    $user = signedIn();

    createDebt($this, [
        'name' => 'Loan from Ate Mia', 'type' => 'informal', 'interestType' => 'none',
        'outstandingBalanceCentavos' => 1_500_050, 'targetPayoffDate' => '2027-06-30',
    ])->assertOk()->assertJsonPath('result.structuredContent.debt.name', 'Loan from Ate Mia');

    $debt = $user->debts()->firstOrFail();
    expect($debt->type)->toBe(DebtType::Informal)->and($debt->outstanding_balance)->toBe(1_500_050);
});

test('a loan is created with its first payment plan', function (): void {
    $user = signedIn();

    createDebt($this, [
        'name' => 'Car loan', 'type' => 'loan', 'interestType' => 'amortized', 'monthlyInterestRate' => 1.2, 'dueDay' => 15,
        'plan' => ['totalMonths' => 36, 'startMonth' => '2026-10', 'principalCentavos' => 36_000_000],
    ])->assertOk();

    $plan = $user->debts()->firstOrFail()->paymentPlans()->firstOrFail();
    expect($plan->total_months)->toBe(36)->and($plan->principal)->toBe(36_000_000)->and($plan->monthly_amount)->toBeGreaterThan(0);
});

test('a buy-now-pay-later debt is created with its plan', function (): void {
    $user = signedIn();

    createDebt($this, [
        'name' => 'Shopee SPayLater', 'type' => 'bnpl', 'interestType' => 'none', 'dueDay' => 10,
        'plan' => ['totalMonths' => 3, 'startMonth' => '2026-10', 'monthlyAmountCentavos' => 100_000],
    ])->assertOk();

    expect($user->debts()->firstOrFail()->paymentPlans()->firstOrFail()->monthly_amount)->toBe(100_000);
});

test('a credit card is created with its details and installments', function (): void {
    $user = signedIn();

    createDebt($this, [
        'name' => 'BDO Mastercard', 'type' => 'credit_card', 'interestType' => 'revolving', 'monthlyInterestRate' => 3.5, 'dueDay' => 28,
        'creditLimitCentavos' => 10_000_000, 'statementDay' => 8, 'totalDueCentavos' => 2_000_000,
        'installments' => [['description' => 'Laptop', 'monthlyAmountCentavos' => 500_000, 'startMonth' => '2025-01', 'endMonth' => '2027-12']],
    ])->assertOk();

    $card = $user->debts()->with(['cardDetail', 'paymentPlans'])->firstOrFail();
    expect($card->cardDetail->credit_limit)->toBe(10_000_000)
        ->and($card->cardDetail->statement_day)->toBe(8)
        ->and($card->paymentPlans)->toHaveCount(1);
});

test('a read-only token cannot create a debt', function (): void {
    $user = signedIn(['mcp:use']);

    createDebt($this, ['name' => 'X', 'type' => 'informal', 'interestType' => 'none', 'outstandingBalanceCentavos' => 100])
        ->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($user->debts()->count())->toBe(0);
});

test('a debt that breaks its type\'s rules is rejected and nothing is written', function (array $arguments): void {
    $user = signedIn();

    createDebt($this, $arguments)->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($user->debts()->count())->toBe(0);
})->with([
    'informal without a balance' => [['name' => 'X', 'type' => 'informal', 'interestType' => 'none']],
    'unknown type' => [['name' => 'X', 'type' => 'mortgage', 'interestType' => 'none', 'dueDay' => 1]],
    'amortized without a rate' => [['name' => 'X', 'type' => 'loan', 'interestType' => 'amortized', 'dueDay' => 1, 'plan' => ['totalMonths' => 12, 'startMonth' => '2026-10', 'principalCentavos' => 100_000]]],
    'a loan without a plan' => [['name' => 'X', 'type' => 'loan', 'interestType' => 'none', 'dueDay' => 1]],
    'a card without its limit' => [['name' => 'X', 'type' => 'credit_card', 'interestType' => 'revolving', 'monthlyInterestRate' => 3, 'dueDay' => 1, 'statementDay' => 5, 'revolvingBalanceCentavos' => 0]],
    'a non-informal debt without a due day' => [['name' => 'X', 'type' => 'bnpl', 'interestType' => 'none', 'plan' => ['totalMonths' => 3, 'startMonth' => '2026-10', 'monthlyAmountCentavos' => 100]]],
]);

test('recording an expense against a bill uses the bill\'s account', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 100_000]);
    $bill = Bill::factory()->for($user)->create(['account_id' => $account->id]);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 20_000, 'category' => 'home', 'billId' => $bill->id, 'date' => '2026-10-01'],
    ]))->assertOk()
        ->assertJsonPath('result.structuredContent.transaction.billId', $bill->id)
        ->assertJsonPath('result.structuredContent.accountBalanceAfter', 80_000);

    $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'bill_id' => $bill->id, 'account_id' => $account->id]);
});

test('a bill with no account needs an account or card to be recorded', function (): void {
    $user = signedIn();
    $bill = Bill::factory()->for($user)->create();

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 20_000, 'category' => 'home', 'billId' => $bill->id],
    ]))->assertOk()->assertJsonPath('result.isError', true);

    expect($user->transactions()->count())->toBe(0);
});

test('an expense can be recorded in one of the user\'s own categories', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 10_000]);
    $category = CustomCategory::factory()->for($user)->create(['name' => 'Pets']);

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 1_000, 'customCategoryId' => $category->id, 'accountId' => $account->id],
    ]))->assertOk()
        ->assertJsonPath('result.structuredContent.transaction.customCategoryId', $category->id)
        ->assertJsonPath('result.structuredContent.transaction.customCategoryName', 'Pets');

    $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'category' => 'other', 'custom_category_id' => $category->id]);
});

test('another user\'s custom category cannot be used', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 10_000]);
    $category = CustomCategory::factory()->create();

    $this->postJson('/mcp/money', rpc('tools/call', [
        'name' => 'record_transaction',
        'arguments' => ['amountCentavos' => 1_000, 'customCategoryId' => $category->id, 'accountId' => $account->id],
    ]))->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($user->transactions()->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $arguments
 */
function updateTransaction(mixed $test, array $arguments): TestResponse
{
    return $test->postJson('/mcp/money', rpc('tools/call', ['name' => 'update_transaction', 'arguments' => $arguments]));
}

test('editing an expense\'s amount moves the difference in the account', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 75_000]);
    $transaction = Transaction::factory()->for($user)->create(['account_id' => $account->id, 'amount' => 25_000, 'note' => 'Lunch', 'date' => '2026-10-01']);

    updateTransaction($this, ['transactionId' => $transaction->id, 'amountCentavos' => 30_000])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.transaction.amount', 30_000)
        ->assertJsonPath('result.structuredContent.transaction.note', 'Lunch')
        ->assertJsonPath('result.structuredContent.accounts.0.balance', 70_000);

    expect($account->fresh()->balance)->toBe(70_000)
        ->and($transaction->fresh()->date->toDateString())->toBe('2026-10-01');
});

test('an expense can be moved to a custom category and back to a built-in one', function (): void {
    $user = signedIn();
    $transaction = Transaction::factory()->for($user)->create(['account_id' => Account::factory()->for($user)]);
    $category = CustomCategory::factory()->for($user)->create();

    updateTransaction($this, ['transactionId' => $transaction->id, 'customCategoryId' => $category->id])->assertOk();
    expect($transaction->fresh())->category->value->toBe('other')->custom_category_id->toBe($category->id);

    updateTransaction($this, ['transactionId' => $transaction->id, 'category' => 'transport'])->assertOk();
    expect($transaction->fresh())->category->value->toBe('transport')->custom_category_id->toBeNull();
});

test('moving an expense from an account to a card puts the money back in the account', function (): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 75_000]);
    $card = Debt::factory()->for($user)->create(['type' => DebtType::CreditCard]);
    $transaction = Transaction::factory()->for($user)->create(['account_id' => $account->id, 'amount' => 25_000]);

    updateTransaction($this, ['transactionId' => $transaction->id, 'debtId' => $card->id])->assertOk();

    expect($account->fresh()->balance)->toBe(100_000)
        ->and($transaction->fresh())->account_id->toBeNull()->debt_id->toBe($card->id);
});

test('editing a transfer moves both sides', function (): void {
    $user = signedIn();
    $from = Account::factory()->for($user)->create(['balance' => 60_000]);
    $to = Account::factory()->for($user)->create(['balance' => 45_000]);
    $other = Account::factory()->for($user)->create(['balance' => 0]);
    $transaction = Transaction::factory()->for($user)->create([
        'kind' => 'transfer', 'category' => 'transfer', 'amount' => 40_000, 'account_id' => $from->id, 'to_account_id' => $to->id,
    ]);

    updateTransaction($this, ['transactionId' => $transaction->id, 'toAccountId' => $other->id, 'amountCentavos' => 10_000])->assertOk();

    expect($from->fresh()->balance)->toBe(90_000)
        ->and($to->fresh()->balance)->toBe(5_000)
        ->and($other->fresh()->balance)->toBe(10_000);
});

test('a note can be cleared', function (): void {
    $user = signedIn();
    $transaction = Transaction::factory()->for($user)->create(['account_id' => Account::factory()->for($user), 'note' => 'Lunch']);

    updateTransaction($this, ['transactionId' => $transaction->id, 'note' => null])->assertOk();

    expect($transaction->fresh()->note)->toBeNull();
});

test('a bad edit is rejected and nothing changes', function (array $arguments): void {
    $user = signedIn();
    $account = Account::factory()->for($user)->create(['balance' => 75_000]);
    $theirs = Account::factory()->create();
    $transaction = Transaction::factory()->for($user)->create(['account_id' => $account->id, 'amount' => 25_000, 'category' => 'food']);
    $arguments = array_map(fn ($value) => $value === 'THEIRS' ? $theirs->id : $value, ['transactionId' => $transaction->id, ...$arguments]);

    updateTransaction($this, $arguments)->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($account->fresh()->balance)->toBe(75_000)
        ->and($transaction->fresh())->amount->toBe(25_000)->category->value->toBe('food');
})->with([
    'zero amount' => [['amountCentavos' => 0]],
    'unknown category' => [['category' => 'yachts']],
    'another user\'s account' => [['accountId' => 'THEIRS']],
    'a destination on an expense' => [['toAccountId' => 'THEIRS']],
    'no account or card left' => [['accountId' => null]],
]);

test('another user\'s transaction cannot be edited', function (): void {
    signedIn();
    $other = User::factory()->create();
    $account = Account::factory()->for($other)->create(['balance' => 75_000]);
    $transaction = Transaction::factory()->for($other)->create(['account_id' => $account->id, 'amount' => 25_000]);

    updateTransaction($this, ['transactionId' => $transaction->id, 'amountCentavos' => 1])
        ->assertOk()->assertJsonPath('result.isError', true);

    expect($transaction->fresh()->amount)->toBe(25_000)->and($account->fresh()->balance)->toBe(75_000);
});

test('a balance adjustment cannot be edited', function (): void {
    $user = signedIn();
    $transaction = Transaction::factory()->for($user)->create([
        'kind' => 'adjustment_increase', 'category' => 'adjustment', 'account_id' => Account::factory()->for($user), 'amount' => 25_000,
    ]);

    updateTransaction($this, ['transactionId' => $transaction->id, 'amountCentavos' => 1])
        ->assertOk()->assertJsonPath('result.isError', true);

    expect($transaction->fresh()->amount)->toBe(25_000);
});

test('a read-only token cannot edit a transaction', function (): void {
    $user = signedIn(['mcp:use']);
    $transaction = Transaction::factory()->for($user)->create(['account_id' => Account::factory()->for($user), 'amount' => 25_000]);

    updateTransaction($this, ['transactionId' => $transaction->id, 'amountCentavos' => 1])
        ->assertOk()->assertJsonMissingPath('result.structuredContent');

    expect($transaction->fresh()->amount)->toBe(25_000);
});
