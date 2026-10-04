<?php

namespace App\Services\Money;

use App\Models\Money\Debt;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionKind;
use App\Models\Money\TransactionSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brings a card statement in without doubling up. Each charge on the statement
 * is one of three things: one already imported, one that matches an expense the
 * user logged by hand (same card, same amount, within three days), or new.
 * A match is kept as a linked row that is not counted, so the expense is
 * there once and importing the same statement again adds nothing.
 */
final class StatementImport
{
    public const MATCH_DAYS = 3;

    public function __construct(private StatementParser $parser) {}

    /**
     * @return array{rows: list<array{line: int, date: string, description: string, amount: int, status: string, matched: array{id: int, date: string, note: string|null, category: string}|null}>, skipped: list<array{line: int, text: string, reason: string}>, counts: array{new: int, matches: int, imported: int}}
     */
    public function preview(User $user, Debt $card, string $csv): array
    {
        $parsed = $this->parser->parse($csv);
        $rows = $this->classify($card, $parsed['rows']);
        $count = fn (string $status): int => count(array_filter($rows, fn (array $row): bool => $row['status'] === $status));

        return [
            'rows' => $rows,
            'skipped' => $parsed['skipped'],
            'counts' => ['new' => $count('new'), 'matches' => $count('matches'), 'imported' => $count('imported')],
        ];
    }

    /**
     * @return array{new: int, matches: int, imported: int}
     */
    public function import(User $user, Debt $card, string $csv): array
    {
        return DB::transaction(function () use ($user, $card, $csv): array {
            $preview = $this->preview($user, $card, $csv);

            foreach ($preview['rows'] as $row) {
                if ($row['status'] === 'imported') {
                    continue;
                }

                $user->transactions()->create([
                    'kind' => TransactionKind::Expense,
                    'date' => $row['date'],
                    'amount' => $row['amount'],
                    'category' => $row['matched']['category'] ?? TransactionCategory::Other->value,
                    'note' => $row['description'] !== '' ? $row['description'] : null,
                    'source' => TransactionSource::StatementImport,
                    'debt_id' => $card->id,
                    'account_id' => null,
                    'matched_transaction_id' => $row['matched']['id'] ?? null,
                ]);
            }

            return $preview['counts'];
        });
    }

    /**
     * @param  list<array{line: int, date: string, description: string, amount: int}>  $rows
     * @return list<array{line: int, date: string, description: string, amount: int, status: string, matched: array{id: int, date: string, note: string|null, category: string}|null}>
     */
    private function classify(Debt $card, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates = array_column($rows, 'date');
        $from = CarbonImmutable::parse(min($dates))->subDays(self::MATCH_DAYS)->toDateString();
        $to = CarbonImmutable::parse(max($dates))->addDays(self::MATCH_DAYS + 1)->toDateString();

        /** @var Collection<int, Transaction> $existing */
        $existing = Transaction::query()
            ->where('debt_id', $card->id)
            ->where('date', '>=', $from)
            ->where('date', '<', $to)
            ->with('statementRow')
            ->orderBy('id')
            ->get();

        $imported = [];

        foreach ($existing->where('source', TransactionSource::StatementImport) as $transaction) {
            $key = $this->key($transaction->date->toDateString(), $transaction->amount, (string) $transaction->note);
            $imported[$key] = ($imported[$key] ?? 0) + 1;
        }

        $manual = $existing
            ->where('source', TransactionSource::Manual)
            ->filter(fn (Transaction $transaction): bool => $transaction->statementRow === null)
            ->values();
        $taken = [];
        $classified = [];

        foreach ($rows as $row) {
            $key = $this->key($row['date'], $row['amount'], $row['description']);

            if (($imported[$key] ?? 0) > 0) {
                $imported[$key]--;
                $classified[] = [...$row, 'status' => 'imported', 'matched' => null];

                continue;
            }

            $match = $manual
                ->filter(fn (Transaction $transaction): bool => ! isset($taken[$transaction->id])
                    && $transaction->amount === $row['amount']
                    && abs(CarbonImmutable::parse($row['date'])->diffInDays($transaction->date, false)) <= self::MATCH_DAYS)
                ->sortBy(fn (Transaction $transaction): int => (int) abs(CarbonImmutable::parse($row['date'])->diffInDays($transaction->date, false)))
                ->first();

            if ($match === null) {
                $classified[] = [...$row, 'status' => 'new', 'matched' => null];

                continue;
            }

            $taken[$match->id] = true;
            $classified[] = [
                ...$row,
                'status' => 'matches',
                'matched' => ['id' => $match->id, 'date' => $match->date->toDateString(), 'note' => $match->note, 'category' => $match->category->value],
            ];
        }

        return $classified;
    }

    private function key(string $date, int $amount, string $description): string
    {
        return $date.'|'.$amount.'|'.$description;
    }
}
