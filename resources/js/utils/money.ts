/** Money is stored and passed around as integer centavos; these only format it. */

export function formatPeso(centavos: number): string {
    return (
        '₱' +
        (centavos / 100).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}

/** Whole pesos, for chart labels and tight spaces. */
export function formatPesoShort(centavos: number): string {
    return (
        '₱' +
        Math.round(centavos / 100).toLocaleString('en-PH', {
            maximumFractionDigits: 0,
        })
    );
}

export function toPesos(centavos: number): number {
    return centavos / 100;
}

/** "2026-11" -> "Nov 2026" */
export function formatMonth(month: string): string {
    const [year, mon] = month.split('-').map(Number);

    return new Date(year, mon - 1, 1).toLocaleDateString('en-US', {
        month: 'short',
        year: 'numeric',
    });
}

/** "2026-11-10" -> "Nov 10" */
export function formatDay(date: string): string {
    const [year, mon, day] = date.split('-').map(Number);

    return new Date(year, mon - 1, day).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

export const debtTypes = [
    { value: 'credit_card', label: 'Credit cards', single: 'Credit card' },
    { value: 'loan', label: 'Loans', single: 'Loan' },
    {
        value: 'bnpl',
        label: 'Buy now, pay later',
        single: 'Buy now, pay later',
    },
    {
        value: 'informal',
        label: 'Informal',
        single: 'Informal (family, friends)',
    },
] as const;

export const interestTypes = [
    {
        value: 'none',
        label: 'No interest',
        short: 'No interest',
        description:
            'You pay back exactly what you borrowed. Common for 0% card installments, family, and friends.',
    },
    {
        value: 'amortized',
        label: 'Reducing balance (amortized)',
        short: 'Reducing balance',
        description:
            'Interest is charged on what you still owe, so paying early saves money. Common for bank, SSS, and Pag-IBIG loans.',
    },
    {
        value: 'add_on',
        label: 'Fixed interest (add-on)',
        short: 'Fixed interest',
        description:
            "The total interest is set upfront. Paying early won't reduce it. Common for appliance and gadget financing, and some lending apps.",
    },
    {
        value: 'revolving',
        label: 'Carries over (revolving)',
        short: 'Carries over',
        description: 'Unpaid card balance is charged interest every month.',
    },
] as const;

export type InterestValue = (typeof interestTypes)[number]['value'];

export function interestLabel(value: string): string {
    return interestTypes.find((t) => t.value === value)?.short ?? value;
}
