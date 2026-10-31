<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\SpendingLimitRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class SpendingLimitController extends Controller
{
    /**
     * Sets, changes or clears a category's limit from this month on. Earlier
     * months keep the limit they had.
     */
    public function update(SpendingLimitRequest $request): RedirectResponse
    {
        $attributes = $request->limitAttributes();

        $request->user()->spendingLimits()->updateOrCreate(
            [
                'category' => $attributes['category'],
                'custom_category_id' => $attributes['custom_category_id'],
                'starts_on' => Carbon::today()->startOfMonth(),
            ],
            ['amount' => $attributes['amount']],
        );

        return back();
    }
}
