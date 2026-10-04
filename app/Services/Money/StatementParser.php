<?php

namespace App\Services\Money;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Reads a card statement pasted or uploaded as CSV, tab or semicolon separated
 * text. Each charge becomes a date, a description and an amount in centavos.
 * Payments, refunds and anything unreadable are skipped with a reason, never
 * guessed at. Slash dates are month first unless the first number can only be a
 * day.
 */
final class StatementParser
{
    private const DATE_FORMATS = [
        'Y-m-d', 'Y/m/d', 'm/d/Y', 'n/j/Y', 'd/m/Y', 'j/n/Y', 'm/d/y', 'n/j/y', 'd/m/y', 'j/n/y',
        'M j, Y', 'F j, Y', 'M j Y', 'j M Y', 'j F Y', 'd-M-Y', 'j-M-Y', 'd-M-y', 'j-M-y',
    ];

    /**
     * @return array{rows: list<array{line: int, date: string, description: string, amount: int}>, skipped: list<array{line: int, text: string, reason: string}>}
     */
    public function parse(string $text): array
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return ['rows' => [], 'skipped' => []];
        }

        $delimiter = $this->delimiter($lines[0]);
        $columns = ['date' => 0, 'description' => 1, 'amount' => 2, 'debit' => null, 'credit' => null];
        $first = $this->cells($lines[0], $delimiter);
        $hasHeader = ! $this->hasDate($first);
        $offset = 0;

        if ($hasHeader) {
            $columns = $this->mapHeader($first) ?? $columns;
            $offset = 1;
        }

        $rows = [];
        $skipped = [];

        foreach (array_slice($lines, $offset) as $index => $line) {
            $number = $index + $offset + 1;
            $cells = $this->cells($line, $delimiter);
            $date = $this->parseDate($cells[$columns['date']] ?? '');

            if ($date === null) {
                $skipped[] = ['line' => $number, 'text' => $line, 'reason' => 'Could not read the date'];

                continue;
            }

            $amount = $this->chargeAmount($cells, $columns);

            if (is_string($amount)) {
                $skipped[] = ['line' => $number, 'text' => $line, 'reason' => $amount];

                continue;
            }

            $rows[] = [
                'line' => $number,
                'date' => $date,
                'description' => mb_substr(trim($cells[$columns['description']] ?? ''), 0, 200),
                'amount' => $amount,
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function delimiter(string $line): string
    {
        if (str_contains($line, "\t")) {
            return "\t";
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /**
     * @return list<string>
     */
    private function cells(string $line, string $delimiter): array
    {
        return array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));
    }

    /**
     * @param  list<string>  $cells
     */
    private function hasDate(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->parseDate($cell) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $header
     * @return array{date: int, description: int, amount: int|null, debit: int|null, credit: int|null}|null
     */
    private function mapHeader(array $header): ?array
    {
        $columns = ['date' => null, 'description' => null, 'amount' => null, 'debit' => null, 'credit' => null];

        foreach ($header as $index => $name) {
            $name = strtolower($name);

            $key = match (true) {
                str_contains($name, 'date') => 'date',
                str_contains($name, 'debit') || str_contains($name, 'withdraw') => 'debit',
                str_contains($name, 'credit') || str_contains($name, 'deposit') => 'credit',
                str_contains($name, 'amount') || str_contains($name, 'charge') => 'amount',
                preg_match('/desc|detail|merchant|particular|transaction|narrat|name|payee/', $name) === 1 => 'description',
                default => null,
            };

            if ($key !== null && $columns[$key] === null) {
                $columns[$key] = $index;
            }
        }

        if ($columns['date'] === null || ($columns['amount'] === null && $columns['debit'] === null)) {
            return null;
        }

        return [
            'date' => $columns['date'],
            'description' => $columns['description'] ?? ($columns['date'] === 0 ? 1 : 0),
            'amount' => $columns['amount'],
            'debit' => $columns['debit'],
            'credit' => $columns['credit'],
        ];
    }

    /**
     * The charge in centavos, or the reason the row is not a charge.
     *
     * @param  list<string>  $cells
     * @param  array{date: int, description: int, amount: int|null, debit: int|null, credit: int|null}  $columns
     */
    private function chargeAmount(array $cells, array $columns): int|string
    {
        $debit = $columns['debit'] !== null ? trim($cells[$columns['debit']] ?? '') : '';
        $credit = $columns['credit'] !== null ? trim($cells[$columns['credit']] ?? '') : '';

        if ($columns['amount'] === null && $debit === '' && $credit !== '') {
            return 'A payment or refund, not a charge';
        }

        $raw = $columns['amount'] !== null ? ($cells[$columns['amount']] ?? '') : $debit;
        $negative = preg_match('/^\(.*\)$|^-|CR$/i', trim($raw)) === 1;
        $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $raw)) ?? '';

        if ($clean === '' || ! is_numeric($clean)) {
            return 'Could not read the amount';
        }

        if ($negative) {
            return 'A payment or refund, not a charge';
        }

        $amount = Centavos::fromPesos($clean);

        return $amount > 0 ? $amount : 'The amount is zero';
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]/', $value, $match) === 1) {
            $value = $match[1];
        }

        if ($value === '' || preg_match('/\d/', $value) !== 1) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date instanceof CarbonImmutable && strtolower($date->format($format)) === strtolower($value) && $date->year >= 2000 && $date->year <= 2100) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
