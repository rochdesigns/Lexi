<?php

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with invalid user id', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => 123, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verified user is redirected to dashboard from verification prompt', function () {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('the user model requires email verification', function () {
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class);
});

test('registration sends a verification notification', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Fresh User',
        'email' => 'fresh@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => 'on',
    ]);

    Notification::assertSentTo(
        User::where('email', 'fresh@example.com')->firstOrFail(),
        QueuedVerifyEmail::class,
    );
});

test('an unverified user cannot reach a verified route', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('today'))
        ->assertRedirect(route('verification.notice', absolute: false));
});

test('a verified user can reach a verified route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('today'))->assertOk();
});

test('a verification link opened without a session does not verify the address', function () {
    // This is how SESSION_SAME_SITE=strict broke verification in production.
    // Strict withholds the session cookie on a cross-site top-level navigation,
    // and clicking a link in a mail client is exactly that. On the verify route
    // auth:web runs ahead of signed, so the click arrives as a guest, redirects,
    // and the signature is never even reached -- no 403, no log, nothing.
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->get($verificationUrl)->assertRedirect(route('login'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired verification link sends a fresh one instead of a dead end', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $expiredUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($expiredUrl)
        ->assertRedirect(route('verification.notice', absolute: false))
        ->assertSessionHas('status', 'verification-link-expired');

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a verification link with a tampered signature does not send a fresh one', function () {
    // A signature that fails while the link is still inside its hour did not
    // age out: it was altered, or the link was issued for a different origin
    // than the one it arrived at. Sending another cannot help with either, and
    // when the cause is systematic every click mints one more.
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($url.'tampered')
        ->assertRedirect(route('verification.notice', absolute: false))
        ->assertSessionHas('status', 'verification-link-invalid');

    Notification::assertNothingSent();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a link signed for http is refused without resending when the click arrives over https', function () {
    // The production failure this guards: queued mail is built on the command
    // line, so the scheme comes only from APP_URL. With APP_URL on http, nginx
    // redirected every click to https and the signature — which covers the
    // scheme — no longer matched what arrived.
    Notification::fake();

    $user = User::factory()->unverified()->create();

    URL::forceRootUrl('http://localhost');

    $signedForHttp = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    URL::forceRootUrl(null);

    expect($signedForHttp)->toStartWith('http://localhost/');

    $this->actingAs($user)->get(str_replace('http://', 'https://', $signedForHttp))
        ->assertRedirect(route('verification.notice', absolute: false))
        ->assertSessionHas('status', 'verification-link-invalid');

    Notification::assertNothingSent();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired verification link for an already verified address says so', function () {
    Notification::fake();

    $user = User::factory()->create();

    $expiredUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($expiredUrl)
        ->assertRedirect(route('today', absolute: false))
        ->assertSessionHas('inertia.flash_data');

    Notification::assertNothingSent();
});

test('an expired verification link for another account is still refused', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $expiredUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $other->id, 'hash' => sha1($other->email)],
    );

    $this->actingAs($user)->get($expiredUrl)->assertForbidden();

    Notification::assertNothingSent();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
