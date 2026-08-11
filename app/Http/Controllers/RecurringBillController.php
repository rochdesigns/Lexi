<?php

namespace App\Http\Controllers;

use App\Models\BudgetCategory;
use App\Models\RecurringBill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class RecurringBillController extends Controller
{
    public function index(Request $request): Response
    {
        $bills = $request->user()->recurringBills()
            ->with('budgetCategory')
            ->orderBy('day_of_month')
            ->get()
            ->map(fn ($bill) => [
                'id' => $bill->id,
                'name' => $bill->name,
                'icon' => $bill->icon,
                'color' => $bill->color,
                'bg' => $bill->bg,
                'amount' => (float) $bill->amount,
                'day_of_month' => $bill->day_of_month,
                'budget_category_id' => $bill->budget_category_id,
                'category' => $bill->budgetCategory ? [
                    'id' => $bill->budgetCategory->id,
                    'name' => $bill->budgetCategory->name,
                    'color' => $bill->budgetCategory->color,
                ] : null,
            ]);

        return Inertia::render('settings/Bills', ['bills' => $bills]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $appearance = $this->appearanceFromCategory($request->user()->id, $validated['budget_category_id'] ?? null);

        $bill = $request->user()->recurringBills()->create(array_merge($validated, $appearance));

        if ($bill->budget_category_id) {
            $this->adjustCategoryLimit($request->user()->id, $bill->budget_category_id, (float) $bill->amount);
        }

        return back();
    }

    public function update(Request $request, RecurringBill $recurringBill): RedirectResponse
    {
        abort_if($recurringBill->user_id !== $request->user()->id, 403);

        $validated = $request->validate($this->rules());

        $oldCategoryId = $recurringBill->budget_category_id;
        $oldAmount = (float) $recurringBill->amount;
        $newCategoryId = $validated['budget_category_id'] ?? null;
        $newAmount = (float) $validated['amount'];
        $userId = $request->user()->id;

        $appearance = $this->appearanceFromCategory($userId, $newCategoryId);

        $recurringBill->update(array_merge($validated, $appearance));

        if ($oldCategoryId !== $newCategoryId) {
            if ($oldCategoryId) {
                $this->adjustCategoryLimit($userId, $oldCategoryId, -$oldAmount);
            }
            if ($newCategoryId) {
                $this->adjustCategoryLimit($userId, $newCategoryId, $newAmount);
            }
        } elseif ($oldCategoryId && $oldAmount !== $newAmount) {
            $this->adjustCategoryLimit($userId, $oldCategoryId, $newAmount - $oldAmount);
        }

        return back();
    }

    public function pay(Request $request, RecurringBill $recurringBill): RedirectResponse
    {
        abort_if($recurringBill->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'merchant' => ['nullable', 'string', 'max:100'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'budget_category_id' => ['nullable', 'integer', 'exists:budget_categories,id'],
            'transacted_at' => ['required', 'date'],
        ]);

        $user = $request->user();
        $account = $user->accounts()->findOrFail($validated['account_id']);

        $user->transactions()->create([
            'type' => 'expense',
            'amount' => $validated['amount'],
            'merchant' => $validated['merchant'],
            'account_id' => $validated['account_id'],
            'budget_category_id' => $validated['budget_category_id'] ?? null,
            'transacted_at' => $validated['transacted_at'],
        ]);

        $account->decrement('balance', $validated['amount']);

        $recurringBill->update(['last_paid_at' => Carbon::parse($validated['transacted_at'])->toDateString()]);

        return back();
    }

    public function destroy(Request $request, RecurringBill $recurringBill): RedirectResponse
    {
        abort_if($recurringBill->user_id !== $request->user()->id, 403);

        if ($recurringBill->budget_category_id) {
            $this->adjustCategoryLimit($request->user()->id, $recurringBill->budget_category_id, -(float) $recurringBill->amount);
        }

        $recurringBill->delete();

        return back();
    }

    private function adjustCategoryLimit(int $userId, int $categoryId, float $delta): void
    {
        BudgetCategory::where('id', $categoryId)
            ->where('user_id', $userId)
            ->increment('limit_amount', $delta);
    }

    /** @return array{icon: string, color: string, bg: string} */
    private function appearanceFromCategory(int $userId, ?int $categoryId): array
    {
        if ($categoryId) {
            $cat = BudgetCategory::where('id', $categoryId)->where('user_id', $userId)->first();
            if ($cat) {
                return ['icon' => $cat->icon, 'color' => $cat->color, 'bg' => $cat->color.'22'];
            }
        }

        return ['icon' => 'receipt', 'color' => '#3f7fb0', 'bg' => '#dcebf5'];
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'budget_category_id' => ['nullable', 'integer', 'exists:budget_categories,id'],
        ];
    }
}
