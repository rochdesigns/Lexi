export function formatMoney(value: number): string {
    const abs = Math.abs(value);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return value < 0 ? `-${formatted}` : formatted;
}

export function formatSigned(value: number): string {
    return (value >= 0 ? '+' : '') + formatMoney(value);
}

export function formatPct(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}
