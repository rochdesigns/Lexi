<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\Write\RecordDebtPayment;
use App\Mcp\Tools\Money\Write\RecordTransaction;
use App\Models\Money\Account;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\IncomeSource;
use App\Models\User;
use Laravel\Passport\Passport;

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
        ->assertDontSee(['record_transaction', 'record_debt_payment']);
});

test('a token with the write scope is offered the write tools', function (): void {
    signedIn();

    $this->postJson('/mcp/money', rpc('tools/list'))
        ->assertOk()
        ->assertSee(['record_transaction', 'record_debt_payment']);
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
    signedIn();
    $other = User::factory()->create();
    $account = Account::factory()->for($other)->create(['balance' => 10_000]);
    $card = Debt::factory()->for($other)->create(['type' => DebtType::CreditCard]);
    $source = IncomeSource::factory()->for($other)->create();
    $loan = Debt::factory()->for($other)->create(['outstanding_balance' => 50_000]);

    foreach ([
        ['record_transaction', ['amountCentavos' => 100, 'category' => 'food', 'accountId' => $account->id]],
        ['record_transaction', ['amountCentavos' => 100, 'category' => 'food', 'debtId' => $card->id]],
        ['record_transaction', ['kind' => 'income', 'amountCentavos' => 100, 'accountId' => $account->id]],
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

test('the write tools are annotated as additive, never destructive', function (string $tool): void {
    expect((new $tool)->toArray()['annotations'])->toMatchArray(['destructiveHint' => false, 'idempotentHint' => false])
        ->not->toHaveKey('readOnlyHint');
})->with(MoneyServer::WRITE_TOOLS);

test('the server exposes exactly the reviewed write tools, and none of them is also a read tool', function (): void {
    $names = collect(MoneyServer::WRITE_TOOLS)->map(fn (string $tool): string => (new $tool)->name())->sort()->values()->all();

    expect($names)->toBe(['record_debt_payment', 'record_transaction'])
        ->and(array_intersect(MoneyServer::TOOLS, MoneyServer::WRITE_TOOLS))->toBe([])
        ->and(MoneyServer::WRITE_TOOLS)->toBe([RecordTransaction::class, RecordDebtPayment::class]);
});
