<?php

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('an unknown url renders the error page', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('ErrorPage')
            ->where('status', 404)
        );
});

test('the error page keeps shared data so it can render the app shell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('ErrorPage')
            ->where('auth.user.email', $user->email)
        );
});

test('a forbidden action renders the error page', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create();

    $this->actingAs($user)
        ->get($item->temporaryImageUrl())
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('ErrorPage')
            ->where('status', 403)
        );
});

test('a server error renders the error page', function () {
    Route::middleware('web')->get('/explode', fn () => throw new RuntimeException('boom'));

    $this->get('/explode')
        ->assertStatus(500)
        ->assertInertia(fn ($page) => $page
            ->component('ErrorPage')
            ->where('status', 500)
        );
});

test('a request that wants json still gets json', function () {
    $this->getJson('/no-such-page')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});

test('statuses outside the rendered set are left alone', function () {
    Route::middleware('web')->get('/gone', fn () => abort(410));

    $response = $this->get('/gone');

    $response->assertStatus(410);
    expect($response->headers->get('x-inertia'))->toBeNull();
});

test('every fallback view renders without the vite build', function (int $status) {
    $rendered = view("errors.{$status}", ['exception' => new HttpException($status)])->render();

    expect($rendered)
        ->toContain((string) $status)
        ->not->toContain('Vite');
})->with([403, 404, 419, 429, 500, 503]);
