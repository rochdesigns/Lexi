<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Services\Money\Centavos;
use App\Services\Money\Projection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PaymentAllocationController extends Controller
{
    /**
     * How an amount would be split across the user's cards. Saves nothing.
     */
    public function __invoke(Request $request, Projection $projection): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        $debts = $request->user()->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();

        return response()->json($projection->allocatePayment($debts, Centavos::fromPesos($data['amount'] ?? 0), Carbon::today()));
    }
}
