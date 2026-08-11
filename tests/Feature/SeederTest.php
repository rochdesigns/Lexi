<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\BudgetMonth;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Setting;
use App\Models\SinkingFund;
use App\Models\Task;
use App\Models\User;
use App\Services\TrajectoryReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the expected records', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'elle@example.com')->exists())->toBeTrue();
    expect(Task::count())->toBe(9);
    expect(Event::count())->toBe(3);
    expect(Habit::count())->toBe(4);
    expect(Account::count())->toBe(6);
    expect(BudgetMonth::count())->toBe(1);
    expect(HabitLog::where('done', true)->count())->toBeGreaterThanOrEqual(16);
});

it('seeds trajectory demo data with history for every report section', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'elle@example.com')->firstOrFail();
    $setting = Setting::where('user_id', $user->id)->firstOrFail();

    $japanTrip = SinkingFund::where('user_id', $user->id)->where('name', 'Japan trip')->first();
    $newLaptop = SinkingFund::where('user_id', $user->id)->where('name', 'New laptop')->first();

    expect((float) $setting->fi_number)->toBeGreaterThan(0.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and(Account::where('user_id', $user->id)->where('is_emergency_fund', true)->count())->toBe(1)
        ->and(Account::where('user_id', $user->id)->where('type', 'investment')->whereNotNull('cost_basis')->count())->toBe(2)
        ->and(Debt::where('user_id', $user->id)->count())->toBe(3)
        ->and(Debt::where('user_id', $user->id)->whereNotNull('interest_rate')->count())->toBe(3)
        ->and($japanTrip)->not->toBeNull()
        ->and($japanTrip->due_date)->not->toBeNull()
        ->and($newLaptop)->not->toBeNull()
        ->and($newLaptop->due_date)->toBeNull()
        ->and(DebtPayment::where('user_id', $user->id)->count())->toBe(36)
        ->and(AccountBalanceSnapshot::where('user_id', $user->id)->count())->toBe(72)
        ->and(DebtBalanceSnapshot::where('user_id', $user->id)->count())->toBe(36);
});

it('links the seeded credit card debt to its credit account with an agreeing balance', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'elle@example.com')->firstOrFail();
    $card = Account::where('user_id', $user->id)->where('name', 'Credit card')->firstOrFail();
    $debt = Debt::where('user_id', $user->id)->where('name', 'Credit card')->firstOrFail();

    expect($debt->account_id)->toBe($card->id)
        ->and((float) $card->balance)->toBe((float) $debt->balance)
        ->and($card->linkedDebt()->first()->id)->toBe($debt->id);
});

it('backfills history for every seeded debt', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'elle@example.com')->firstOrFail();
    $before = DebtBalanceSnapshot::where('user_id', $user->id)->count();

    $this->artisan('finance:snapshot', ['--backfill' => 5])->assertSuccessful();

    // Every debt gets its own history. The old forward reconstruction reconciled
    // against `original` and skipped all three of these outright.
    foreach (Debt::where('user_id', $user->id)->get() as $debt) {
        $written = DebtBalanceSnapshot::where('debt_id', $debt->id)
            ->where('snapshot_date', '>=', now()->subDays(4)->toDateString())
            ->count();

        expect($written)->toBeGreaterThanOrEqual(5);

        // Today's reconstructed balance is the live one, by construction.
        expect((float) DebtBalanceSnapshot::where('debt_id', $debt->id)
            ->whereDate('snapshot_date', now()->toDateString())
            ->value('balance'))->toBe((float) $debt->balance);
    }

    expect(DebtBalanceSnapshot::where('user_id', $user->id)->count())->toBeGreaterThan($before);
});

it('produces an available net worth trend for the seeded demo user', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'elle@example.com')->firstOrFail();
    $result = app(TrajectoryReportService::class)->netWorth($user);

    // The headline feature: with debts present, every month must carry a complete set
    // of account AND debt snapshots or it drops out of the trend entirely.
    expect($result['available'])->toBeTrue()
        ->and($result['trend'])->toHaveCount(12)
        ->and($result['debtTotal'])->toBe(420000.0);

    // The linked card is counted once, as a debt — never also as a positive asset.
    $unlinkedTotal = (float) Account::where('user_id', $user->id)->where('type', '!=', 'credit')->sum('balance');

    expect($result['accountTotal'])->toBe(round($unlinkedTotal, 2));
});
