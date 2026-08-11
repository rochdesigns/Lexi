<?php

use App\Models\BudgetCategory;
use App\Models\User;

test('guests are redirected to login', function () {
    $this->get('/settings/categories')->assertRedirect('/login');
});

test('categories settings page lists the user\'s categories', function () {
    $user = User::factory()->create();
    BudgetCategory::factory()->for($user)->create(['name' => 'Groceries', 'group' => 'Essentials']);

    $this->actingAs($user)
        ->get('/settings/categories')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Categories')
            ->has('categories', 1)
            ->where('categories.0.name', 'Groceries')
            ->where('editCategoryId', null)
        );
});

test('an edit query param is passed through as editCategoryId when it belongs to the user', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();
    $otherUsersCategory = BudgetCategory::factory()->create();

    $this->actingAs($user)
        ->get("/settings/categories?edit={$category->id}")
        ->assertInertia(fn ($page) => $page->where('editCategoryId', $category->id));

    $this->actingAs($user)
        ->get("/settings/categories?edit={$otherUsersCategory->id}")
        ->assertInertia(fn ($page) => $page->where('editCategoryId', null));
});
