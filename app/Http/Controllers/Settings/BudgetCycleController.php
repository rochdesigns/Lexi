<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetCycleController extends Controller
{
    public function edit(Request $request): Response
    {
        $setting = $request->user()->setting;

        return Inertia::render('settings/BudgetCycle', [
            'cycleDays' => $setting?->budget_cycle_days ?? 30,
            'cycleAnchor' => $setting?->budget_cycle_anchor?->toDateString() ?? Carbon::today()->toDateString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cycle_days' => ['required', 'integer', 'min:1', 'max:365'],
            'cycle_anchor' => ['required', 'date'],
        ]);

        $setting = $request->user()->setting;
        $setting->budget_cycle_days = $validated['cycle_days'];
        $setting->budget_cycle_anchor = Carbon::parse($validated['cycle_anchor']);
        $setting->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Budget cycle updated.']);

        return to_route('budget-cycle.edit');
    }
}
