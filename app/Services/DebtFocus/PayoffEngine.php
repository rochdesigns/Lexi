<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Debt;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayoffEngine
{
    private const MAX_MONTHS = 600;

    /**
     * @param  Collection<int, Debt>  $debts
     * @return array{
     *     strategy: string,
     *     months: int|null,
     *     onTrack: bool,
     *     perDebt: array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>
     * }
     */
    public function simulate(Collection $debts, string $strategy, float $extraMonthly = 0.0): array
    {
        if (! in_array($strategy, ['avalanche', 'snowball'], true)) {
            throw new InvalidArgumentException("Unsupported strategy: {$strategy}");
        }

        $active = $debts->filter(fn (Debt $debt) => (float) $debt->balance > 0)->values();

        if ($active->isEmpty()) {
            return ['strategy' => $strategy, 'months' => 0, 'onTrack' => true, 'perDebt' => []];
        }

        $work = $active->keyBy('id')->map(fn (Debt $debt) => [
            'balance' => (float) $debt->balance,
            'minimumPayment' => (float) $debt->minimum_payment,
            'monthlyRate' => $debt->apr !== null ? ((float) $debt->apr / 100 / 12) : 0.0,
            'totalInterest' => $debt->apr !== null ? 0.0 : null,
            'payoffMonth' => null,
        ])->all();

        $sortedByStartingBalance = $active->all();
        usort($sortedByStartingBalance, fn (Debt $a, Debt $b) => [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]);
        $snowballOrder = array_map(fn (Debt $debt) => $debt->id, $sortedByStartingBalance);

        $month = 0;

        while (collect($work)->contains(fn ($d) => $d['payoffMonth'] === null) && $month < self::MAX_MONTHS) {
            $month++;

            foreach ($work as &$debt) {
                if ($debt['payoffMonth'] !== null) {
                    continue;
                }

                $interest = $debt['balance'] * $debt['monthlyRate'];
                if ($debt['totalInterest'] !== null) {
                    $debt['totalInterest'] += $interest;
                }
                $debt['balance'] += $interest;
            }
            unset($debt);

            foreach ($work as &$debt) {
                if ($debt['payoffMonth'] !== null) {
                    continue;
                }

                $debt['balance'] -= min($debt['balance'], $debt['minimumPayment']);

                if ($debt['balance'] <= 0.0) {
                    $debt['balance'] = 0.0;
                    $debt['payoffMonth'] = $month;
                }
            }
            unset($debt);

            if ($extraMonthly > 0) {
                $target = $this->priorityTarget($work, $strategy, $snowballOrder);

                if ($target !== null) {
                    $work[$target]['balance'] = max(0.0, $work[$target]['balance'] - $extraMonthly);

                    if ($work[$target]['balance'] <= 0.0) {
                        $work[$target]['payoffMonth'] = $month;
                    }
                }
            }
        }

        $allPaidOff = collect($work)->every(fn ($d) => $d['payoffMonth'] !== null);

        return [
            'strategy' => $strategy,
            'months' => $allPaidOff ? $month : null,
            'onTrack' => $allPaidOff,
            'perDebt' => $active->map(fn (Debt $debt) => [
                'debtId' => $debt->id,
                'payoffMonth' => $work[$debt->id]['payoffMonth'],
                'totalInterest' => $work[$debt->id]['totalInterest'] !== null
                    ? round($work[$debt->id]['totalInterest'], 2)
                    : null,
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array{balance: float, monthlyRate: float, payoffMonth: int|null}>  $work
     * @param  array<int, int>  $snowballOrder
     */
    private function priorityTarget(array $work, string $strategy, array $snowballOrder): ?int
    {
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
