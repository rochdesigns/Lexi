<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per card per month: the revolving balance last saved that month,
     * in integer centavos. month is "Y-m". Existing cards start with a row for
     * the current month, since their earlier balances were never kept.
     */
    public function up(): void
    {
        Schema::create('card_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->unsignedBigInteger('revolving_balance');
            $table->timestamps();

            $table->unique(['debt_id', 'month']);
        });

        $now = now();

        DB::table('card_details')->get(['debt_id', 'revolving_balance'])->each(function (object $card) use ($now): void {
            DB::table('card_balance_snapshots')->insert([
                'debt_id' => $card->debt_id,
                'month' => $now->format('Y-m'),
                'revolving_balance' => $card->revolving_balance,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_balance_snapshots');
    }
};
