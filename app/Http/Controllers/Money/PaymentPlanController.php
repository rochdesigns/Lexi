<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\PaymentPlanRequest;
use App\Models\Money\Debt;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Services\Money\Centavos;
use App\Services\Money\Projection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentPlanController extends Controller
{
    public function __construct(private Projection $projection) {}

    /**
     * What a plan would cost, from the form as it is being filled in. Saves nothing.
     */
    public function preview(Request $request, Debt $debt): JsonResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'principal' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'total_months' => ['nullable', 'integer', 'between:1,360'],
            'interest_type' => ['nullable', Rule::in([InterestType::None->value, InterestType::Amortized->value, InterestType::AddOn->value])],
        ]);

        $months = $data['total_months'] ?? null;
        $principal = ! empty($data['principal']) ? Centavos::fromPesos($data['principal']) : null;
        $monthly = ! empty($data['monthly_amount']) ? Centavos::fromPesos($data['monthly_amount']) : null;

        $plan = new PaymentPlan([
            'principal' => $principal ?? 0,
            'monthly_amount' => $monthly ?? 0,
            'total_months' => $months,
            'start_month' => now()->startOfMonth(),
            'interest_type' => $data['interest_type'] ?? null,
        ]);
        $plan->setRelation('debt', $debt);

        $type = $this->projection->interestTypeOf($plan);

        if ($months === null) {
            return response()->json(['summary' => null, 'missing' => 'months']);
        }

        if ($type === InterestType::Amortized) {
            if ($debt->monthly_interest_rate === null) {
                return response()->json(['summary' => null, 'missing' => 'rate']);
            }

            if ($principal === null) {
                return response()->json(['summary' => null, 'missing' => 'principal']);
            }

            $plan->monthly_amount = $monthly ?? $this->projection->amortizedPayment($principal, $debt->monthly_interest_rate, $months);
        } elseif ($monthly === null) {
            return response()->json(['summary' => null, 'missing' => 'monthly']);
        } elseif ($type === InterestType::AddOn && $principal === null) {
            return response()->json(['summary' => null, 'missing' => 'principal']);
        }

        return response()->json(['summary' => $this->projection->planSummary($plan), 'missing' => null]);
    }

    public function store(PaymentPlanRequest $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $debt->paymentPlans()->create($request->planAttributes($this->projection));

        return back();
    }

    public function update(PaymentPlanRequest $request, PaymentPlan $paymentPlan): RedirectResponse
    {
        abort_if($paymentPlan->debt->user_id !== $request->user()->id, 403);

        $paymentPlan->update($request->planAttributes($this->projection));

        return back();
    }

    public function destroy(Request $request, PaymentPlan $paymentPlan): RedirectResponse
    {
        abort_if($paymentPlan->debt->user_id !== $request->user()->id, 403);

        $paymentPlan->delete();

        return back();
    }
}
