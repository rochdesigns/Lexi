<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\IncomeSourceRequest;
use App\Models\Money\IncomeSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IncomeSourceController extends Controller
{
    public function store(IncomeSourceRequest $request): RedirectResponse
    {
        $request->user()->incomeSources()->create($request->incomeAttributes());

        return back();
    }

    public function update(IncomeSourceRequest $request, IncomeSource $incomeSource): RedirectResponse
    {
        abort_if($incomeSource->user_id !== $request->user()->id, 403);

        $incomeSource->update($request->incomeAttributes());

        return back();
    }

    public function destroy(Request $request, IncomeSource $incomeSource): RedirectResponse
    {
        abort_if($incomeSource->user_id !== $request->user()->id, 403);

        $incomeSource->delete();

        return back();
    }
}
