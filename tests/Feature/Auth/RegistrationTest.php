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
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration creates a setting with budget_cycle_days = 30', function () {
    $this->post(route('register.store'), [
        'name' => 'Cycle Test',
        'email' => 'cycle@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'cycle@example.com')->first();

    expect($user->setting)->not->toBeNull();
    expect($user->setting->budget_cycle_days)->toBe(30);
    expect($user->setting->budget_cycle_anchor)->not->toBeNull();
});
