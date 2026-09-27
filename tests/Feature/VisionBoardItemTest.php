<?php

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('filesystems.uploads'));
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
    VisionBoardItem::disk()->assertExists($item->image_path);
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

test('store rejects oversized image files', function (): void {
    $file = UploadedFile::fake()->image('big.jpg')->size(6000);

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'Too big',
    ])->assertSessionHasErrors('image');
});

test('store rejects a 10th item when the board is full', function (): void {
    VisionBoardItem::factory()->for($this->user)->count(9)->create();

    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'One too many',
    ])->assertSessionHasErrors('image');

    expect(VisionBoardItem::where('user_id', $this->user->id)->count())->toBe(9);
});

test('update edits the caption without touching the image', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
        'caption' => 'Old caption',
    ]);
    VisionBoardItem::disk()->put('vision-board/original.jpg', 'fake-contents');

    $this->patch("/vision-board-items/{$item->id}", [
        'caption' => 'New caption',
    ])->assertRedirect();

    $item->refresh();
    expect($item->caption)->toBe('New caption');
    expect($item->image_path)->toBe('vision-board/original.jpg');
    VisionBoardItem::disk()->assertExists('vision-board/original.jpg');
});

test('update replaces the image and deletes the old file', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
    ]);
    VisionBoardItem::disk()->put('vision-board/original.jpg', 'fake-contents');

    $newFile = UploadedFile::fake()->image('new.jpg');

    $this->patch("/vision-board-items/{$item->id}", [
        'image' => $newFile,
        'caption' => $item->caption,
    ])->assertRedirect();

    $item->refresh();
    expect($item->image_path)->not->toBe('vision-board/original.jpg');
    VisionBoardItem::disk()->assertMissing('vision-board/original.jpg');
    VisionBoardItem::disk()->assertExists($item->image_path);
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
    VisionBoardItem::disk()->put('vision-board/original.jpg', 'fake-contents');

    $this->delete("/vision-board-items/{$item->id}")->assertRedirect();

    expect(VisionBoardItem::find($item->id))->toBeNull();
    VisionBoardItem::disk()->assertMissing('vision-board/original.jpg');
});

test('destroy returns 403 for another user\'s item', function (): void {
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create();

    $this->delete("/vision-board-items/{$item->id}")->assertForbidden();

    expect(VisionBoardItem::find($item->id))->not->toBeNull();
});

test('images are stored on the private uploads disk, not the public one', function (): void {
    Storage::fake('public');

    $this->post('/vision-board-items', [
        'image' => UploadedFile::fake()->image('trip.jpg'),
        'caption' => 'Japan trip 2027',
    ])->assertRedirect();

    $item = VisionBoardItem::where('user_id', $this->user->id)->firstOrFail();

    VisionBoardItem::disk()->assertExists($item->image_path);
    Storage::disk('public')->assertMissing($item->image_path);
});

test('the owner can load their own image through the signed route', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/'.$this->user->id.'/original.jpg',
    ]);
    VisionBoardItem::disk()->put($item->image_path, 'fake-contents');

    $this->get($item->temporaryImageUrl())
        ->assertOk()
        ->assertHeader('cache-control', 'max-age=3600, private');
});

test('the image route returns 403 for another user\'s item', function (): void {
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create([
        'image_path' => 'vision-board/'.$other->id.'/private.jpg',
    ]);
    VisionBoardItem::disk()->put($item->image_path, 'fake-contents');

    $this->get($item->temporaryImageUrl())->assertForbidden();
});

test('the image route rejects an unsigned url', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create();

    $this->get(route('vision-board-items.image', $item))->assertForbidden();
});

test('the image route rejects a tampered signature', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create();
    $other = VisionBoardItem::factory()->for($this->user)->create();

    $url = $item->temporaryImageUrl();
    $tampered = str_replace((string) $item->id, (string) $other->id, $url);

    $this->get($tampered)->assertForbidden();
});

test('the image route 404s when the file is gone', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/'.$this->user->id.'/deleted.jpg',
    ]);

    $this->get($item->temporaryImageUrl())->assertNotFound();
});

test('an unauthenticated visitor cannot load an image', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create();
    $url = $item->temporaryImageUrl();

    auth()->logout();

    $this->get($url)->assertRedirect(route('login'));
});
