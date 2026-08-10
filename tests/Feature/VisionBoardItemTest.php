<?php

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('store uploads an image and creates a vision board item', function (): void {
    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'Japan trip 2027',
    ])->assertRedirect();

    $item = VisionBoardItem::where('user_id', $this->user->id)->first();
    expect($item)->not->toBeNull();
    expect($item->caption)->toBe('Japan trip 2027');
    expect($item->position)->toBe(1);
    Storage::disk('public')->assertExists($item->image_path);
});

test('store validates required fields', function (): void {
    $this->post('/vision-board-items', [])->assertSessionHasErrors(['image', 'caption']);
});

test('store rejects a caption over 80 characters', function (): void {
    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => str_repeat('a', 81),
    ])->assertSessionHasErrors('caption');
});

test('store rejects non-image files', function (): void {
    $file = UploadedFile::fake()->create('trip.pdf', 100);

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'Not an image',
    ])->assertSessionHasErrors('image');
});

test('store rejects a 7th item when the board is full', function (): void {
    VisionBoardItem::factory()->for($this->user)->count(6)->create();

    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'One too many',
    ])->assertSessionHasErrors('image');

    expect(VisionBoardItem::where('user_id', $this->user->id)->count())->toBe(6);
});

test('update edits the caption without touching the image', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
        'caption' => 'Old caption',
    ]);
    Storage::disk('public')->put('vision-board/original.jpg', 'fake-contents');

    $this->patch("/vision-board-items/{$item->id}", [
        'caption' => 'New caption',
    ])->assertRedirect();

    $item->refresh();
    expect($item->caption)->toBe('New caption');
    expect($item->image_path)->toBe('vision-board/original.jpg');
    Storage::disk('public')->assertExists('vision-board/original.jpg');
});

test('update replaces the image and deletes the old file', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
    ]);
    Storage::disk('public')->put('vision-board/original.jpg', 'fake-contents');

    $newFile = UploadedFile::fake()->image('new.jpg');

    $this->patch("/vision-board-items/{$item->id}", [
        'image' => $newFile,
        'caption' => $item->caption,
    ])->assertRedirect();

    $item->refresh();
    expect($item->image_path)->not->toBe('vision-board/original.jpg');
    Storage::disk('public')->assertMissing('vision-board/original.jpg');
    Storage::disk('public')->assertExists($item->image_path);
});

test('update returns 403 for another user\'s item', function (): void {
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create();

    $this->patch("/vision-board-items/{$item->id}", [
        'caption' => 'Hacked',
    ])->assertForbidden();
});

test('destroy deletes the item and its file', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
    ]);
    Storage::disk('public')->put('vision-board/original.jpg', 'fake-contents');

    $this->delete("/vision-board-items/{$item->id}")->assertRedirect();

    expect(VisionBoardItem::find($item->id))->toBeNull();
    Storage::disk('public')->assertMissing('vision-board/original.jpg');
});

test('destroy returns 403 for another user\'s item', function (): void {
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create();

    $this->delete("/vision-board-items/{$item->id}")->assertForbidden();

    expect(VisionBoardItem::find($item->id))->not->toBeNull();
});
