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

// ── store ──────────────────────────────────────────────────────────────────

test('user can create a new section', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/task-sections', ['label' => 'Projects'])
        ->assertRedirect(route('tasks.index'));

    expect(TaskSection::where('user_id', $user->id)->where('label', 'Projects')->exists())->toBeTrue();
});

test('new section is appended with the next position', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $maxPosition = TaskSection::where('user_id', $user->id)->max('position');

    $this->actingAs($user)->post('/task-sections', ['label' => 'Later']);

    $section = TaskSection::where('user_id', $user->id)->where('label', 'Later')->first();
    expect($section->position)->toBe($maxPosition + 1);
});

test('store validates label is required', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/task-sections', [])
        ->assertSessionHasErrors('label');
});

test('guest cannot create a section', function (): void {
    $this->post('/task-sections', ['label' => 'Projects'])->assertRedirect('/login');
});

// ── update ────────────────────────────────────────────────────────────────

test('user can rename a section', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $section = TaskSection::where('user_id', $user->id)->first();

    $this->actingAs($user)
        ->patch("/task-sections/{$section->id}", ['label' => 'Right Now'])
        ->assertRedirect();

    expect($section->fresh()->label)->toBe('Right Now');
});

test('user cannot rename another users section', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $otherSection = TaskSection::create(['user_id' => $other->id, 'label' => 'Theirs', 'position' => 1]);

    $this->actingAs($user)
        ->patch("/task-sections/{$otherSection->id}", ['label' => 'Stolen'])
        ->assertForbidden();
});

// ── destroy ───────────────────────────────────────────────────────────────

test('user can delete a section', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $section = TaskSection::where('user_id', $user->id)->first();

    $this->actingAs($user)
        ->delete("/task-sections/{$section->id}")
        ->assertRedirect(route('tasks.index'));

    expect(TaskSection::find($section->id))->toBeNull();
});

test('deleting a section also deletes its tasks', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $section = TaskSection::where('user_id', $user->id)->first();
    $taskIds = Task::where('section_id', $section->id)->pluck('id');

    expect($taskIds)->not->toBeEmpty();

    $this->actingAs($user)->delete("/task-sections/{$section->id}");

    expect(Task::whereIn('id', $taskIds)->exists())->toBeFalse();
});

test('user cannot delete another users section', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $otherSection = TaskSection::create(['user_id' => $other->id, 'label' => 'Theirs', 'position' => 1]);

    $this->actingAs($user)
        ->delete("/task-sections/{$otherSection->id}")
        ->assertForbidden();
});

// ── reorder ───────────────────────────────────────────────────────────────

test('user can reorder sections', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $sections = TaskSection::where('user_id', $user->id)->orderBy('position')->get();

    $reordered = $sections->reverse()->values()->map(fn ($s, $i) => ['id' => $s->id, 'position' => $i + 1]);

    $this->actingAs($user)
        ->post('/task-sections/reorder', ['sections' => $reordered->all()])
        ->assertRedirect();

    foreach ($reordered as $item) {
        expect(TaskSection::find($item['id'])->position)->toBe($item['position']);
    }
});

test('reorder ignores sections belonging to other users', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $otherSection = TaskSection::create(['user_id' => $other->id, 'label' => 'Theirs', 'position' => 5]);

    $this->actingAs($user)->post('/task-sections/reorder', [
        'sections' => [['id' => $otherSection->id, 'position' => 1]],
    ]);

    expect($otherSection->fresh()->position)->toBe(5);
});

// ── registration ──────────────────────────────────────────────────────────

test('new user gets three default sections', function (): void {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();
    $sections = TaskSection::where('user_id', $user->id)->orderBy('position')->get();

    expect($sections)->toHaveCount(3);
    expect($sections[0]->label)->toBe('Today');
    expect($sections[1]->label)->toBe('This Week');
    expect($sections[2]->label)->toBe('Someday');
});
