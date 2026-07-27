<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();
        $user->transactions()->findOrFail($transaction->id);

        match ($transaction->type) {
            'income' => $transaction->account->decrement('balance', $transaction->amount),
            'transfer' => (function () use ($transaction) {
                $transaction->account->increment('balance', $transaction->amount);
                $transaction->toAccount?->decrement('balance', $transaction->amount);
            })(),
            default => $transaction->account->increment('balance', $transaction->amount),
        };

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
        match ($transaction->type) {
            'income' => $transaction->account->decrement('balance', $transaction->amount),
            'transfer' => (function () use ($transaction) {
                $transaction->account->increment('balance', $transaction->amount);
                $transaction->toAccount?->decrement('balance', $transaction->amount);
            })(),
            default => $transaction->account->increment('balance', $transaction->amount),
        };

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

        match ($validated['type']) {
            'income' => $newAccount->increment('balance', $validated['amount']),
            'transfer' => (function () use ($user, $newAccount, $validated) {
                $toAccount = $user->accounts()->findOrFail($validated['to_account_id']);
                $newAccount->decrement('balance', $validated['amount']);
                $toAccount->increment('balance', $validated['amount']);
            })(),
            default => $newAccount->decrement('balance', $validated['amount']),
        };

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

        match ($type) {
            'income' => $account->increment('balance', $validated['amount']),
            'transfer' => (function () use ($account, $toAccount, $validated) {
                $account->decrement('balance', $validated['amount']);
                $toAccount->increment('balance', $validated['amount']);
            })(),
            default => $account->decrement('balance', $validated['amount']),
        };

        // Decrement the set-aside's saved amount when spending it
        if ($type === 'expense' && ! empty($validated['sinking_fund_id'])) {
            $fund = $user->sinkingFunds()->findOrFail($validated['sinking_fund_id']);
            $fund->decrement('saved', $validated['amount']);
        }

        return back();
    }
}
