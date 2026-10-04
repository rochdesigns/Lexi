<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Models\Money\DebtType;
use App\Services\Money\Centavos;
use App\Services\Money\Projection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Tries a change without saving it. Nothing here writes to the user's data.
 */
class WhatIfController extends Controller
{
    public function __construct(private Projection $projection) {}

    /**
     * What a new installment would do to safe to spend, month by month.
     */
    public function afford(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'price' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'months' => ['required', 'integer', 'between:1,60'],
            'debt_id' => ['nullable', Rule::exists('debts', 'id')->where('user_id', $user->id)->where('type', DebtType::CreditCard->value)],
            'start_month' => ['nullable', 'required_without:debt_id', 'date_format:Y-m'],
        ]);

        $today = Carbon::today();
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $bills = $user->bills()->get();
        $card = isset($data['debt_id']) ? $debts->firstWhere('id', (int) $data['debt_id']) : null;

        return response()->json($this->projection->affordability(
            $debts,
            $bills,
            $user->incomeSources()->get(),
            $today,
            $this->projection->goalContributions($user->goals()->with('account')->get(), $debts, $bills, $today),
            $user->setting?->recovery_months,
            Centavos::fromPesos($data['price']),
            (int) $data['months'],
            $card,
            isset($data['start_month']) ? Carbon::createFromFormat('Y-m-d', $data['start_month'].'-01') : null,
        ));
    }

    /**
     * Which payment plans to clear first with a lump of spare cash.
     */
    public function extraCash(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:1000000000']]);

        $debts = $request->user()->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();

        return response()->json($this->projection->extraCashPlan($debts, Centavos::fromPesos($data['amount']), Carbon::today()));
    }

    /**
     * A card's carried balance as a balance conversion against leaving it revolving.
     */
    public function conversion(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'debt_id' => ['required', Rule::exists('debts', 'id')->where('user_id', $user->id)->where('type', DebtType::CreditCard->value)],
            'months' => ['required', 'integer', 'between:2,60'],
            'rate' => ['required', 'numeric', 'min:0', 'max:20'],
        ]);

        $card = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->findOrFail((int) $data['debt_id']);
        $comparison = $this->projection->conversionComparison($card, (int) $data['months'], (float) $data['rate'], Carbon::today());

        abort_if($comparison === null, 422, 'That card has no balance carried over.');

        return response()->json($comparison);
    }
}
