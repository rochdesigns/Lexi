<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Services\Money\Projection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public const MONTHS_AHEAD = 11;

    public function __construct(private Projection $projection) {}

    /**
     * The cash flow calendar for one month. It runs from this month through
     * the next 11, and anything outside that shows this month.
     */
    public function __invoke(Request $request): Response
    {
        $today = Carbon::today();
        $min = $today->format('Y-m');
        $max = $today->copy()->startOfMonth()->addMonths(self::MONTHS_AHEAD)->format('Y-m');
        $requested = (string) $request->query('month', $min);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1 && $requested >= $min && $requested <= $max ? $requested : $min;

        $user = $request->user();
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $accounts = $user->accounts()->get();

        return Inertia::render('Money/Calendar', [
            'month' => $month,
            'minMonth' => $min,
            'maxMonth' => $max,
            'today' => $today->toDateString(),
            'hasAccounts' => $accounts->isNotEmpty(),
            ...$this->projection->calendar($debts, $user->bills()->get(), $user->incomeSources()->with('receipts')->get(), $accounts, $today, $month),
        ]);
    }
}
