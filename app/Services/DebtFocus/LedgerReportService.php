<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-side reporting for the Ledger page: net-worth history, a month's
 * money flow for one account, and the activity feed behind it.
 *
 * Nothing here writes; every figure is derived from the accounts and
 * transactions already on record.
 */
class LedgerReportService
{
    /**
     * Net worth at the end of each of the last $months months.
     *
     * Net worth only moves on income (up) and expense (down) — a transfer
     * between two of the user's own accounts is neutral, whichever way the
     * liability sign falls. So the series is each account's opening balance
     * from the month it was added, plus every transaction since.
     *
     * @return array<int, array{label: string, month: string, value: float}>
     */
    public function netWorthHistory(User $user, int $months = 9): array
    {
        $accounts = $user->debtFocusAccounts()->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $currentMonth = Carbon::now()->format('Y-m');
        $deltaPerAccount = [];
        $deltaPerMonth = [];

        foreach ($this->transactionsFor($user) as $transaction) {
            $month = min($transaction->occurred_at->format('Y-m'), $currentMonth);

            foreach ($this->netWorthLegs($transaction) as [$accountId, $delta]) {
                $deltaPerAccount[$accountId] = ($deltaPerAccount[$accountId] ?? 0.0) + $delta;
                $deltaPerMonth[$month] = ($deltaPerMonth[$month] ?? 0.0) + $delta;
            }
        }

        $openingPerMonth = [];
        $opened = $currentMonth;

        foreach ($accounts as $account) {
            $signed = (float) $account->balance * ($account->is_liability ? -1 : 1);
            $opening = $signed - ($deltaPerAccount[$account->id] ?? 0.0);
            $month = min($account->created_at->format('Y-m'), $currentMonth);
            $opened = min($opened, $month);

            $openingPerMonth[$month] = ($openingPerMonth[$month] ?? 0.0) + $opening;
        }

        $cumulative = $this->accumulate($openingPerMonth, $deltaPerMonth);
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $series = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $month = $start->copy()->addMonthsNoOverflow($offset);
            $key = $month->format('Y-m');

            // Months before the first account existed are not a net worth of
            // zero, they are no history at all — plotting them reads as a cliff.
            if ($key < $opened) {
                continue;
            }

            $value = 0.0;

            foreach ($cumulative as $cumulativeMonth => $cumulativeValue) {
                if ($cumulativeMonth > $key) {
                    break;
                }

                $value = $cumulativeValue;
            }

            $series[] = ['label' => $month->format('M Y'), 'month' => $key, 'value' => round($value, 2)];
        }

        return $series;
    }

    /**
     * A month of money in and out for one account, with what left it split
     * into bills, debt, money moved to savings, and everyday living.
     *
     * @return array{
     *     month_label: string,
     *     in: float,
     *     out: float,
     *     stayed: float,
     *     in_sources: array<int, array{label: string, amount: float}>,
     *     out_segments: array<int, array{key: string, label: string, amount: float}>,
     *     debt_share_of_income: float|null
     * }
     */
    public function monthlyFlow(Account $account, CarbonInterface $month): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $account->user_id)
            ->involvingAccount($account->id)
            ->whereBetween('occurred_at', $this->monthBounds($month))
            ->with(['envelope:id,name', 'fromAccount:id,name,type,is_liability', 'toAccount:id,name,type,is_liability'])
            ->get();

        $in = 0.0;
        $out = 0.0;
        $sources = [];
        $segments = ['bills' => 0.0, 'debt' => 0.0, 'saved' => 0.0, 'living' => 0.0];

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->amount;

            if ($this->directionFor($transaction, $account->id) === 'in') {
                $in += $amount;
                $label = $this->sourceLabel($transaction, $account->id);
                $sources[$label] = ($sources[$label] ?? 0.0) + $amount;

                continue;
            }

            $out += $amount;
            $segments[$this->outSegmentFor($transaction, $account->id)] += $amount;
        }

        return $this->shapeFlow($month, $in, $out, $sources, $segments);
    }

    /**
     * The same month of flow, but for everything the user owns at once.
     *
     * A total is not the per-account figures added up: moving money between
     * two of your own everyday accounts is not income on one side and spending
     * on the other, it is the same peso standing still. So internal moves drop
     * out, and what is left is money that genuinely arrived (income, or cash
     * borrowed off a card) against money that genuinely went somewhere — bills,
     * debt, savings set aside, and everyday living.
     *
     * @return array{
     *     month_label: string,
     *     in: float,
     *     out: float,
     *     stayed: float,
     *     in_sources: array<int, array{label: string, amount: float}>,
     *     out_segments: array<int, array{key: string, label: string, amount: float}>,
     *     debt_share_of_income: float|null
     * }
     */
    public function monthlyFlowTotal(User $user, CarbonInterface $month): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', $this->monthBounds($month))
            ->with(['fromAccount:id,name,type,is_liability', 'toAccount:id,name,type,is_liability'])
            ->get();

        $in = 0.0;
        $out = 0.0;
        $sources = [];
        $segments = ['bills' => 0.0, 'debt' => 0.0, 'saved' => 0.0, 'living' => 0.0];

        foreach ($transactions as $transaction) {
            [$direction, $bucket] = $this->totalLegFor($transaction);

            if ($direction === null) {
                continue;
            }

            $amount = (float) $transaction->amount;

            if ($direction === 'in') {
                $in += $amount;
                $sources[$bucket] = ($sources[$bucket] ?? 0.0) + $amount;

                continue;
            }

            $out += $amount;
            $segments[$bucket] += $amount;
        }

        return $this->shapeFlow($month, $in, $out, $sources, $segments);
    }

    /**
     * The activity feed for one account over a date range, newest first.
     *
     * @return array<int, array{
     *     id: int,
     *     title: string,
     *     kind: string,
     *     kind_label: string,
     *     icon: string,
     *     type: string,
     *     description: string|null,
     *     account_id: int|null,
     *     from_account_id: int|null,
     *     to_account_id: int|null,
     *     debt_id: int|null,
     *     envelope_id: int|null,
     *     envelope_name: string|null,
     *     occurred_at: string,
     *     occurred_on: string,
     *     amount: float,
     *     direction: string
     * }>
     */
    public function activityFor(Account $account, CarbonInterface $from, CarbonInterface $to, int $limit = 500): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $account->user_id)
            ->involvingAccount($account->id)
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->with(['envelope:id,name,icon', 'debt:id,name', 'fromAccount:id,name,type,is_liability', 'toAccount:id,name,type,is_liability'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $transactions->map(function (Transaction $transaction) use ($account) {
            $kind = $this->kindFor($transaction, $account->id);

            return [
                'id' => $transaction->id,
                'title' => $this->titleFor($transaction, $account->id),
                'kind' => $kind,
                'kind_label' => ucfirst($kind),
                'icon' => $this->iconFor($transaction, $kind),
                // The raw fields behind the row, so it can be opened and edited
                // without another round trip.
                'type' => $transaction->type,
                'description' => $transaction->description,
                'account_id' => $transaction->account_id,
                'from_account_id' => $transaction->from_account_id,
                'to_account_id' => $transaction->to_account_id,
                'debt_id' => $transaction->debt_id,
                'envelope_id' => $transaction->envelope_id,
                'envelope_name' => $transaction->envelope?->name,
                'occurred_at' => $transaction->occurred_at->format('j M'),
                'occurred_on' => $transaction->occurred_at->toDateString(),
                'amount' => (float) $transaction->amount,
                'direction' => $this->directionFor($transaction, $account->id),
            ];
        })->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function monthBounds(CarbonInterface $month): array
    {
        return [
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function transactionsFor(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->get(['id', 'type', 'account_id', 'from_account_id', 'to_account_id', 'amount', 'occurred_at']);
    }

    /**
     * Each account this transaction moves net worth for, and by how much.
     * A transfer between two live accounts nets to zero; a transfer whose
     * other leg points at a deleted account does not.
     *
     * @return array<int, array{0: int, 1: float}>
     */
    private function netWorthLegs(Transaction $transaction): array
    {
        $amount = (float) $transaction->amount;

        return match ($transaction->type) {
            'income' => $transaction->account_id !== null ? [[$transaction->account_id, $amount]] : [],
            'expense' => $transaction->account_id !== null ? [[$transaction->account_id, -$amount]] : [],
            default => array_values(array_filter([
                $transaction->from_account_id !== null ? [$transaction->from_account_id, -$amount] : null,
                $transaction->to_account_id !== null ? [$transaction->to_account_id, $amount] : null,
            ])),
        };
    }

    /**
     * @param  array<string, float>  $openingPerMonth
     * @param  array<string, float>  $deltaPerMonth
     * @return array<string, float>
     */
    private function accumulate(array $openingPerMonth, array $deltaPerMonth): array
    {
        $months = array_unique(array_merge(array_keys($openingPerMonth), array_keys($deltaPerMonth)));
        sort($months);

        $cumulative = [];
        $running = 0.0;

        foreach ($months as $month) {
            $running += ($openingPerMonth[$month] ?? 0.0) + ($deltaPerMonth[$month] ?? 0.0);
            $cumulative[$month] = $running;
        }

        return $cumulative;
    }

    /**
     * Turns a month's running totals into the shape the flow card reads,
     * so the per-account and whole-portfolio views cannot drift apart.
     *
     * @param  array<string, float>  $sources
     * @param  array<string, float>  $segments
     * @return array{
     *     month_label: string,
     *     in: float,
     *     out: float,
     *     stayed: float,
     *     in_sources: array<int, array{label: string, amount: float}>,
     *     out_segments: array<int, array{key: string, label: string, amount: float}>,
     *     debt_share_of_income: float|null
     * }
     */
    private function shapeFlow(CarbonInterface $month, float $in, float $out, array $sources, array $segments): array
    {
        arsort($sources);

        $labels = ['bills' => 'Bills', 'debt' => 'Debt', 'saved' => 'Saved', 'living' => 'Living'];

        return [
            'month_label' => $month->format('F'),
            'in' => round($in, 2),
            'out' => round($out, 2),
            'stayed' => round($in - $out, 2),
            'in_sources' => collect($sources)
                ->map(fn (float $amount, string $label) => ['label' => $label, 'amount' => round($amount, 2)])
                ->values()
                ->all(),
            'out_segments' => collect($segments)
                ->filter(fn (float $amount) => $amount > 0.0)
                ->map(fn (float $amount, string $key) => ['key' => $key, 'label' => $labels[$key], 'amount' => round($amount, 2)])
                ->values()
                ->all(),
            'debt_share_of_income' => $in > 0.0 ? round($segments['debt'] / $in, 4) : null,
        ];
    }

    /**
     * Where one transaction lands in the whole-portfolio view: the direction it
     * moves money overall, and either the source label it is credited to or the
     * out segment it is spent under. A null direction means the transaction
     * never left the user's own money — it is an internal move, and counting it
     * on either side would invent income or spending that never happened.
     *
     * @return array{0: string|null, 1: string}
     */
    private function totalLegFor(Transaction $transaction): array
    {
        if ($transaction->type === 'income') {
            return ['in', $transaction->description ?? 'Income'];
        }

        if ($transaction->type === 'expense') {
            return ['out', $this->plainOutSegmentFor($transaction)];
        }

        $from = $transaction->fromAccount;
        $to = $transaction->toAccount;

        // Money paid into a card or loan is money gone, even though net worth
        // holds steady — that is the whole point of a debt payment.
        if ($transaction->debt_id !== null || $to?->is_liability === true) {
            return ['out', 'debt'];
        }

        if ($to === null) {
            return ['out', $this->plainOutSegmentFor($transaction)];
        }

        if ($from === null) {
            return ['in', 'From a closed account'];
        }

        // Cash drawn off a card is borrowed, but it is still money that arrived.
        if ($from->is_liability) {
            return ['in', "From {$from->name}"];
        }

        if (in_array($to->type, ['savings', 'investment'], true)) {
            return ['out', 'saved'];
        }

        return [null, ''];
    }

    private function directionFor(Transaction $transaction, int $accountId): string
    {
        return match (true) {
            $transaction->type === 'income' => 'in',
            $transaction->type === 'expense' => 'out',
            $transaction->from_account_id === $accountId => 'out',
            default => 'in',
        };
    }

    private function counterpartFor(Transaction $transaction, int $accountId): ?Account
    {
        if ($transaction->type !== 'transfer') {
            return null;
        }

        return $transaction->from_account_id === $accountId
            ? $transaction->toAccount
            : $transaction->fromAccount;
    }

    /**
     * Money out is one of four things: a bill the budget tracks, a payment
     * against debt, money moved into savings, or everyday living.
     *
     * Bills are recognised by the "Bill: " description the budget writes when
     * a bill period is marked paid — transactions carry no bill_period_id yet.
     */
    private function outSegmentFor(Transaction $transaction, int $accountId): string
    {
        $counterpart = $this->counterpartFor($transaction, $accountId);

        if ($transaction->debt_id !== null || $counterpart?->is_liability === true) {
            return 'debt';
        }

        if ($counterpart !== null && in_array($counterpart->type, ['savings', 'investment'], true)) {
            return 'saved';
        }

        return $this->plainOutSegmentFor($transaction);
    }

    /**
     * Money out with no counterpart account to read it against: a payment on a
     * debt, a bill the budget marked paid, or everyday living.
     */
    private function plainOutSegmentFor(Transaction $transaction): string
    {
        if ($transaction->debt_id !== null) {
            return 'debt';
        }

        return str_starts_with((string) $transaction->description, 'Bill: ') ? 'bills' : 'living';
    }

    private function sourceLabel(Transaction $transaction, int $accountId): string
    {
        if ($transaction->type === 'income') {
            return $transaction->description ?? 'Income';
        }

        $counterpart = $this->counterpartFor($transaction, $accountId);

        return 'From '.($counterpart === null ? 'a closed account' : $counterpart->name);
    }

    private function kindFor(Transaction $transaction, int $accountId): string
    {
        if ($transaction->debt_id !== null || $this->counterpartFor($transaction, $accountId)?->is_liability === true) {
            return 'debt';
        }

        if ($transaction->type === 'expense' && str_starts_with((string) $transaction->description, 'Bill: ')) {
            return 'bill';
        }

        return $transaction->type;
    }

    private function titleFor(Transaction $transaction, int $accountId): string
    {
        if ($transaction->description !== null && $transaction->description !== '') {
            return $transaction->description;
        }

        if ($transaction->type === 'transfer') {
            $counterpart = $this->counterpartFor($transaction, $accountId);
            $name = $counterpart === null ? '(deleted account)' : $counterpart->name;

            return $transaction->from_account_id === $accountId
                ? "Transfer to {$name}"
                : "Transfer from {$name}";
        }

        return $transaction->type === 'income' ? 'Income' : 'Expense';
    }

    private function iconFor(Transaction $transaction, string $kind): string
    {
        return match ($kind) {
            'debt' => 'credit_card',
            'bill' => 'receipt_long',
            'income' => 'payments',
            'transfer' => 'swap_horiz',
            default => $transaction->envelope?->icon ?: 'shopping_cart',
        };
    }
}
