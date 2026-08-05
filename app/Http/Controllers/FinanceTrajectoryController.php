<?php

namespace App\Http\Controllers;

use App\Services\TrajectoryReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceTrajectoryController extends Controller
{
    public function __construct(private TrajectoryReportService $trajectory) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'extra_monthly' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $extraMonthly = isset($validated['extra_monthly']) ? (float) $validated['extra_monthly'] : 0.0;

        return Inertia::render('Trajectory', [
            'extraMonthly' => $extraMonthly,
            'netWorth' => $this->trajectory->netWorth($user),
            'savingsRate' => $this->trajectory->savingsRate($user),
            'debt' => $this->trajectory->debtSummary($user, $extraMonthly),
            'savings' => $this->trajectory->savingsSummary($user),
            'investments' => $this->trajectory->investmentsSummary($user),
            'monthlyFlow' => $this->trajectory->monthlyFlow($user),
            'signature' => $this->trajectory->signatureMetric($user),
        ]);
    }
}
