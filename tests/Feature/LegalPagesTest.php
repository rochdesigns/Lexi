<?php

use App\Models\User;

test('the legal pages are reachable without signing in', function (string $route, string $component) {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'privacy' => ['privacy', 'legal/Privacy'],
    'terms' => ['terms', 'legal/Terms'],
]);

test('the legal pages are reachable while signed in', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertOk();
})->with(['privacy', 'terms']);

test('an unverified user can still read the legal pages', function (string $route) {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route($route))
        ->assertOk();
})->with(['privacy', 'terms']);

test('the landing page links to both legal pages', function () {
    // The links live in the Vue component, so there is nothing in the
    // server-rendered HTML to assert on; this guards the source instead.
    $source = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($source)
        ->toContain('terms()')
        ->toContain('privacy()');
});

test('the register page links to both legal pages', function () {
    $source = file_get_contents(resource_path('js/pages/auth/Register.vue'));

    expect($source)
        ->toContain('terms()')
        ->toContain('privacy()')
        ->toContain('name="terms"');
});
