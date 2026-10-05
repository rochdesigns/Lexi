<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\CustomCategoryRequest;
use App\Models\Money\CustomCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomCategoryController extends Controller
{
    public function store(CustomCategoryRequest $request): RedirectResponse
    {
        $request->user()->customCategories()->create(['name' => $request->validated('name')]);

        return back();
    }

    /**
     * Entries in the category fall back to "other".
     */
    public function destroy(Request $request, CustomCategory $customCategory): RedirectResponse
    {
        abort_if($customCategory->user_id !== $request->user()->id, 403);

        $customCategory->delete();

        return back();
    }
}
