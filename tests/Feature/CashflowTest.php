<?php

use App\Models\User;

test('guests are redirected to login', function () {
    $this->get('/cashflow')->assertRedirect('/login');
});

test('renders for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/cashflow')->assertOk();
});
