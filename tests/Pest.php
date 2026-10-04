<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\Goal;
use App\Models\Money\IncomeSource;
use App\Models\Money\PaymentPlan;
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
 * test can tell whose data a response contains.
 */
function seedMarkedMoney(User $user, string $marker): void
{
    Account::factory()->for($user)->create(['name' => "{$marker} account"]);
    Bill::factory()->for($user)->create(['name' => "{$marker} bill", 'due_day' => now()->addDays(2)->day]);
    IncomeSource::factory()->for($user)->create(['name' => "{$marker} income"]);
    Goal::factory()->for($user)->create(['name' => "{$marker} goal"]);

    $debt = Debt::factory()->for($user)->create(['name' => "{$marker} debt", 'lender' => "{$marker} lender"]);
    PaymentPlan::factory()->for($debt)->create(['description' => "{$marker} plan"]);
    DebtPayment::factory()->for($debt)->create();
}
