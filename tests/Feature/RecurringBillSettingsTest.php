<?php

use App\Models\RecurringBill;
use App\Models\User;

test('guests are redirected to login', function () {
    $this->get('/settings/bills')->assertRedirect('/login');
});

test('bills settings page lists the user\'s recurring bills', function () {
    $user = User::factory()->create();
    RecurringBill::factory()->for($user)->create(['name' => 'Netflix', 'day_of_month' => 5]);

    $this->actingAs($user)
        ->get('/settings/bills')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Bills')
            ->has('bills', 1)
            ->where('bills.0.name', 'Netflix')
        );
});
