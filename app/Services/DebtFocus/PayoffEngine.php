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
     *     perDebt: array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>,
     *     trajectory: array<int, float>|null
     * }
     */
    public function simulate(Collection $debts, string $strategy, float $extraMonthly = 0.0, bool $withTrajectory = false): array
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
            ];
        }

        $work = $this->buildWorkingSet($active);

        $sortedByStartingBalance = $active->all();
        usort($sortedByStartingBalance, fn (Debt $a, Debt $b) => [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]);
        $snowballOrder = array_map(fn (Debt $debt) => $debt->id, $sortedByStartingBalance);

        $month = 0;
        $rolledMinimums = 0.0;
        $trajectory = $withTrajectory ? [array_sum(array_column($work, 'balance'))] : [];

        while (collect($work)->contains(fn ($d) => $d['payoffMonth'] === null) && $month < self::MAX_MONTHS) {
            $month++;

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

                $debt['balance'] -= min($debt['balance'], $debt['minimumPayment']);

                if ($debt['balance'] <= 0.0) {
                    $debt['balance'] = 0.0;
                    $debt['payoffMonth'] = $month;
                    $rolledMinimums += $debt['minimumPayment'];
                }

                $work[$id] = $debt;
            }

            $extraThisMonth = $extraMonthly + $rolledMinimums;

            if ($extraThisMonth > 0) {
                $target = $this->priorityTarget($work, $strategy, $snowballOrder);

                if ($target !== null) {
                    $targetDebt = $work[$target];
                    $targetDebt['balance'] = max(0.0, $targetDebt['balance'] - $extraThisMonth);

                    if ($targetDebt['balance'] <= 0.0) {
                        $targetDebt['payoffMonth'] = $month;
                    }

                    $work[$target] = $targetDebt;
                }
            }

            if ($withTrajectory) {
                $trajectory[] = array_sum(array_column($work, 'balance'));
            }
        }

        $allPaidOff = collect($work)->every(fn ($d) => $d['payoffMonth'] !== null);

        return [
            'strategy' => $strategy,
            'months' => $allPaidOff ? $month : null,
            'onTrack' => $allPaidOff,
            'trajectory' => $withTrajectory ? $trajectory : null,
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
