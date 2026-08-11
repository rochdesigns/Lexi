<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\BudgetMonth;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\HealthWeek;
use App\Models\Setting;
use App\Models\SinkingFund;
use App\Models\Task;
use App\Models\TaskSection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name' => 'Elle Domingo',
            'email' => 'elle@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        Setting::create(['user_id' => $user->id, 'theme' => 'ocean']);

        // Events — today only
        $today = Carbon::today();
        foreach ([
            ['title' => 'Team standup', 'time' => '11:00 PM', 'tag' => 'Work', 'color' => '#7d4bd0'],
            ['title' => 'Lunch with Mia', 'time' => '12:30 PM', 'tag' => 'Personal', 'color' => '#c0512c'],
            ['title' => 'Yoga class', 'time' => '6:00 PM', 'tag' => 'Health', 'color' => '#2f7d5b'],
        ] as $event) {
            Event::create(array_merge($event, ['user_id' => $user->id, 'date' => $today->toDateString()]));
        }

        // Task sections
        $todaySection = TaskSection::create(['user_id' => $user->id, 'label' => 'Today', 'position' => 1]);
        $weekSection = TaskSection::create(['user_id' => $user->id, 'label' => 'This Week', 'position' => 2]);
        $somedaySection = TaskSection::create(['user_id' => $user->id, 'label' => 'Someday', 'position' => 3]);

        // Tasks
        foreach ([
            ['label' => 'Reply to the landlord', 'section_id' => $todaySection->id, 'done' => false],
            ['label' => 'Book a dentist appointment', 'section_id' => $todaySection->id, 'done' => false],
            ['label' => 'Pay the electric bill', 'section_id' => $todaySection->id, 'done' => false],
            ['label' => 'Grocery run', 'section_id' => $todaySection->id, 'done' => true],
            ['label' => 'Finish the Q3 deck', 'section_id' => $weekSection->id, 'done' => false],
            ['label' => 'Renew car insurance quote', 'section_id' => $weekSection->id, 'done' => false],
            ['label' => 'Clean out the garage', 'section_id' => $weekSection->id, 'done' => false],
            ['label' => 'Learn to make sourdough', 'section_id' => $somedaySection->id, 'done' => false],
            ['label' => 'Plan Batangas trip', 'section_id' => $somedaySection->id, 'done' => false],
        ] as $task) {
            Task::create(array_merge($task, ['user_id' => $user->id]));
        }

        // Health metrics
        foreach ([
            ['name' => 'Steps', 'value' => '7,420', 'sub' => 'of 10,000', 'pct' => 74, 'color' => '#2f7d5b', 'icon' => 'directions_walk'],
            ['name' => 'Sleep', 'value' => '7h 10m', 'sub' => 'of 8h', 'pct' => 90, 'color' => '#3f7fb0', 'icon' => 'bedtime'],
            ['name' => 'Active energy', 'value' => '480', 'sub' => 'of 600 cal', 'pct' => 80, 'color' => '#d98a2b', 'icon' => 'local_fire_department'],
            ['name' => 'Resting HR', 'value' => '58', 'sub' => 'bpm · steady', 'pct' => 0, 'color' => '#c0512c', 'icon' => 'favorite'],
        ] as $metric) {
            HealthMetric::create(array_merge($metric, ['user_id' => $user->id]));
        }

        // Health week (Mon–Sun steps)
        foreach ([
            ['day' => 'Mon', 'steps' => 9200],
            ['day' => 'Tue', 'steps' => 6800],
            ['day' => 'Wed', 'steps' => 11400],
            ['day' => 'Thu', 'steps' => 7900],
            ['day' => 'Fri', 'steps' => 7420],
            ['day' => 'Sat', 'steps' => 0],
            ['day' => 'Sun', 'steps' => 0],
        ] as $row) {
            HealthWeek::create(array_merge($row, ['user_id' => $user->id]));
        }

        // Habits + logs
        $habitsData = [
            ['name' => 'Drink water', 'icon' => 'water_drop', 'color' => '#3f7fb0',
                'done_days' => ['Sun', 'Mon', 'Tue', 'Wed']],
            ['name' => 'Read 20 min', 'icon' => 'menu_book', 'color' => '#7d4bd0',
                'done_days' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu']],
            ['name' => 'Move 30 min', 'icon' => 'directions_run', 'color' => '#d98a2b',
                'done_days' => ['Sun', 'Tue', 'Wed', 'Fri']],
            ['name' => 'Meditate', 'icon' => 'self_improvement', 'color' => '#2f7d5b',
                'done_days' => ['Sun', 'Mon', 'Tue', 'Thu']],
        ];

        $weekStart = $today->copy()->startOfWeek(Carbon::SUNDAY);
        $dayOffsets = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        foreach ($habitsData as $data) {
            $habit = Habit::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'icon' => $data['icon'],
                'color' => $data['color'],
            ]);
            foreach ($data['done_days'] as $dayName) {
                HabitLog::create([
                    'habit_id' => $habit->id,
                    'date' => $weekStart->copy()->addDays($dayOffsets[$dayName])->toDateString(),
                    'done' => true,
                ]);
            }
        }

        // Accounts
        foreach ([
            ['name' => 'Checking', 'institution' => 'BDO', 'last4' => '4821', 'balance' => 2480.00, 'type' => 'cash'],
            ['name' => 'Savings', 'institution' => 'BDO', 'last4' => '3392', 'balance' => 8120.00, 'type' => 'cash'],
            // A credit balance is money owed, and this card is the counterpart of the
            // 'Credit card' debt linked to it in seedTrajectoryDemoData() — the two must
            // agree, because Account::syncLinkedDebt() mirrors this balance onto the debt.
            ['name' => 'Credit card', 'institution' => 'BPI', 'last4' => '7714', 'balance' => 85000.00, 'type' => 'credit'],
        ] as $account) {
            Account::create(array_merge($account, ['user_id' => $user->id]));
        }

        BudgetMonth::create([
            'user_id' => $user->id,
            'year' => $today->year,
            'month' => $today->month,
            'budget' => 3200.00,
            'base_spent' => 1970.00,
        ]);

        $this->seedTrajectoryDemoData($user, $today);
    }

    /**
     * Twelve months of finance history so every section of the trajectory report has
     * something real to draw: an emergency fund, investments with a cost basis, debts
     * at different rates, dated goals, and month-end balance snapshots.
     */
    private function seedTrajectoryDemoData(User $user, Carbon $today): void
    {
        $user->setting()->update([
            'fi_number' => 25000000.00,
            'fi_expected_return_rate' => 6.00,
            'emergency_fund_target_months' => 6,
            'investment_monthly_contribution_target' => 15000.00,
        ]);

        $emergency = Account::create([
            'user_id' => $user->id, 'name' => 'Emergency Fund', 'institution' => 'BDO',
            'last4' => '9021', 'balance' => 150000.00, 'type' => 'cash', 'is_emergency_fund' => true,
        ]);

        $indexFund = Account::create([
            'user_id' => $user->id, 'name' => 'Index Fund', 'institution' => 'COL Financial',
            'last4' => '3310', 'balance' => 420000.00, 'cost_basis' => 350000.00, 'type' => 'investment',
        ]);

        $retirement = Account::create([
            'user_id' => $user->id, 'name' => 'Retirement', 'institution' => 'Sun Life',
            'last4' => '7742', 'balance' => 280000.00, 'cost_basis' => 250000.00, 'type' => 'investment',
        ]);

        $checking = Account::where('user_id', $user->id)->where('name', 'Checking')->firstOrFail();
        $savings = Account::where('user_id', $user->id)->where('name', 'Savings')->firstOrFail();
        $creditCard = Account::where('user_id', $user->id)->where('name', 'Credit card')->firstOrFail();

        $debts = collect([
            // Linked to the 'Credit card' account above so the demo exercises
            // Account::linkedDebt()/syncLinkedDebt() and the net worth double-counting guard.
            ['name' => 'Credit card', 'icon' => 'credit_card', 'color' => '#c0512c', 'bg' => '#fbe2d6', 'account_id' => $creditCard->id,
                'balance' => 85000.00, 'original' => 120000.00, 'per_month' => 8000.00, 'interest_rate' => 18.00, 'due_day' => 15],
            ['name' => 'Car loan', 'icon' => 'directions_car', 'color' => '#3f7fb0', 'bg' => '#dcebf5',
                'balance' => 240000.00, 'original' => 400000.00, 'per_month' => 12000.00, 'interest_rate' => 7.50, 'due_day' => 5],
            ['name' => 'Student loan', 'icon' => 'school', 'color' => '#a85fc0', 'bg' => '#efe0f5',
                'balance' => 95000.00, 'original' => 200000.00, 'per_month' => 5000.00, 'interest_rate' => 4.00, 'due_day' => 20],
        ])->map(fn (array $row, int $index) => Debt::create([
            ...$row,
            'user_id' => $user->id,
            'interest_rate_period' => 'annual',
            'position' => $index,
        ]));

        SinkingFund::create([
            'user_id' => $user->id, 'name' => 'Japan trip', 'icon' => 'flight', 'color' => '#d98a2b', 'bg' => '#f8ecd6',
            'saved' => 45000.00, 'target' => 150000.00, 'per_month' => 10000.00,
            'due_date' => $today->copy()->addMonths(5)->toDateString(), 'position' => 0,
        ]);

        SinkingFund::create([
            'user_id' => $user->id, 'name' => 'New laptop', 'icon' => 'laptop_mac', 'color' => '#2f7d5b', 'bg' => '#dcf0e6',
            'saved' => 30000.00, 'target' => 90000.00, 'per_month' => 5000.00, 'due_date' => null, 'position' => 1,
        ]);

        $accountBalances = [
            $checking->id => 2480.00,
            $savings->id => 8120.00,
            $creditCard->id => 85000.00,
            $emergency->id => 150000.00,
            $indexFund->id => 420000.00,
            $retirement->id => 280000.00,
        ];

        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $today->copy()->startOfMonth()->subMonths($offset);
            $monthEnd = $month->copy()->endOfMonth()->min($today);

            // Small month-to-month wobble (raises/bonuses, seasonal costs) keyed on $offset —
            // same idea as the balance decay below — so income and spending trends aren't
            // perfectly flat across the 12 months.
            $income = round(85000.00 * (1 + 0.04 * sin($offset * 0.85)), 2);
            $livingCosts = round(42000.00 * (1 + 0.06 * cos($offset * 0.71)), 2);

            Transaction::create([
                'user_id' => $user->id, 'account_id' => $checking->id, 'type' => 'income',
                'amount' => $income, 'merchant' => 'Salary',
                'transacted_at' => $month->copy()->startOfMonth()->toDateString(),
            ]);

            Transaction::create([
                'user_id' => $user->id, 'account_id' => $checking->id, 'type' => 'expense',
                'amount' => $livingCosts, 'merchant' => 'Living costs',
                'transacted_at' => $month->copy()->startOfMonth()->addDays(9)->toDateString(),
            ]);

            Transaction::create([
                'user_id' => $user->id, 'account_id' => $checking->id, 'to_account_id' => $indexFund->id,
                'type' => 'transfer', 'amount' => 12000.00, 'merchant' => 'Index fund top-up',
                'transacted_at' => $month->copy()->startOfMonth()->addDays(14)->toDateString(),
            ]);

            $cardBalanceAtMonth = null;

            foreach ($debts as $debt) {
                $paidAt = $month->copy()->startOfMonth()->addDays(($debt->due_day ?? 15) - 1);

                // Balance owed as of this month, interpolated linearly between the debt's
                // original amount (12 months ago) and its current balance (today) — the
                // same trajectory the month-end DebtBalanceSnapshot below records.
                $balanceAtMonth = (float) $debt->balance + ((float) $debt->original - (float) $debt->balance) * ($offset / 11);

                $monthlyRate = $debt->interest_rate_period === 'monthly'
                    ? (float) $debt->interest_rate / 100
                    : (float) $debt->interest_rate / 100 / 12;

                // A small wobble on top of the scheduled payment (occasional round-up or
                // extra payment), phase-shifted per debt so the three don't move in lockstep.
                $amount = round((float) $debt->per_month * (1 + 0.04 * sin($offset * 0.6 + $debt->id)), 2);

                // Interest accrues on the declining balance, so it naturally shrinks as the
                // debt is paid down — the standard amortization curve — instead of being a
                // constant fraction of the fixed payment.
                $interest = round(min($balanceAtMonth * $monthlyRate, $amount * 0.9), 2);

                $transaction = Transaction::create([
                    'user_id' => $user->id, 'account_id' => $checking->id, 'debt_id' => $debt->id,
                    'type' => 'expense', 'amount' => $amount, 'merchant' => $debt->name,
                    'transacted_at' => $paidAt->toDateString(),
                ]);

                DebtPayment::create([
                    'user_id' => $user->id, 'debt_id' => $debt->id, 'transaction_id' => $transaction->id,
                    'account_id' => $checking->id, 'amount' => $amount,
                    'principal_amount' => round($amount - $interest, 2), 'interest_amount' => $interest,
                    'paid_at' => $paidAt->toDateString(),
                ]);

                DebtBalanceSnapshot::create([
                    'user_id' => $user->id, 'debt_id' => $debt->id,
                    'balance' => round($balanceAtMonth, 2),
                    'snapshot_date' => $monthEnd->toDateString(),
                ]);

                if ($debt->account_id === $creditCard->id) {
                    $cardBalanceAtMonth = round($balanceAtMonth, 2);
                }
            }

            foreach ($accountBalances as $accountId => $currentBalance) {
                // The card mirrors its linked debt rather than following the generic
                // decay, so the two histories can't drift apart.
                $balance = $accountId === $creditCard->id && $cardBalanceAtMonth !== null
                    ? $cardBalanceAtMonth
                    : round($currentBalance * (1 - 0.02 * $offset), 2);

                AccountBalanceSnapshot::create([
                    'user_id' => $user->id, 'account_id' => $accountId,
                    'balance' => $balance,
                    'snapshot_date' => $monthEnd->toDateString(),
                ]);
            }
        }
    }
}
