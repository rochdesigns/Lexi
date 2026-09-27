<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

beforeEach(function () {
    RateLimiter::clear('');
});

test('registration is throttled per ip', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => "signup-{$attempt}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => 'on',
        ]);

        $this->post(route('logout'));
    }

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'signup-over-limit@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => 'on',
    ]);

    $response->assertStatus(429);
    expect(User::where('email', 'signup-over-limit@example.com')->exists())->toBeFalse();
});

test('password reset link requests are throttled per ip across distinct addresses', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    Notification::fake();

    // Laravel's password broker already throttles one address to once a
    // minute. The uncapped vector is one client walking many addresses, so
    // this asserts the per-IP ceiling rather than the per-user one.
    foreach (range(1, 10) as $attempt) {
        $this->post(route('password.email'), ['email' => "probe-{$attempt}@example.com"])
            ->assertStatus(302);
    }

    $this->post(route('password.email'), ['email' => 'probe-11@example.com'])
        ->assertStatus(429);
});

test('one address cannot be mail bombed from rotating ips', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    Notification::fake();

    $user = User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 6) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
            ->post(route('password.email'), ['email' => 'victim@example.com']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post(route('password.email'), ['email' => 'victim@example.com'])
        ->assertStatus(429);
});

test('reading the login page is not throttled by the fortify limiter', function () {
    foreach (range(1, 12) as $attempt) {
        $this->get(route('login'))->assertOk();
    }
});
