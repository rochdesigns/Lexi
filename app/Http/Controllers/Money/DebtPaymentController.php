<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\DebtPaymentRequest;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Services\Money\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DebtPaymentController extends Controller
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Payments are history, unless they name an account they were paid from
     * or the debt only has a balance (informal).
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

        $this->ledger->removeDebtPayment($debtPayment);

        return back();
    }
}
