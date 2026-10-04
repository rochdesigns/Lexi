<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\CardBalanceSnapshot;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Models\Money\Goal;
use App\Models\Money\IncomeSource;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\Money\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * One of every money record for the user, each named with the marker, so a
 * test can tell whose data a response contains. Every amount is derived from
 * $amountBase, so two users seeded with different bases share no value and no
 * sum of one user's values can equal another's.
 *
 * Returns the amounts as they render in a response (integer centavos), keyed
 * by what they are, so a test can assert one user's numbers do not appear in
 * another user's response.
 *
 * @return array<string, int>
 */
function seedMarkedMoney(User $user, string $marker, int $amountBase = 100_000): array
{
    $amounts = [
        'accountBalance' => $amountBase * 9 + 87,
        'billAmount' => $amountBase * 8 + 21,
        'incomeAmount' => $amountBase * 7 + 10,
        'receiptAmount' => $amountBase * 7 + 3,
        'goalTarget' => $amountBase * 30 + 1,
        'goalContribution' => $amountBase * 2 + 9,
        'loanBalance' => $amountBase * 4 + 9,
        'paymentAmount' => $amountBase * 3 + 5,
        'cardLimit' => $amountBase * 50 + 1,
        'cardBalance' => $amountBase * 6 + 43,
        'cardBalanceLastMonth' => $amountBase * 5 + 37,
        'cardMinimumFloor' => $amountBase + 17,
    ];

    $account = Account::factory()->for($user)->create(['name' => "{$marker} account", 'balance' => $amounts['accountBalance']]);
    Bill::factory()->for($user)->create(['name' => "{$marker} bill", 'amount' => $amounts['billAmount'], 'due_day' => now()->addDays(2)->day]);
    $income = IncomeSource::factory()->for($user)->create(['name' => "{$marker} income", 'amount' => $amounts['incomeAmount']]);
    Goal::factory()->for($user)->create([
        'name' => "{$marker} goal",
        'account_id' => $account->id,
        'target_amount' => $amounts['goalTarget'],
        'monthly_contribution' => $amounts['goalContribution'],
    ]);

    Transaction::factory()->income()->for($user)->create([
        'account_id' => $account->id,
        'income_source_id' => $income->id,
        'amount' => $amounts['receiptAmount'],
        'date' => now()->toDateString(),
    ]);

    $debt = Debt::factory()->for($user)->create(['name' => "{$marker} debt", 'lender' => "{$marker} lender", 'outstanding_balance' => $amounts['loanBalance']]);
    PaymentPlan::factory()->for($debt)->create(['description' => "{$marker} plan"]);
    DebtPayment::factory()->for($debt)->create(['amount' => $amounts['paymentAmount']]);

    $card = Debt::factory()->for($user)->create([
        'name' => "{$marker} card",
        'lender' => "{$marker} card lender",
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => 3.5,
        'due_day' => 28,
    ]);
    CardDetail::factory()->create([
        'debt_id' => $card->id,
        'credit_limit' => $amounts['cardLimit'],
        'min_payment_floor' => $amounts['cardMinimumFloor'],
        'revolving_balance' => $amounts['cardBalance'],
    ]);
    CardBalanceSnapshot::create([
        'debt_id' => $card->id,
        'month' => now()->startOfMonth()->subMonthNoOverflow()->format('Y-m'),
        'revolving_balance' => $amounts['cardBalanceLastMonth'],
    ]);

    return $amounts;
}
