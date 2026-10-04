<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\RecoveryRequest;
use Illuminate\Http\RedirectResponse;

class RecoveryController extends Controller
{
    /**
     * Turns recovery mode on for a number of months, or off with null.
     */
    public function update(RecoveryRequest $request): RedirectResponse
    {
        $request->user()->setting()->updateOrCreate([], ['recovery_months' => $request->validated('months')]);

        return back();
    }
}
