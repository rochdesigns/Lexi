<?php

namespace App\Services\Money;

use App\Models\Money\Account;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The writes behind recording money: one place, shared by the web controllers
 * and the MCP write tools, so both move balances the same way.
 */
class Ledger
{
    /**
     * Money received lands in the account straight away, and spending from an
     * account comes out of it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordTransaction(User $user, array $attributes): Transaction
    {
        return DB::transaction(function () use ($user, $attributes): Transaction {
            $transaction = $user->transactions()->create($attributes);

            $this->move($transaction, 1);

            return $transaction;
        });
    }

    /**
     * Payments are history. The one exception is a debt with only a balance
     * (informal), where a payment brings that balance down.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDebtPayment(Debt $debt, array $attributes): DebtPayment
    {
        return DB::transaction(function () use ($debt, $attributes): DebtPayment {
            $payment = $debt->payments()->create($attributes);

            if ($debt->type === DebtType::Informal && $debt->outstanding_balance !== null) {
                $debt->update(['outstanding_balance' => max(0, $debt->outstanding_balance - $payment->amount)]);
            }

            return $payment;
        });
    }

    /**
     * Applies (1) or undoes (-1) what an entry did to its account: income adds
     * to the balance, an expense takes from it.
     */
    public function move(Transaction $transaction, int $direction): void
    {
        if ($transaction->account_id === null) {
            return;
        }

        $change = $direction * ($transaction->kind === TransactionKind::Income ? 1 : -1) * $transaction->amount;

        Account::whereKey($transaction->account_id)->increment('balance', $change);
    }

    /**
     * The categories an expense can have.
     *
     * @return array<int, string>
     */
    public function expenseCategories(): array
    {
        return TransactionCategory::expenseValues();
    }
}
