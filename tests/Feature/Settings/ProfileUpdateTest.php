<?php

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Support\Facades\Storage;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('deleting an account removes its uploaded files', function () {
    Storage::fake(config('filesystems.uploads'));

    $user = User::factory()->create();
    $other = User::factory()->create();

    $item = VisionBoardItem::factory()->for($user)->create([
        'image_path' => VisionBoardItem::directoryFor($user->id).'/keepsake.jpg',
    ]);
    $survivor = VisionBoardItem::factory()->for($other)->create([
        'image_path' => VisionBoardItem::directoryFor($other->id).'/keepsake.jpg',
    ]);

    VisionBoardItem::disk()->put($item->image_path, 'fake-contents');
    VisionBoardItem::disk()->put($survivor->image_path, 'fake-contents');

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    VisionBoardItem::disk()->assertMissing($item->image_path);
    VisionBoardItem::disk()->assertDirectoryEmpty(VisionBoardItem::directoryFor($user->id));

    // Another account's directory is untouched.
    VisionBoardItem::disk()->assertExists($survivor->image_path);
});
