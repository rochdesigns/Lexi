<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerService
{
    public function recordIncome(Account $account, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description, $occurredAt) {
            $this->applyEffect($account, $amount, positive: true);

            return Transaction::create([
                'user_id' => $account->user_id,
                'type' => 'income',
                'account_id' => $account->id,
                'amount' => $amount,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    public function recordExpense(Account $account, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null, ?int $envelopeId = null): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description, $occurredAt, $envelopeId) {
            $this->applyEffect($account, $amount, positive: false);

            return Transaction::create([
                'user_id' => $account->user_id,
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => $amount,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
                'envelope_id' => $envelopeId,
            ]);
        });
    }

    public function recordTransfer(Account $from, Account $to, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null): Transaction
    {
        if ($from->user_id !== $to->user_id) {
            throw new InvalidArgumentException('Cannot transfer between accounts owned by different users.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $description, $occurredAt) {
            $this->applyEffect($from, $amount, positive: false);
            $this->applyEffect($to, $amount, positive: true);

            return Transaction::create([
                'user_id' => $from->user_id,
                'type' => 'transfer',
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    /**
     * A "positive event" (income, or being a transfer's destination) increases an
     * asset account's balance and decreases a liability account's balance; a
     * "negative event" (expense, or being a transfer's source) does the opposite.
     */
    private function applyEffect(Account $account, float $amount, bool $positive): void
    {
        $delta = $positive ? $amount : -$amount;

        if ($account->is_liability) {
            $delta = -$delta;
        }

        $account->increment('balance', $delta);
    }
}
