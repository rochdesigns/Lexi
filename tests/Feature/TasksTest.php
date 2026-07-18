<?php

use App\Models\Task;
use App\Models\TaskSection;
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
            ->has('groups.0.id')
            ->has('groups.0.label')
            ->has('groups.0.tasks')
        );
});

test('guest is redirected from tasks index', function (): void {
    $this->get('/tasks')->assertRedirect('/login');
});

test('authenticated user can add a task', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $section = TaskSection::where('user_id', $user->id)->first();

    $this->actingAs($user)
        ->post('/tasks', ['label' => 'Buy oat milk', 'section_id' => $section->id])
        ->assertRedirect(route('tasks.index'));

    expect(
        Task::where('user_id', $user->id)->where('label', 'Buy oat milk')->exists()
    )->toBeTrue();
});

test('store validates label is required', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $section = TaskSection::where('user_id', $user->id)->first();

    $this->actingAs($user)
        ->post('/tasks', ['section_id' => $section->id])
        ->assertSessionHasErrors('label');
});

test('store validates section_id is required', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/tasks', ['label' => 'Buy oat milk'])
        ->assertSessionHasErrors('section_id');
});

test('store rejects section belonging to another user', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $otherSection = TaskSection::create(['user_id' => $other->id, 'label' => 'Theirs', 'position' => 1]);

    $this->actingAs($user)
        ->post('/tasks', ['label' => 'Sneaky task', 'section_id' => $otherSection->id])
        ->assertForbidden();
});

test('unauthenticated store request is redirected', function (): void {
    $section = TaskSection::first();

    $this->post('/tasks', ['label' => 'Buy oat milk', 'section_id' => $section->id])
        ->assertRedirect('/login');
});

test('index only returns the authenticated users tasks', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $otherSection = TaskSection::create(['user_id' => $other->id, 'label' => 'Work', 'position' => 1]);
    Task::create(['user_id' => $other->id, 'label' => 'Other user task', 'section_id' => $otherSection->id, 'done' => false]);

    $response = $this->actingAs($user)->get('/tasks');

    $groups = $response->original->getData()['page']['props']['groups'];
    $allTaskIds = collect($groups)->flatMap(fn ($g) => collect($g['tasks'])->pluck('id'));
    $userTaskIds = Task::where('user_id', $user->id)->pluck('id');

    expect($allTaskIds->diff($userTaskIds)->isEmpty())->toBeTrue();
});
