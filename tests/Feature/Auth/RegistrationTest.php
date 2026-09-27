<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => 'on',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration creates the default settings row', function () {
    $this->post(route('register.store'), [
        'name' => 'Cycle Test',
        'email' => 'cycle@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => 'on',
    ]);

    $user = User::where('email', 'cycle@example.com')->first();

    expect($user->setting)->not->toBeNull();
    expect($user->setting->theme)->toBe('ocean');
});

test('registration is refused without consent', function (mixed $terms) {
    $payload = [
        'name' => 'No Consent',
        'email' => 'no-consent@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    if ($terms !== null) {
        $payload['terms'] = $terms;
    }

    $this->post(route('register.store'), $payload)
        ->assertSessionHasErrors('terms');

    $this->assertGuest();
    expect(User::where('email', 'no-consent@example.com')->exists())->toBeFalse();
})->with([
    'absent' => null,
    'unchecked' => 'off',
    'false' => '0',
    'empty' => '',
]);

test('registration records when consent was given', function () {
    $this->travelTo(now()->startOfSecond());

    $this->post(route('register.store'), [
        'name' => 'Consenting User',
        'email' => 'consent@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => 'on',
    ]);

    $user = User::where('email', 'consent@example.com')->firstOrFail();

    expect($user->terms_accepted_at)->not->toBeNull()
        ->and($user->terms_accepted_at->timestamp)->toBe(now()->timestamp);
});

test('consent cannot be back-dated through request input', function () {
    $this->post(route('register.store'), [
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => 'on',
        'terms_accepted_at' => '1999-01-01 00:00:00',
    ]);

    $user = User::where('email', 'sneaky@example.com')->firstOrFail();

    expect($user->terms_accepted_at->year)->toBe(now()->year);
});
