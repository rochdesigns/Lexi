<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
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
     * Undoes everything a transaction did, so it can be edited or removed
     * without leaving the balances it moved behind. Every account it touched is
     * put back, and a debt whose balance it reduced directly is restored too.
     */
    public function reverseEffects(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $amount = (float) $transaction->amount;

            match ($transaction->type) {
                'income' => $this->applyToAccountId($transaction->account_id, $amount, positive: false),
                'expense' => $this->applyToAccountId($transaction->account_id, $amount, positive: true),
                'transfer' => $this->reverseTransfer($transaction, $amount),
                default => throw new InvalidArgumentException("Cannot reverse a {$transaction->type} transaction."),
            };

            $this->restoreDebtBalance($transaction, $amount);
        });
    }

    /**
     * Applies a transaction's effects from its current fields — the other half
     * of reverseEffects(), used to re-land an edited entry.
     */
    public function applyEffects(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $amount = (float) $transaction->amount;

            match ($transaction->type) {
                'income' => $this->applyToAccountId($transaction->account_id, $amount, positive: true),
                'expense' => $this->applyToAccountId($transaction->account_id, $amount, positive: false),
                'transfer' => $this->applyTransfer($transaction, $amount),
                default => throw new InvalidArgumentException("Cannot apply a {$transaction->type} transaction."),
            };

            $this->reduceDebtBalance($transaction, $amount);
        });
    }

    private function reverseTransfer(Transaction $transaction, float $amount): void
    {
        $this->applyToAccountId($transaction->from_account_id, $amount, positive: true);
        $this->applyToAccountId($transaction->to_account_id, $amount, positive: false);
    }

    private function applyTransfer(Transaction $transaction, float $amount): void
    {
        $this->applyToAccountId($transaction->from_account_id, $amount, positive: false);
        $this->applyToAccountId($transaction->to_account_id, $amount, positive: true);
    }

    /**
     * An account leg can be missing — a transfer keeps its history after one
     * side is deleted — in which case there is nothing left to put back.
     */
    private function applyToAccountId(?int $accountId, float $amount, bool $positive): void
    {
        if ($accountId === null) {
            return;
        }

        $account = Account::find($accountId);

        if ($account !== null) {
            $this->applyEffect($account, $amount, $positive);
        }
    }

    /**
     * A debt mirrored by a ledger account tracks that account, so reversing the
     * account is enough. One without a linked account was reduced directly when
     * the payment was recorded, so it has to be put back by hand.
     */
    private function restoreDebtBalance(Transaction $transaction, float $amount): void
    {
        $debt = $this->unlinkedDebtFor($transaction);

        $debt?->update(['balance' => (float) $debt->balance + $amount]);
    }

    private function reduceDebtBalance(Transaction $transaction, float $amount): void
    {
        $debt = $this->unlinkedDebtFor($transaction);

        $debt?->update(['balance' => max(0.0, (float) $debt->balance - $amount)]);
    }

    private function unlinkedDebtFor(Transaction $transaction): ?Debt
    {
        if ($transaction->debt_id === null) {
            return null;
        }

        $debt = Debt::find($transaction->debt_id);

        return $debt !== null && $debt->linked_account_id === null ? $debt : null;
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
