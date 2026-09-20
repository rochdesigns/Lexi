<?php

namespace App\Http\Controllers;

use App\Services\DebtFocus\ForecastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ForecastController extends Controller
{
    public function index(Request $request, ForecastService $forecast): Response
    {
        $user = $request->user();
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);

        $expectedIncome = $settings->expected_monthly_income !== null
            ? (float) $settings->expected_monthly_income
            : null;

        // Every number on the rail hangs off the expected income, so with no
        // answer there is nothing honest to draw — the page asks for it first.
        if ($expectedIncome === null) {
            return Inertia::render('Forecast', [
                'expectedMonthlyIncome' => null,
                'suggestedMonthlyIncome' => $forecast->suggestedMonthlyIncome($user),
                'months' => [],
                'openingBalance' => null,
                'closingBalance' => null,
                'tightMonth' => null,
            ]);
        }

        $projection = $forecast->project($user, $expectedIncome);

        return Inertia::render('Forecast', [
            'expectedMonthlyIncome' => $expectedIncome,
            'suggestedMonthlyIncome' => null,
            'months' => $projection['months'],
            'openingBalance' => $projection['openingBalance'],
            'closingBalance' => $projection['closingBalance'],
            'tightMonth' => $projection['tightMonth'],
        ]);
    }

    public function updateIncome(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'expected_monthly_income' => ['required', 'numeric', 'min:0'],
        ]);

        $settings = $request->user()->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $settings->update($validated);

        return back();
    }
}
