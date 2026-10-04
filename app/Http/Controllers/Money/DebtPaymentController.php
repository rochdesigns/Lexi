<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\DebtPaymentRequest;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Services\Money\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtPaymentController extends Controller
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Payments are history. The one exception is a debt with only a balance
     * (informal), where a payment brings that balance down.
     */
    public function store(DebtPaymentRequest $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $this->ledger->recordDebtPayment($debt, $request->paymentAttributes());

        return back();
    }

    public function destroy(Request $request, DebtPayment $debtPayment): RedirectResponse
    {
        $debt = $debtPayment->debt;
        abort_if($debt->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($debt, $debtPayment): void {
            if ($debt->type === DebtType::Informal && $debt->outstanding_balance !== null) {
                $debt->update(['outstanding_balance' => $debt->outstanding_balance + $debtPayment->amount]);
            }

            $debtPayment->delete();
        });

        return back();
    }
}
