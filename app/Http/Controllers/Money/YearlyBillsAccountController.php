<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\YearlyBillsAccountRequest;
use Illuminate\Http\RedirectResponse;

class YearlyBillsAccountController extends Controller
{
    /**
     * Picks the account yearly and quarterly bills are saved in, or clears it with null.
     */
    public function update(YearlyBillsAccountRequest $request): RedirectResponse
    {
        $request->user()->setting()->updateOrCreate([], ['yearly_bills_account_id' => $request->validated('account_id')]);

        return back();
    }
}
