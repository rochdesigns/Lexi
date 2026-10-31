<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Short enough for MySQL's 64-character limit on identifiers. */
    private const UNIQUE_INDEX = 'spending_limits_category_month_unique';

    /**
     * A monthly cap on spending in one category, from the month it was set or
     * changed. A custom category's limit keeps "other" and points at it, as its
     * entries do. No amount means no limit from that month on.
     *
     * A first deploy had MySQL create the table and then refuse the index's
     * default name, leaving the table behind. Running again only adds the index.
     */
    public function up(): void
    {
        if (! Schema::hasTable('spending_limits')) {
            Schema::create('spending_limits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('category');
                $table->foreignId('custom_category_id')->nullable()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('amount')->nullable();
                $table->date('starts_on');
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('spending_limits', self::UNIQUE_INDEX)) {
            Schema::table('spending_limits', function (Blueprint $table): void {
                $table->unique(['user_id', 'category', 'custom_category_id', 'starts_on'], self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_limits');
    }
};
