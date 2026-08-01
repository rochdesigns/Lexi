<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();
        $user->transactions()->findOrFail($transaction->id);

        $this->reverseEffect($transaction->account, $transaction->type, (float) $transaction->amount);

        if ($transaction->type === 'transfer') {
            $transaction->toAccount?->decrement('balance', $transaction->amount);
        }

        $transaction->account->refresh()->syncLinkedDebt();

        // Restore the set-aside's saved amount when an expense linked to it is deleted
        if ($transaction->type === 'expense' && $transaction->sinking_fund_id) {
            $transaction->sinkingFund?->increment('saved', $transaction->amount);
        }

        // Reverse the goal top-up when its transaction is deleted
        if ($transaction->type === 'expense' && $transaction->goal_id) {
            $transaction->goal?->decrement('saved', $transaction->amount);
        }

        $transaction->delete();

        return back();
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();
        $user->transactions()->findOrFail($transaction->id);

        $type = $request->input('type', 'expense');
        $hasSinkingFund = ! empty($request->input('sinking_fund_id'));
        $hasGoal = ! empty($request->input('goal_id'));

        $validated = $request->validate([
            'type' => ['required', 'in:expense,income,transfer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'budget_category_id' => $type === 'expense' && ! $hasSinkingFund && ! $hasGoal
                ? ['required', 'integer', 'exists:budget_categories,id']
                : ['nullable', 'integer', 'exists:budget_categories,id'],
            'sinking_fund_id' => ['nullable', 'integer', 'exists:sinking_funds,id'],
            'goal_id' => ['nullable', 'integer', 'exists:goals,id'],
            'merchant' => ['nullable', 'string', 'max:100'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => $type === 'transfer'
                ? ['required', 'integer', 'exists:accounts,id', 'different:account_id']
                : ['nullable', 'integer', 'exists:accounts,id'],
            'transacted_at' => ['required', 'date'],
        ]);

        // Reverse old balance effect
        $this->reverseEffect($transaction->account, $transaction->type, (float) $transaction->amount);
        if ($transaction->type === 'transfer') {
            $transaction->toAccount?->decrement('balance', $transaction->amount);
        }

        // Reverse old sinking fund effect
        if ($transaction->type === 'expense' && $transaction->sinking_fund_id) {
            $transaction->sinkingFund?->increment('saved', $transaction->amount);
        }

        // Reverse old goal top-up effect
        if ($transaction->type === 'expense' && $transaction->goal_id) {
            $transaction->goal?->decrement('saved', $transaction->amount);
        }

        $transaction->update($validated);

        // Apply new balance effect
        $newAccount = $user->accounts()->findOrFail($validated['account_id']);
        $this->applyEffect($newAccount, $validated['type'], (float) $validated['amount']);

        if ($validated['type'] === 'transfer') {
            $toAccount = $user->accounts()->findOrFail($validated['to_account_id']);
            $toAccount->increment('balance', $validated['amount']);
        }

        $newAccount->refresh()->syncLinkedDebt();

        // Apply new sinking fund effect
        if ($validated['type'] === 'expense' && ! empty($validated['sinking_fund_id'])) {
            $fund = $user->sinkingFunds()->findOrFail($validated['sinking_fund_id']);
            $fund->decrement('saved', $validated['amount']);
        }

        // Apply new goal top-up effect
        if ($validated['type'] === 'expense' && ! empty($validated['goal_id'])) {
            $goal = $user->goals()->findOrFail($validated['goal_id']);
            $goal->increment('saved', $validated['amount']);
        }

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->input('type', 'expense');
        $hasSinkingFund = ! empty($request->input('sinking_fund_id'));
        $hasGoal = ! empty($request->input('goal_id'));

        $validated = $request->validate([
            'type' => ['required', 'in:expense,income,transfer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'budget_category_id' => $type === 'expense' && ! $hasSinkingFund && ! $hasGoal
                ? ['required', 'integer', 'exists:budget_categories,id']
                : ['nullable', 'integer', 'exists:budget_categories,id'],
            'sinking_fund_id' => ['nullable', 'integer', 'exists:sinking_funds,id'],
            'goal_id' => ['nullable', 'integer', 'exists:goals,id'],
            'merchant' => ['nullable', 'string', 'max:100'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => $type === 'transfer'
                ? ['required', 'integer', 'exists:accounts,id', 'different:account_id']
                : ['nullable', 'integer', 'exists:accounts,id'],
            'transacted_at' => ['required', 'date'],
        ]);

        $user = $request->user();

        if (! empty($validated['budget_category_id'])) {
            $user->budgetCategories()->findOrFail($validated['budget_category_id']);
        }

        $account = $user->accounts()->findOrFail($validated['account_id']);

        if ($type === 'transfer') {
            $toAccount = $user->accounts()->findOrFail($validated['to_account_id']);
        }

        $user->transactions()->create($validated);

        $this->applyEffect($account, $type, (float) $validated['amount']);

        if ($type === 'transfer') {
            $toAccount->increment('balance', $validated['amount']);
        }

        $account->refresh()->syncLinkedDebt();

        // Decrement the set-aside's saved amount when spending it
        if ($type === 'expense' && ! empty($validated['sinking_fund_id'])) {
            $fund = $user->sinkingFunds()->findOrFail($validated['sinking_fund_id']);
            $fund->decrement('saved', $validated['amount']);
        }

        return back();
    }

    private function applyEffect(Account $account, string $type, float $amount): void
    {
        if ($type === 'income') {
            $account->type === 'credit'
                ? $account->decrement('balance', $amount)
                : $account->increment('balance', $amount);
        } elseif ($type === 'transfer') {
            $account->decrement('balance', $amount);
        } else { // expense
            $account->type === 'credit'
                ? $account->increment('balance', $amount)
                : $account->decrement('balance', $amount);
        }
    }

    private function reverseEffect(Account $account, string $type, float $amount): void
    {
        if ($type === 'income') {
            $account->type === 'credit'
                ? $account->increment('balance', $amount)
                : $account->decrement('balance', $amount);
        } elseif ($type === 'transfer') {
            $account->increment('balance', $amount);
        } else { // expense
            $account->type === 'credit'
                ? $account->decrement('balance', $amount)
                : $account->increment('balance', $amount);
        }
    }
}
