<?php

use App\Models\Money\Bill;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('saving a bill flashes a success toast', function (): void {
    $bill = Bill::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->patch("/money/bills/{$bill->id}", ['name' => 'Water', 'amount' => 150_000, 'frequency' => 'monthly', 'due_day' => 25, 'autopay' => false])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'Bill saved.']);
});

test('deleting a record says it was deleted', function (): void {
    $bill = Bill::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->delete("/money/bills/{$bill->id}")
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'Bill deleted.']);
});

test('a save that fails validation flashes no toast', function (): void {
    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->post('/money/bills', ['name' => ''])
        ->assertSessionHasErrors()
        ->assertSessionMissing('inertia.flash_data.toast');
});

test('a controller that flashes its own toast keeps it', function (): void {
    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->patch('/settings/profile', ['name' => 'New Name', 'email' => $this->user->email])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'Profile updated.']);
});

test('ticking a task off stays quiet', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->where('done', false)->first();

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->patch("/tasks/{$task->id}", ['done' => true])
        ->assertSessionMissing('inertia.flash_data.toast');
});

test('a plain form post without Inertia gets no toast', function (): void {
    $this->actingAs($this->user)
        ->post('/money/accounts', ['name' => 'Cash', 'type' => 'cash', 'balance' => 0, 'is_spendable' => true])
        ->assertSessionMissing('inertia.flash_data.toast');
});
