<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flashes a "saved" toast after a form is saved, added or deleted, so every
 * save gives feedback without each controller asking for it. A controller that
 * flashes its own toast keeps it, and a redirect that carries validation errors
 * gets none.
 */
class FlashSavedToast
{
    /** What each kind of record is called in the toast. */
    private const NOUNS = [
        'events' => 'Event',
        'money.accounts' => 'Account',
        'money.bills' => 'Bill',
        'money.categories' => 'Category',
        'money.debts' => 'Debt',
        'money.payments' => 'Payment',
        'money.plans' => 'Payment plan',
        'money.goals' => 'Goal',
        'money.income-sources' => 'Income source',
        'money.transactions' => 'Transaction',
        'task-sections' => 'Section',
        'tasks' => 'Task',
        'vision-board-items' => 'Vision board item',
    ];

    /** Routes whose whole message is fixed. */
    private const MESSAGES = [
        'money.accounts.reconcile' => 'Balance updated.',
        'money.debts.allocate' => 'Payments recorded.',
        'money.debts.clearing' => 'Saved.',
        'money.recovery.update' => 'Recovery plan saved.',
        'money.spending-limits.update' => 'Spending limits saved.',
        'money.transactions.import.store' => 'Statement imported.',
        'money.yearly-bills-account.update' => 'Yearly bills account saved.',
    ];

    /** Saves that happen on a tick or a drag, where a toast each time would be noise. */
    private const QUIET = ['tasks.update', 'task-sections.reorder'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse || ! $request->header('X-Inertia') || $request->isMethodSafe()) {
            return $response;
        }

        $name = $request->route()?->getName();

        if ($name === null || in_array($name, self::QUIET, true) || $request->session()->has('errors') || isset(Inertia::getFlashed($request)['toast'])) {
            return $response;
        }

        $message = $this->message($name);

        if ($message !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return $response;
    }

    private function message(string $name): ?string
    {
        if (isset(self::MESSAGES[$name])) {
            return self::MESSAGES[$name];
        }

        $action = substr($name, (int) strrpos($name, '.') + 1);
        $noun = self::NOUNS[substr($name, 0, (int) strrpos($name, '.'))] ?? null;

        if ($noun === null) {
            return null;
        }

        return match ($action) {
            'store' => "{$noun} added.",
            'update' => "{$noun} saved.",
            'destroy' => "{$noun} deleted.",
            default => null,
        };
    }
}
