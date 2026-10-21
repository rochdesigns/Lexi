<?php

namespace App\Services\Money;

use App\Http\Requests\Money\DebtRequest;
use App\Models\Money\Account;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionKind;
use App\Models\Money\TransactionSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The writes behind recording money: one place, shared by the web controllers
 * and the MCP write tools, so both move balances the same way.
 */
class Ledger
{
    public function __construct(private Projection $projection) {}

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
     * Changes an entry, undoing what it did to its accounts before and applying
     * what it does after, so balances follow the edit.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateTransaction(Transaction $transaction, array $attributes): Transaction
    {
        return DB::transaction(function () use ($transaction, $attributes): Transaction {
            $this->move($transaction, -1);
            $transaction->update($attributes);
            $this->move($transaction, 1);

            return $transaction;
        });
    }

    /**
     * Brings an account's balance to what the bank shows. The gap is recorded
     * as an adjustment, so the history says where the balance was corrected;
     * nothing is recorded when the two already match.
     */
    public function reconcileAccount(Account $account, int $actualBalance, ?string $date = null): ?Transaction
    {
        return DB::transaction(function () use ($account, $actualBalance, $date): ?Transaction {
            $current = Account::query()->lockForUpdate()->findOrFail($account->id)->balance;
            $difference = $actualBalance - $current;

            if ($difference === 0) {
                return null;
            }

            return $this->recordTransaction($account->user, [
                'kind' => $difference > 0 ? TransactionKind::AdjustmentIncrease : TransactionKind::AdjustmentDecrease,
                'date' => $date ?? now()->toDateString(),
                'amount' => abs($difference),
                'category' => TransactionCategory::Adjustment,
                'note' => 'Reconciliation adjustment',
                'source' => TransactionSource::Manual,
                'account_id' => $account->id,
            ]);
        });
    }

    /**
     * A new debt with what comes with it: a card's details and installments,
     * or a loan's or BNPL's first payment plan. The request has already
     * validated every type's own rules.
     */
    public function createDebt(User $user, DebtRequest $request): Debt
    {
        return DB::transaction(function () use ($user, $request): Debt {
            $debt = $user->debts()->create($request->debtAttributes());

            if ($request->isCard()) {
                $debt->cardDetail()->create($request->cardAttributes());

                foreach ($request->installmentAttributes() as $installment) {
                    $debt->paymentPlans()->create($installment);
                }
            }

            if ($request->wantsFirstPlan()) {
                $debt->paymentPlans()->create($request->planAttributes($this->projection));
            }

            return $debt;
        });
    }

    /**
     * Payments are history. A debt with only a balance (informal) has it
     * brought down, and a payment that names the account it was paid from takes
     * the amount out of that account.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDebtPayment(Debt $debt, array $attributes): DebtPayment
    {
        return DB::transaction(function () use ($debt, $attributes): DebtPayment {
            $payment = $debt->payments()->create($attributes);

            if ($payment->account_id !== null) {
                Account::whereKey($payment->account_id)->decrement('balance', $payment->amount);
            }

            if ($debt->type === DebtType::Informal && $debt->outstanding_balance !== null) {
                $debt->update(['outstanding_balance' => max(0, $debt->outstanding_balance - $payment->amount)]);
            }

            return $payment;
        });
    }

    /**
     * Takes a payment out of history, putting back what it did to the debt's
     * balance and to the account it was paid from.
     */
    public function removeDebtPayment(DebtPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $debt = $payment->debt;

            if ($debt->type === DebtType::Informal && $debt->outstanding_balance !== null) {
                $debt->update(['outstanding_balance' => $debt->outstanding_balance + $payment->amount]);
            }

            if ($payment->account_id !== null) {
                Account::whereKey($payment->account_id)->increment('balance', $payment->amount);
            }

            $payment->delete();
        });
    }

    /**
     * Deletes a debt with its history, first putting back into each account
     * what the debt's payments took from it.
     */
    public function removeDebt(Debt $debt): void
    {
        DB::transaction(function () use ($debt): void {
            foreach ($debt->payments()->whereNotNull('account_id')->get() as $payment) {
                Account::whereKey($payment->account_id)->increment('balance', $payment->amount);
            }

            $debt->delete();
        });
    }

    /**
     * Applies (1) or undoes (-1) what an entry did to its accounts: income adds
     * to the balance, an expense takes from it, and a transfer does both, one
     * on each side.
     */
    public function move(Transaction $transaction, int $direction): void
    {
        if ($transaction->kind === TransactionKind::Transfer) {
            if ($transaction->account_id !== null) {
                Account::whereKey($transaction->account_id)->decrement('balance', $direction * $transaction->amount);
            }

            if ($transaction->to_account_id !== null) {
                Account::whereKey($transaction->to_account_id)->increment('balance', $direction * $transaction->amount);
            }

            return;
        }

        if ($transaction->account_id === null) {
            return;
        }

        $adds = in_array($transaction->kind, [TransactionKind::Income, TransactionKind::AdjustmentIncrease], true);
        $change = $direction * ($adds ? 1 : -1) * $transaction->amount;

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
