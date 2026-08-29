<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvestingController extends Controller
{
    public function edit(Request $request): Response
    {
        $setting = $request->user()->setting;

        return Inertia::render('settings/Investing', [
            'fiNumber' => $setting?->fi_number,
            'fiExpectedReturnRate' => $setting?->fi_expected_return_rate ?? '6.00',
            'emergencyFundTargetMonths' => $setting?->emergency_fund_target_months ?? 6,
            'investmentMonthlyContributionTarget' => $setting?->investment_monthly_contribution_target,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fi_number' => ['nullable', 'numeric', 'min:0'],
            'fi_expected_return_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'emergency_fund_target_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'investment_monthly_contribution_target' => ['nullable', 'numeric', 'min:0'],
        ]);

        $setting = $request->user()->setting;
        $setting->fi_number = $validated['fi_number'] ?? null;
        $setting->fi_expected_return_rate = $validated['fi_expected_return_rate'] ?? null;
        $setting->emergency_fund_target_months = $validated['emergency_fund_target_months'] ?? null;
        $setting->investment_monthly_contribution_target = $validated['investment_monthly_contribution_target'] ?? null;
        $setting->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Investing settings updated.']);

        return to_route('investing.edit');
    }
}
