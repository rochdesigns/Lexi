<?php

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('authenticated user can view tasks page', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks')
            ->has('groups', 3)
            ->has('groups.0.key')
            ->has('groups.0.label')
            ->has('groups.0.tasks')
            ->has('groups.0.leftCount')
        );
});

test('guest is redirected from tasks index', function (): void {
    $this->get('/tasks')->assertRedirect('/login');
});

test('authenticated user can add a task', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/tasks', ['label' => 'Buy oat milk', 'group' => 'today'])
        ->assertRedirect(route('tasks.index'));

    expect(
        Task::where('user_id', $user->id)->where('label', 'Buy oat milk')->exists()
    )->toBeTrue();
});

test('store validates label is required', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/tasks', ['group' => 'today'])
        ->assertSessionHasErrors('label');
});

test('store validates group must be valid', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/tasks', ['label' => 'Buy oat milk', 'group' => 'invalid'])
        ->assertSessionHasErrors('group');
});

test('unauthenticated store request is redirected', function (): void {
    $this->post('/tasks', ['label' => 'Buy oat milk', 'group' => 'today'])
        ->assertRedirect('/login');
});
