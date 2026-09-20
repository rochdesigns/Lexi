<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Debt;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayoffEngine
{
    private const MAX_MONTHS = 600;

    /**
     * Share of the principal a suggested minimum clears on top of the interest.
     * Mirrored by suggestedMinimum() in resources/js/pages/PayoffPlan.vue, which
     * fills the field live while a card is being added.
     */
    private const MINIMUM_PRINCIPAL_SHARE = 0.01;

    /**
     * `trajectory` is the total balance at each month boundary, so entry 0 is
     * where the plan starts. `payments` is the cash handed over *during* each
     * simulated month, so entry 0 is the first month — one shorter than the
     * trajectory, and the indexing the Forecast page's month rail expects.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  int|null  $priorityDebtId  Pins the extra payment to one debt, overriding the strategy order.
     * @return array{
     *     strategy: string,
     *     months: int|null,
     *     onTrack: bool,
     *     perDebt: array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>,
     *     trajectory: array<int, float>|null,
     *     payments: array<int, float>|null
     * }
     */
    public function simulate(Collection $debts, string $strategy, float $extraMonthly = 0.0, bool $withTrajectory = false, ?int $priorityDebtId = null): array
    {
        if (! in_array($strategy, ['avalanche', 'snowball'], true)) {
            throw new InvalidArgumentException("Unsupported strategy: {$strategy}");
        }

        if ($extraMonthly < 0) {
            throw new InvalidArgumentException('extraMonthly must not be negative.');
        }

        $active = $debts->filter(fn (Debt $debt) => (float) $debt->balance > 0)->values();

        if ($active->isEmpty()) {
            return [
                'strategy' => $strategy,
                'months' => 0,
                'onTrack' => true,
                'perDebt' => [],
                'trajectory' => $withTrajectory ? [0.0] : null,
                'payments' => $withTrajectory ? [] : null,
            ];
        }

        $work = $this->buildWorkingSet($active);

        $sortedByStartingBalance = $active->all();
        usort($sortedByStartingBalance, fn (Debt $a, Debt $b) => [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]);
        $snowballOrder = array_map(fn (Debt $debt) => $debt->id, $sortedByStartingBalance);

        $month = 0;
        $rolledMinimums = 0.0;
        $trajectory = $withTrajectory ? [array_sum(array_column($work, 'balance'))] : [];
        $payments = [];

        while (collect($work)->contains(fn ($d) => $d['payoffMonth'] === null) && $month < self::MAX_MONTHS) {
            $month++;
            $paidThisMonth = 0.0;
            $freedThisMonth = 0.0;
            $newlyRolled = 0.0;

            foreach ($work as $id => $debt) {
                if ($debt['payoffMonth'] !== null) {
                    continue;
                }

                $interest = $debt['balance'] * $debt['monthlyRate'];
                if ($debt['totalInterest'] !== null) {
                    $debt['totalInterest'] += $interest;
                }
                $debt['balance'] += $interest;

                $work[$id] = $debt;
            }

            foreach ($work as $id => $debt) {
                if ($debt['payoffMonth'] !== null) {
                    continue;
                }

                $due = min($debt['balance'], $debt['minimumPayment']);
                $paidThisMonth += $due;
                $debt['balance'] -= $due;

                if ($debt['balance'] <= 0.0) {
                    $debt['balance'] = 0.0;
                    $debt['payoffMonth'] = $month;
                    // Only the part of the minimum this debt did not need is
                    // spare *this* month. The whole minimum frees up from the
                    // next one — rolling all of it now would spend money the
                    // month's committed outlay never had.
                    $freedThisMonth += $debt['minimumPayment'] - $due;
                    $newlyRolled += $debt['minimumPayment'];
                }

                $work[$id] = $debt;
            }

            $extraThisMonth = $extraMonthly + $rolledMinimums + $freedThisMonth;

            if ($extraThisMonth > 0) {
                $target = $this->priorityTarget($work, $strategy, $snowballOrder, $priorityDebtId);

                if ($target !== null) {
                    $targetDebt = $work[$target];
                    $paidThisMonth += min($targetDebt['balance'], $extraThisMonth);
                    $targetDebt['balance'] = max(0.0, $targetDebt['balance'] - $extraThisMonth);

                    if ($targetDebt['balance'] <= 0.0) {
                        $targetDebt['payoffMonth'] = $month;
                    }

                    $work[$target] = $targetDebt;
                }
            }

            $rolledMinimums += $newlyRolled;

            if ($withTrajectory) {
                $trajectory[] = array_sum(array_column($work, 'balance'));
                $payments[] = round($paidThisMonth, 2);
            }
        }

        $allPaidOff = collect($work)->every(fn ($d) => $d['payoffMonth'] !== null);

        return [
            'strategy' => $strategy,
            'months' => $allPaidOff ? $month : null,
            'onTrack' => $allPaidOff,
            'trajectory' => $withTrajectory ? $trajectory : null,
            'payments' => $withTrajectory ? $payments : null,
            'perDebt' => $active->map(fn (Debt $debt) => [
                'debtId' => $debt->id,
                'payoffMonth' => $work[$debt->id]['payoffMonth'],
                'totalInterest' => $work[$debt->id]['payoffMonth'] !== null && $work[$debt->id]['totalInterest'] !== null
                    ? round($work[$debt->id]['totalInterest'], 2)
                    : null,
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Debt>  $debts
     * @param  int|null  $priorityDebtId  Pinned debt, moved to the front of the queue.
     * @return Collection<int, Debt>
     */
    public function orderByPriority(Collection $debts, string $strategy, ?int $priorityDebtId = null): Collection
    {
        $ordered = $debts->values()->all();

        usort($ordered, fn (Debt $a, Debt $b) => $strategy === 'snowball'
            ? [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]
            : [(float) ($b->monthly_rate ?? -1), $a->id] <=> [(float) ($a->monthly_rate ?? -1), $b->id]);

        if ($priorityDebtId !== null) {
            usort($ordered, fn (Debt $a, Debt $b) => ($b->id === $priorityDebtId ? 1 : 0) <=> ($a->id === $priorityDebtId ? 1 : 0));
        }

        return collect($ordered);
    }

    /**
     * Whether a debt's minimum payment fails to cover the interest it earns in a
     * month, so its balance grows even when the minimum is paid on time.
     */
    public function isGoingBackwards(Debt $debt): bool
    {
        if ($debt->monthly_rate === null || (float) $debt->balance <= 0.0) {
            return false;
        }

        return (float) $debt->balance * ((float) $debt->monthly_rate / 100) > (float) $debt->minimum_payment;
    }

    /**
     * The smallest whole-peso monthly top-up that makes a stalled debt fall again,
     * or null when it is already falling.
     */
    public function minimumTopUpToClear(Debt $debt): ?float
    {
        if (! $this->isGoingBackwards($debt)) {
            return null;
        }

        $interest = (float) $debt->balance * ((float) $debt->monthly_rate / 100);

        return max(1.0, ceil($interest - (float) $debt->minimum_payment) + 1.0);
    }

    /**
     * A workable minimum for a revolving debt: the month's interest, plus a
     * slice of the principal so the balance actually falls. A card whose
     * minimum only covers interest never clears, which is the trap a zero or
     * guessed minimum quietly sets.
     *
     * Null when there is no rate to work from — an interest-free card's minimum
     * is whatever the issuer says, and there is nothing here to infer it from.
     */
    public function suggestedMinimumPayment(float $balance, ?float $monthlyRate): ?float
    {
        if ($monthlyRate === null || $balance <= 0.0) {
            return null;
        }

        $interest = $balance * ($monthlyRate / 100);

        return round($interest + $balance * self::MINIMUM_PRINCIPAL_SHARE, 2);
    }

    /**
     * Splits a payment into the interest it clears and the principal it actually
     * knocks off, using the month's accrued interest on the current balance.
     *
     * A payment smaller than the month's interest leaves a negative principal —
     * the balance grows despite paying — which is worth showing plainly rather
     * than flooring to zero and pretending the payment stood still.
     *
     * @return array{interest: float, principal: float, balanceAfter: float, coversInterest: bool}
     */
    public function splitPayment(Debt $debt, float $amount): array
    {
        $balance = (float) $debt->balance;
        $interest = $debt->monthly_rate !== null
            ? round($balance * ((float) $debt->monthly_rate / 100), 2)
            : 0.0;

        $principal = round($amount - $interest, 2);

        return [
            'interest' => $interest,
            'principal' => $principal,
            'balanceAfter' => round(max(0.0, $balance + $interest - $amount), 2),
            'coversInterest' => $principal >= 0.0,
        ];
    }

    /**
     * Projects the plan at a range of extra-payment levels so a slider can read
     * the effect of each step without a round trip per drag.
     *
     * @param  Collection<int, Debt>  $debts
     * @return array<int, array{extra: float, months: int|null, totalInterest: float}>
     */
    public function extraCurve(Collection $debts, string $strategy, float $maxExtra, int $steps = 20, ?int $priorityDebtId = null): array
    {
        $steps = max(1, $steps);
        $curve = [];

        for ($i = 0; $i <= $steps; $i++) {
            $extra = round($maxExtra * $i / $steps, 2);
            $result = $this->simulate($debts, $strategy, $extra, priorityDebtId: $priorityDebtId);

            $curve[] = [
                'extra' => $extra,
                'months' => $result['months'],
                'totalInterest' => round((float) collect($result['perDebt'])->sum('totalInterest'), 2),
            ];
        }

        return $curve;
    }

    /**
     * @param  Collection<int, Debt>  $active
     * @return array<int, array{balance: float, minimumPayment: float, monthlyRate: float, totalInterest: float|null, payoffMonth: int|null}>
     */
    private function buildWorkingSet(Collection $active): array
    {
        $work = [];

        foreach ($active as $debt) {
            $work[$debt->id] = [
                'balance' => (float) $debt->balance,
                'minimumPayment' => (float) $debt->minimum_payment,
                'monthlyRate' => $debt->monthly_rate !== null ? ((float) $debt->monthly_rate / 100) : 0.0,
                'totalInterest' => $debt->monthly_rate !== null ? 0.0 : null,
                'payoffMonth' => null,
            ];
        }

        return $work;
    }

    /**
     * @param  array<int, array{balance: float, minimumPayment: float, monthlyRate: float, totalInterest: float|null, payoffMonth: int|null}>  $work
     * @param  array<int, int>  $snowballOrder
     * @param  int|null  $priorityDebtId  Pinned debt, targeted ahead of the strategy order.
     */
    private function priorityTarget(array $work, string $strategy, array $snowballOrder, ?int $priorityDebtId = null): ?int
    {
        if ($priorityDebtId !== null && isset($work[$priorityDebtId]) && $work[$priorityDebtId]['payoffMonth'] === null) {
            return $priorityDebtId;
        }

        if ($strategy === 'snowball') {
            foreach ($snowballOrder as $id) {
                if ($work[$id]['payoffMonth'] === null) {
                    return $id;
                }
            }

            return null;
        }

        $activeIds = array_keys(array_filter($work, fn ($d) => $d['payoffMonth'] === null));

        if ($activeIds === []) {
            return null;
        }

        usort($activeIds, fn ($a, $b) => $work[$b]['monthlyRate'] <=> $work[$a]['monthlyRate'] ?: $a <=> $b);

        return $activeIds[0];
    }
}
