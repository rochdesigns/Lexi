<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\BudgetCycle;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetCycleController extends Controller
{
    public function edit(Request $request): Response
    {
        $setting = $request->user()->setting;
        $currentCycle = (new BudgetCycle($setting))->current();

        return Inertia::render('settings/BudgetCycle', [
            'cycleDays' => $setting?->budget_cycle_days ?? 30,
            'cycleAnchor' => $setting?->budget_cycle_anchor?->toDateString() ?? Carbon::today()->toDateString(),
            'cycleType' => $setting?->cycle_type ?? 'fixed_days',
            'monthlyStartDay' => $setting?->monthly_start_day,
            // If the user switches to Monthly without picking a day, default to the
            // day-of-month their *current* cycle actually starts on — not the raw
            // anchor date, which can differ (e.g. anchor Jun 24 but the cycle that
            // contains today starts Jul 25).
            'suggestedMonthlyStartDay' => $currentCycle->start->day,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cycle_type' => ['required', 'string', Rule::in(['fixed_days', 'monthly'])],
            'cycle_days' => ['required_if:cycle_type,fixed_days', 'nullable', 'integer', 'min:1', 'max:365'],
            'cycle_anchor' => ['required_if:cycle_type,fixed_days', 'nullable', 'date'],
            'monthly_start_day' => ['required_if:cycle_type,monthly', 'nullable', 'integer', 'min:1', 'max:31'],
        ]);

        $setting = $request->user()->setting;
        $setting->cycle_type = $validated['cycle_type'];

        if ($validated['cycle_type'] === 'fixed_days') {
            $setting->budget_cycle_days = $validated['cycle_days'];
            $setting->budget_cycle_anchor = Carbon::parse($validated['cycle_anchor']);
        } else {
            $setting->monthly_start_day = $validated['monthly_start_day'];
        }

        $setting->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Budget cycle updated.']);

        return to_route('budget-cycle.edit');
    }
}
