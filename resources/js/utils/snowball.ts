export type SnowballDebt = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    balance: number;
    original: number;
    per_month: number;
    interest_rate: number | null;
};

export type SnowballResult = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    startBalance: number;
    paidPct: number;
    payoffMonth: number;
    payoffDate: string;
    totalInterest: number | null;
};

export function computeSnowball(
    debts: SnowballDebt[],
    monthlyBudget: number,
): SnowballResult[] | null {
    const active = debts.filter((d) => d.balance > 0);
    if (active.length === 0) return [];

    const totalMin = active.reduce((s, d) => s + d.per_month, 0);
    if (monthlyBudget < totalMin) return null;

    type Work = {
        id: number; name: string; icon: string; color: string; bg: string;
        original: number; startBalance: number; balance: number;
        perMonth: number; rate: number | null;
        totalInterest: number; payoffMonth: number | null;
    };

    const work: Work[] = active
        .slice()
        .sort((a, b) => a.balance - b.balance)
        .map((d) => ({
            id: d.id, name: d.name, icon: d.icon, color: d.color, bg: d.bg,
            original: d.original, startBalance: d.balance, balance: d.balance,
            perMonth: d.per_month, rate: d.interest_rate,
            totalInterest: 0, payoffMonth: null,
        }));

    let month = 0;
    while (work.some((d) => d.payoffMonth === null) && month < 600) {
        month++;

        for (const d of work) {
            if (d.payoffMonth !== null) continue;
            if (d.rate) {
                const interest = d.balance * (d.rate / 100 / 12);
                d.balance += interest;
                d.totalInterest += interest;
            }
        }

        let leftover = monthlyBudget;
        for (const d of work) {
            if (d.payoffMonth !== null) continue;
            const payment = Math.min(d.balance, d.perMonth);
            d.balance = Math.round((d.balance - payment) * 100) / 100;
            leftover -= payment;
            if (d.balance <= 0) {
                d.balance = 0;
                d.payoffMonth = month;
            }
        }

        const target = work.find((d) => d.payoffMonth === null);
        if (target && leftover > 0.001) {
            target.balance = Math.max(0, Math.round((target.balance - leftover) * 100) / 100);
            if (target.balance <= 0) {
                target.balance = 0;
                target.payoffMonth = month;
            }
        }
    }

    const now = new Date();
    return work.map((d) => {
        const months = d.payoffMonth ?? 600;
        const date = new Date(now.getFullYear(), now.getMonth() + months, 1);
        const paidPct = d.original > 0
            ? Math.min(100, Math.round(((d.original - d.startBalance) / d.original) * 100))
            : 0;
        return {
            id: d.id,
            name: d.name,
            icon: d.icon,
            color: d.color,
            bg: d.bg,
            startBalance: d.startBalance,
            paidPct,
            payoffMonth: months,
            payoffDate: date.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' }),
            totalInterest: d.rate ? Math.round(d.totalInterest * 100) / 100 : null,
        };
    });
}
