<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** MySQL refuses identifiers longer than 64 characters. */
test('every spending limits index name fits mysql', function (): void {
    $names = array_column(Schema::getIndexes('spending_limits'), 'name');

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64);
    }
});

/**
 * A deploy where MySQL created the table and then refused the unique index
 * leaves the table behind without it. Running the migration again finishes the job.
 */
test('the migration adds the unique index to a table left without it', function (): void {
    Schema::drop('spending_limits');
    Schema::create('spending_limits', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('category');
        $table->foreignId('custom_category_id')->nullable()->constrained()->cascadeOnDelete();
        $table->unsignedBigInteger('amount')->nullable();
        $table->date('starts_on');
        $table->timestamps();
    });

    $migration = require database_path('migrations/2026_10_31_224518_create_spending_limits_table.php');
    $migration->up();

    expect(Schema::hasIndex('spending_limits', ['user_id', 'category', 'custom_category_id', 'starts_on'], 'unique'))->toBeTrue();
});
