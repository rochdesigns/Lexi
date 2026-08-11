<?php

namespace App\Http\Controllers;

use App\Models\BudgetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $categories = $user->budgetCategories()->orderBy('group')->orderBy('name')->get();

        $editId = $request->query('edit');
        $editCategoryId = $editId && $categories->contains('id', (int) $editId) ? (int) $editId : null;

        return Inertia::render('settings/Categories', [
            'categories' => $categories,
            'editCategoryId' => $editCategoryId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $category = $request->user()->budgetCategories()->create($validated);

        return back()->with('newCategoryId', $category->id);
    }

    public function update(Request $request, BudgetCategory $budgetCategory): RedirectResponse
    {
        abort_if($budgetCategory->user_id !== $request->user()->id, 403);

        $validated = $request->validate($this->rules());

        $budgetCategory->update($validated);

        return back();
    }

    public function destroy(Request $request, BudgetCategory $budgetCategory): RedirectResponse
    {
        abort_if($budgetCategory->user_id !== $request->user()->id, 403);

        $budgetCategory->delete();

        return back();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'group' => ['nullable', 'string', 'max:50'],
            'icon' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'limit_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
