<?php

use App\Models\User;
use Carbon\Carbon;

test('guest is redirected from reports page', function () {
    $this->get(route('reports'))->assertRedirect(route('login'));
});

test('reports page renders with Inertia component and month period by default', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->component('Reports')
            ->where('period.type', 'month')
            ->where('period.label', 'August 2026')
            ->where('period.prevDate', '2026-07-15')
            ->where('period.isCurrent', true)
            ->where('period.nextDate', null)
        );

    Carbon::setTestNow();
});

test('reports page resolves quarter and year periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->where('period.type', 'quarter')
            ->where('period.label', 'Q3 2026')
        );

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'year']))
        ->assertInertia(fn ($page) => $page
            ->where('period.type', 'year')
            ->where('period.label', '2026')
        );

    Carbon::setTestNow();
});

test('reports page allows navigating to a past period and re-enables next', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'June 2026')
            ->where('period.isCurrent', false)
            ->where('period.nextDate', '2026-07-01')
        );

    Carbon::setTestNow();
});
