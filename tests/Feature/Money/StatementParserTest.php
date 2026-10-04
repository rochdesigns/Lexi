<?php

use App\Services\Money\StatementParser;

function parseStatement(string $text): array
{
    return (new StatementParser)->parse($text);
}

test('a csv with a header reads each charge', function (): void {
    $result = parseStatement("Date,Description,Amount\n2026-10-12,Jollibee,250.50\n10/13/2026,Grab,\"1,200.00\"");

    expect($result['rows'])->toBe([
        ['line' => 2, 'date' => '2026-10-12', 'description' => 'Jollibee', 'amount' => 25_050],
        ['line' => 3, 'date' => '2026-10-13', 'description' => 'Grab', 'amount' => 120_000],
    ])->and($result['skipped'])->toBe([]);
});

test('without a header the columns are date, description and amount', function (): void {
    expect(parseStatement('2026-10-12,Lunch,100')['rows'])->toBe([
        ['line' => 1, 'date' => '2026-10-12', 'description' => 'Lunch', 'amount' => 10_000],
    ]);
});

test('columns can come in any order when there is a header', function (): void {
    $rows = parseStatement("Amount,Merchant,Posting Date\n75.25,Shell,2026-10-05")['rows'];

    expect($rows)->toBe([['line' => 2, 'date' => '2026-10-05', 'description' => 'Shell', 'amount' => 7_525]]);
});

test('tab separated text pasted from a spreadsheet works with pesos and month names', function (): void {
    $rows = parseStatement("Oct 12, 2026\tSM Supermarket\t₱1,234.56\n12 Oct 2026\tCafe\t PHP 80.00")['rows'];

    expect(array_column($rows, 'amount'))->toBe([123_456, 8_000])
        ->and(array_column($rows, 'date'))->toBe(['2026-10-12', '2026-10-12']);
});

test('semicolon separated text works', function (): void {
    expect(parseStatement('2026-10-12;Shop;99.50')['rows'][0])->toMatchArray(['description' => 'Shop', 'amount' => 9_950]);
});

test('debit and credit columns keep the debits and skip the credits', function (): void {
    $result = parseStatement("Date,Details,Debit,Credit\n2026-10-01,Store,500.00,\n2026-10-02,Payment thank you,,3000.00");

    expect(array_column($result['rows'], 'amount'))->toBe([50_000])
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0])->toMatchArray(['line' => 3, 'reason' => 'A payment or refund, not a charge']);
});

test('negative amounts, brackets and CR are payments or refunds and are skipped', function (): void {
    $result = parseStatement("2026-10-01,A,-100\n2026-10-02,B,(50.00)\n2026-10-03,C,75.00 CR\n2026-10-04,D,10");

    expect(array_column($result['rows'], 'description'))->toBe(['D'])
        ->and(array_column($result['skipped'], 'line'))->toBe([1, 2, 3]);
});

test('rows that cannot be read are skipped with the reason and their line', function (): void {
    $result = parseStatement("Date,Description,Amount\n\n2026-02-30,Impossible date,10\nnot a date,Words,10\n2026-10-04,No amount,abc\n2026-10-05,Zero,0\n2026-10-06,Fine,5");

    expect(array_column($result['rows'], 'line'))->toBe([6])
        ->and(array_column($result['skipped'], 'reason'))->toBe(['Could not read the date', 'Could not read the date', 'Could not read the amount', 'The amount is zero'])
        ->and(array_column($result['skipped'], 'line'))->toBe([2, 3, 4, 5]);
});

test('slash dates are month first unless the first number must be a day', function (): void {
    $dates = array_column(parseStatement("03/04/2026,A,1\n25/10/2026,B,1\n10/25/2026,C,1")['rows'], 'date');

    expect($dates)->toBe(['2026-03-04', '2026-10-25', '2026-10-25']);
});

test('empty text has nothing in it', function (): void {
    expect(parseStatement("  \n \n"))->toBe(['rows' => [], 'skipped' => []]);
});

test('long descriptions are cut to fit', function (): void {
    expect(mb_strlen(parseStatement('2026-10-12,'.str_repeat('x', 400).',10')['rows'][0]['description']))->toBe(200);
});
