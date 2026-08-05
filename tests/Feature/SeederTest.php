<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\BudgetMonth;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
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

    expect((float) $setting->fi_number)->toBeGreaterThan(0.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and(Account::where('user_id', $user->id)->where('is_emergency_fund', true)->count())->toBe(1)
        ->and(Account::where('user_id', $user->id)->where('type', 'investment')->whereNotNull('cost_basis')->count())->toBe(2)
        ->and(Debt::where('user_id', $user->id)->count())->toBe(3)
        ->and(Debt::where('user_id', $user->id)->whereNotNull('interest_rate')->count())->toBe(3)
        ->and(Goal::where('user_id', $user->id)->whereNotNull('by_date')->count())->toBe(1)
        ->and(DebtPayment::where('user_id', $user->id)->count())->toBe(36)
        ->and(AccountBalanceSnapshot::where('user_id', $user->id)->count())->toBe(72)
        ->and(DebtBalanceSnapshot::where('user_id', $user->id)->count())->toBe(36);
});
