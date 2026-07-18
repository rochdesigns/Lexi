<?php

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('task can be marked done', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->where('done', false)->first();

    $this->actingAs($user)
        ->patch("/tasks/{$task->id}", ['done' => true])
        ->assertRedirect();

    expect($task->fresh()->done)->toBeTrue();
});

test('task can be marked undone', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->where('done', true)->first();

    $this->actingAs($user)
        ->patch("/tasks/{$task->id}", ['done' => false])
        ->assertRedirect();

    expect($task->fresh()->done)->toBeFalse();
});

test('user cannot toggle another users task', function (): void {
    $otherUser = User::factory()->create();
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->first();

    $this->actingAs($otherUser)
        ->patch("/tasks/{$task->id}", ['done' => true])
        ->assertForbidden();
});
