<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Models\VisionBoardItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('today page loads for authenticated user', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->get('/today')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('user')
            ->has('todayEvents')
            ->has('todayTasks')
            ->has('moneySummary')
            ->has('habits')
            ->has('healthMetrics')
        );
});

test('today redirects unauthenticated users to login', function (): void {
    $this->get('/today')->assertRedirect('/login');
});

test('today page includes vision board items ordered by position', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    VisionBoardItem::factory()->for($user)->create(['caption' => 'Second', 'position' => 2]);
    VisionBoardItem::factory()->for($user)->create(['caption' => 'First', 'position' => 1]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->where('visionBoardItems.0.caption', 'First')
            ->where('visionBoardItems.1.caption', 'Second')
        );
});

test('the money card includes bills due within the next 7 days', function (): void {
    $this->travelTo(Carbon::parse('2026-03-10'));
    $user = User::factory()->create();

    Bill::factory()->for($user)->create(['name' => 'Internet', 'due_day' => 12]);
    Bill::factory()->for($user)->create(['name' => 'Insurance', 'due_day' => 28]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->has('upcomingBills', 1)
            ->where('upcomingBills.0.name', 'Internet')
            ->where('upcomingBills.0.dueLabel', 'Due in 2 days')
        );
});

test('the money card reaches across the month boundary', function (): void {
    $this->travelTo(Carbon::parse('2026-03-29'));
    $user = User::factory()->create();

    Bill::factory()->for($user)->create(['name' => 'Rent', 'due_day' => 2]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->has('upcomingBills', 1)
            ->where('upcomingBills.0.name', 'Rent')
            ->where('upcomingBills.0.dueLabel', 'Due in 4 days')
        );
});

test('a bill already marked paid drops off the money card', function (): void {
    $this->travelTo(Carbon::parse('2026-03-10'));
    $user = User::factory()->create();

    $bill = Bill::factory()->for($user)->create(['name' => 'Internet', 'due_day' => 12]);
    BillPeriod::factory()->create([
        'bill_id' => $bill->id, 'year' => 2026, 'month' => 3, 'paid_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page->has('upcomingBills', 0));
});

test('the money summary comes from this month\'s envelopes', function (): void {
    $this->travelTo(Carbon::parse('2026-03-10'));
    $user = User::factory()->create();

    Account::factory()->for($user)->create(['balance' => 20000]);
    $envelope = Envelope::factory()->for($user)->create();
    EnvelopePeriod::factory()->create([
        'envelope_id' => $envelope->id, 'year' => 2026, 'month' => 3, 'target' => 1000,
    ]);
    Transaction::factory()->for($user)->create([
        'envelope_id' => $envelope->id, 'type' => 'expense', 'amount' => 400, 'occurred_at' => '2026-03-05',
    ]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->where('moneySummary.budget', 1000)
            ->where('moneySummary.spent', 400)
            ->where('moneySummary.safeToSpend', 600)
        );
});
