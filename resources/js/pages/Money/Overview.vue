<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AccountDialog from '@/components/AccountDialog.vue';
import type { AccountRow } from '@/components/AccountDialog.vue';
import BillDialog from '@/components/BillDialog.vue';
import type { BillRow } from '@/components/BillDialog.vue';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import type { IncomeSourceRow } from '@/components/IncomeSourceDialog.vue';
import IncomeSourceDialog from '@/components/IncomeSourceDialog.vue';
import InfoNote from '@/components/InfoNote.vue';
import { sheetWidth } from '@/utils/blueprint';
import { formatDay, formatPeso, monthNames } from '@/utils/money';
import { update as updateRecovery } from '@/actions/App/Http/Controllers/Money/RecoveryController';
import { transactions } from '@/routes/money';

type Upcoming = {
    kind: 'debt' | 'bill' | 'income';
    name: string;
    amount: number;
    date: string;
    autopay: boolean;
    sourceId: number | null;
    billId: number | null;
    received: boolean;
};

type Shortfall = {
    kind: 'debt' | 'bill';
    name: string;
    amount: number;
    date: string;
    short: number;
};

type CarryAlert = {
    debtId: number;
    name: string;
    monthsCarried: number;
    balance: number;
};

type Recovery = {
    carryover: number;
    months: number | null;
    extra: number;
    options: { months: number; extra: number }[];
};

const props = defineProps<{
    numbers: {
        income: number;
        expenses: number;
        setAside: number;
        debtPayment: number;
        bufferContribution: number;
        recoveryContribution: number;
        safeToSpend: number;
        perDay: number;
    };
    carryAlerts: CarryAlert[];
    recovery: Recovery | null;
    cashAvailable: number;
    shortfalls: Shortfall[];
    accounts: AccountRow[];
    cards: { id: number; name: string }[];
    upcoming: Upcoming[];
    incomeSources: IncomeSourceRow[];
    bills: BillRow[];
}>();

function setRecovery(months: number | null): void {
    router.patch(updateRecovery().url, { months }, { preserveScroll: true });
}

const editingAccount = ref<AccountRow | null>(null);
const accountOpen = ref(false);
const editingIncome = ref<IncomeSourceRow | null>(null);
const incomeOpen = ref(false);
const editingBill = ref<BillRow | null>(null);
const billOpen = ref(false);

function openAccount(account: AccountRow | null): void {
    editingAccount.value = account;
    accountOpen.value = true;
}

const accountTypeLabel: Record<string, string> = {
    cash: 'Cash',
    bank: 'Bank',
    ewallet: 'E-wallet',
};

function openIncome(source: IncomeSourceRow | null): void {
    editingIncome.value = source;
    incomeOpen.value = true;
}

function openBill(bill: BillRow | null): void {
    editingBill.value = bill;
    billOpen.value = true;
}

/** The lines of the month's statement, ending in safe to spend. Recovery only shows while it is on. */
const statement = computed(() => {
    const lines = [
        { label: 'Income', value: props.numbers.income, sign: '+' },
        { label: 'Bills', value: props.numbers.expenses, sign: '−' },
        {
            label: 'Set aside for yearly bills and goals',
            value: props.numbers.setAside,
            sign: '−',
        },
        {
            label: 'Debt payments',
            value: props.numbers.debtPayment,
            sign: '−',
        },
        {
            label: 'Buffer',
            value: props.numbers.bufferContribution,
            sign: '−',
        },
    ];

    if (props.numbers.recoveryContribution > 0) {
        lines.push({
            label: 'Recovery payment',
            value: props.numbers.recoveryContribution,
            sign: '−',
        });
    }

    return lines;
});

const today = new Date();
const dayOfMonth = today.getDate();
const daysInMonth = new Date(
    today.getFullYear(),
    today.getMonth() + 1,
    0,
).getDate();
/** Counts today, the same as the per-day figure on the server. */
const daysLeft = daysInMonth - dayOfMonth + 1;
const monthLabel = today.toLocaleDateString('en-US', {
    month: 'long',
    year: 'numeric',
});

/** One cell per day of the month: spent days solid, today raised, the rest open. */
const monthDays = Array.from({ length: daysInMonth }, (_, index) => ({
    day: index + 1,
    state:
        index + 1 < dayOfMonth
            ? 'past'
            : index + 1 === dayOfMonth
              ? 'today'
              : 'future',
}));

function isoDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Today and the 14 days after it, marked where a payday or a due date falls. */
const timeline = computed(() =>
    Array.from({ length: 15 }, (_, index) => {
        const date = new Date(
            today.getFullYear(),
            today.getMonth(),
            today.getDate() + index,
        );
        const iso = isoDate(date);
        const items = props.upcoming.filter((item) => item.date === iso);

        return {
            iso,
            day: String(date.getDate()).padStart(2, '0'),
            weekday: date
                .toLocaleDateString('en-US', { weekday: 'narrow' })
                .toUpperCase(),
            pay: items.some((item) => item.kind === 'income'),
            due: items.some((item) => item.kind !== 'income'),
            today: index === 0,
        };
    }),
);

function shortDate(date: Date): string {
    return `${String(date.getDate()).padStart(2, '0')} ${date.toLocaleDateString('en-US', { month: 'short' }).toUpperCase()}`;
}

const timelineRange = `${shortDate(today)} — ${shortDate(
    new Date(today.getFullYear(), today.getMonth(), today.getDate() + 14),
)}`;

function weekday(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-US', {
        weekday: 'short',
    });
}

const kindIcon = {
    debt: 'credit_card',
    bill: 'receipt_long',
    income: 'payments',
} as const;

const isShort = computed(() => props.numbers.safeToSpend < 0);

/** How many warnings sit at the top of the page; the header bell points to them. */
const alertCount = computed(
    () =>
        (isShort.value ? 1 : 0) +
        (props.shortfalls.length > 0 ? 1 : 0) +
        props.carryAlerts.length,
);

function showAlerts(): void {
    document
        .getElementById('overview-alerts')
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

const kindLabel = { debt: 'Debt', bill: 'Bill', income: 'Payday' } as const;

function scheduleText(source: IncomeSourceRow): string {
    return source.schedule === 'semi_monthly'
        ? `Days ${source.payDay1} and ${source.payDay2}`
        : `Day ${source.payDay1}`;
}

function billText(bill: BillRow): string {
    if (bill.frequency === 'quarterly') {
        return `Every 3 months from ${monthNames[(bill.dueMonth ?? 1) - 1]}, day ${bill.dueDay}`;
    }

    return bill.frequency === 'annual'
        ? `Yearly, ${monthNames[(bill.dueMonth ?? 1) - 1]} ${bill.dueDay}`
        : `Monthly, day ${bill.dueDay}`;
}

const alertClass =
    'mb-4 flex items-start gap-3.5 border border-[var(--bp-ink)] bg-[var(--bp-card)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.055)_0_1px,transparent_1px_8px)] px-[18px] py-3.5 text-[14px] leading-normal';
const listRowClass =
    'flex w-full items-center justify-between gap-3 border-b border-[var(--bp-hair)] py-[9px] text-left hover:bg-[var(--bp-hover)]';
const addRowClass =
    'flex items-center gap-1.5 py-[9px] text-[13.5px] text-[var(--bp-faint)] hover:text-[var(--bp-ink)]';
</script>

<template>
    <Head title="Money" />

    <Teleport defer to="#page-actions">
        <div class="bp flex items-center gap-1.5">
            <button
                type="button"
                class="relative flex h-8 w-8 items-center justify-center rounded hover:bg-[var(--bp-hover)] disabled:hover:bg-transparent"
                :disabled="alertCount === 0"
                :aria-label="
                    alertCount === 0
                        ? 'No warnings'
                        : `${alertCount} ${alertCount === 1 ? 'warning' : 'warnings'}, show them`
                "
                :title="alertCount === 0 ? 'No warnings' : undefined"
                @click="showAlerts"
            >
                <span class="bp-duo text-[19px]" aria-hidden="true">
                    <span class="text-[var(--bp-line)]">notifications</span>
                    <span class="text-[var(--bp-ink-2)]">notifications</span>
                </span>
                <span
                    v-if="alertCount > 0"
                    class="absolute top-1.5 right-[7px] h-1.5 w-1.5 rounded-full bg-[var(--bp-accent)]"
                    aria-hidden="true"
                ></span>
            </button>
            <Link
                :href="transactions().url"
                class="flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >add</span
                >
                <span class="hidden sm:inline">New entry</span>
            </Link>
        </div>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            tall
            icon="account_balance_wallet"
            code="M-01"
            section="Money"
            title="Overview"
            subtitle="What is left to spend this month, after everything that is already spoken for"
            :dimension="`${monthLabel} · ${daysInMonth} days`"
        >
            <dl
                class="mt-[22px] grid grid-cols-[150px_minmax(0,1fr)] gap-y-0.5 text-[13.5px] sm:grid-cols-[170px_minmax(0,1fr)]"
            >
                <dt
                    class="flex items-center gap-2 py-1.5 text-[var(--bp-muted)]"
                >
                    <span class="ms" style="font-size: 16px" aria-hidden="true"
                        >calendar_today</span
                    >Period
                </dt>
                <dd class="px-2 py-1.5">
                    {{ monthLabel }}
                    <span class="text-[var(--bp-faint)]"
                        >· {{ daysLeft }}
                        {{ daysLeft === 1 ? 'day' : 'days' }} left</span
                    >
                </dd>
                <dt
                    class="flex items-center gap-2 py-1.5 text-[var(--bp-muted)]"
                >
                    <span class="ms" style="font-size: 16px" aria-hidden="true"
                        >account_balance</span
                    >On hand right now
                </dt>
                <dd class="px-2 py-1.5">
                    <span class="bp-mono font-medium">{{
                        formatPeso(cashAvailable)
                    }}</span>
                    <span class="text-[var(--bp-faint)]">
                        · balances of the accounts you can spend, as you last
                        updated them</span
                    >
                </dd>
                <template v-if="recovery">
                    <dt
                        class="flex items-center gap-2 py-1.5 text-[var(--bp-muted)]"
                    >
                        <span
                            class="ms"
                            style="font-size: 16px"
                            aria-hidden="true"
                            >bolt</span
                        >Recovery mode
                    </dt>
                    <dd class="px-2 py-1.5">
                        <span
                            v-if="recovery.months !== null"
                            class="bp-mono rounded-sm border border-[var(--bp-accent)] px-[7px] py-0.5 text-[11px] tracking-[0.08em] text-[var(--bp-accent)]"
                            >ON · {{ recovery.months }} MONTHS</span
                        >
                        <span
                            v-else
                            class="bp-mono rounded-sm border border-[var(--bp-line)] px-[7px] py-0.5 text-[11px] tracking-[0.08em] text-[var(--bp-muted)]"
                            >OFF</span
                        >
                    </dd>
                </template>
            </dl>
        </BlueprintPageHead>

        <div :class="sheetWidth.wide">
            <!-- Alerts -->
            <div id="overview-alerts" class="scroll-mt-20">
                <div v-if="isShort" :class="alertClass" role="alert">
                    <span
                        class="bp-duo shrink-0 text-[22px]"
                        aria-hidden="true"
                    >
                        <span class="text-[var(--bp-line)]">warning</span>
                        <span>warning</span>
                    </span>
                    <div>
                        <p class="font-semibold">
                            This month's plan is
                            {{ formatPeso(-numbers.safeToSpend) }} over your
                            income
                        </p>
                        <p class="text-pretty text-[var(--bp-ink-2)]">
                            Your bills, savings and debt payments come to more
                            than you expect to earn this month.
                        </p>
                    </div>
                </div>

                <div
                    v-if="shortfalls.length > 0"
                    :class="alertClass"
                    role="alert"
                >
                    <span
                        class="bp-duo shrink-0 text-[22px]"
                        aria-hidden="true"
                    >
                        <span class="text-[var(--bp-line)]">warning</span>
                        <span>warning</span>
                    </span>
                    <div>
                        <p class="font-semibold">
                            You may run short in the next 30 days
                        </p>
                        <ul class="text-[var(--bp-ink-2)]">
                            <li
                                v-for="item in shortfalls"
                                :key="`${item.name}-${item.date}`"
                            >
                                <span class="bp-mono">{{
                                    formatPeso(item.short)
                                }}</span>
                                short for {{ item.name }} due
                                {{ formatDay(item.date) }}
                            </li>
                        </ul>
                        <p class="mt-1 text-[12.5px] text-[var(--bp-muted)]">
                            This uses the balances of accounts you can spend and
                            the paydays you have entered.
                        </p>
                    </div>
                </div>

                <div
                    v-for="alert in carryAlerts"
                    :key="alert.debtId"
                    :class="alertClass"
                    role="alert"
                >
                    <span
                        class="bp-duo shrink-0 text-[22px]"
                        aria-hidden="true"
                    >
                        <span class="text-[var(--bp-line)]">warning</span>
                        <span>warning</span>
                    </span>
                    <div>
                        <p class="font-semibold">
                            {{ alert.name }} has carried a balance for
                            {{ alert.monthsCarried }} of the last 3 months
                        </p>
                        <p class="text-pretty text-[var(--bp-ink-2)]">
                            {{ formatPeso(alert.balance) }} is still charging
                            interest. Consider calling the bank to ask about a
                            balance conversion or restructuring it into fixed
                            installments.
                        </p>
                    </div>
                </div>
            </div>
            <div
                v-if="isShort || shortfalls.length > 0 || carryAlerts.length"
                class="h-4"
            ></div>

            <!-- A. Safe to spend -->
            <BlueprintSectionHeading
                letter="A"
                title="Safe to spend this month"
            >
                <span
                    class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                    aria-hidden="true"
                    >FIG. 1</span
                >
            </BlueprintSectionHeading>
            <div
                class="mt-4 grid grid-cols-1 border border-[var(--bp-line)] bg-[var(--bp-card)] md:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]"
            >
                <div class="px-6 py-5">
                    <div
                        v-for="line in statement"
                        :key="line.label"
                        class="flex justify-between gap-4 border-b border-[var(--bp-hair)] py-[7px] text-[13.5px]"
                    >
                        <span class="text-[var(--bp-ink-2)]">{{
                            line.label
                        }}</span>
                        <span class="bp-mono text-[13px] whitespace-nowrap"
                            >{{ line.sign }} {{ formatPeso(line.value) }}</span
                        >
                    </div>
                    <div
                        class="mt-[3px] flex flex-wrap items-baseline justify-between gap-x-4 border-t-[3px] border-double border-[var(--bp-ink)] pt-3"
                    >
                        <span class="text-[14px] font-semibold"
                            >Safe to spend</span
                        >
                        <span
                            class="text-[34px] font-semibold tracking-[-0.03em] tabular-nums"
                            >{{ formatPeso(numbers.safeToSpend) }}</span
                        >
                    </div>
                </div>
                <div
                    class="flex flex-col border-t border-[var(--bp-line)] bg-[var(--bp-paper)] bg-[linear-gradient(var(--bp-grid2)_1px,transparent_1px),linear-gradient(90deg,var(--bp-grid2)_1px,transparent_1px)] bg-[length:16px_16px] px-6 py-5 md:border-t-0 md:border-l"
                >
                    <span class="bp-caption text-[10.5px]"
                        >Per day · rest of month</span
                    >
                    <span
                        class="mt-2 text-[40px] font-semibold tracking-[-0.03em] text-[var(--bp-accent)] tabular-nums"
                        >{{ formatPeso(numbers.perDay) }}</span
                    >
                    <span class="text-[13px] text-[var(--bp-muted)]">
                        <template v-if="isShort"
                            >Nothing to spend day to day until the plan
                            fits</template
                        >
                        <template v-else
                            >About this much a day for the {{ daysLeft }}
                            {{ daysLeft === 1 ? 'day' : 'days' }} left</template
                        >
                    </span>
                    <div
                        class="mt-auto grid items-end gap-0.5 pt-[22px]"
                        :style="{
                            gridTemplateColumns: `repeat(${daysInMonth}, minmax(0, 1fr))`,
                        }"
                        aria-hidden="true"
                    >
                        <div
                            v-for="cell in monthDays"
                            :key="cell.day"
                            class="box-border"
                            :class="{
                                'h-3.5 bg-[var(--bp-ink)]':
                                    cell.state === 'past',
                                'h-[22px] bg-[var(--bp-accent)]':
                                    cell.state === 'today',
                                'h-3.5 border border-[var(--bp-accent)] bg-[var(--bp-tint)]':
                                    cell.state === 'future',
                            }"
                        ></div>
                    </div>
                    <div
                        class="bp-mono mt-1.5 flex justify-between text-[10px] text-[var(--bp-faint)]"
                        aria-hidden="true"
                    >
                        <span>01</span>
                        <span
                            >TODAY
                            {{ String(dayOfMonth).padStart(2, '0') }}</span
                        >
                        <span>{{ daysInMonth }}</span>
                    </div>
                </div>
            </div>
            <InfoNote id="overview-safe" blueprint class="mt-3.5">
                Your forecast income, less bills, the yearly set-aside, debt
                payments, goals and any recovery payment. It looks at what is
                planned for the month, not at your account balances. On hand,
                above, shows those.
            </InfoNote>

            <!-- B. Recovery mode -->
            <template v-if="recovery">
                <BlueprintSectionHeading
                    letter="B"
                    title="Recovery mode"
                    class="mt-11"
                >
                    <button
                        v-if="recovery.months !== null"
                        type="button"
                        class="rounded border border-[var(--bp-line)] bg-[var(--bp-card)] px-2.5 py-[5px] text-[13px] font-medium hover:border-[var(--bp-ink)]"
                        @click="setRecovery(null)"
                    >
                        Turn off
                    </button>
                </BlueprintSectionHeading>
                <p
                    class="mt-2.5 mb-3.5 max-w-[680px] text-[14.5px] leading-[1.55] text-pretty text-[var(--bp-ink-2)]"
                >
                    <template v-if="recovery.months !== null">
                        Setting aside
                        <span class="bp-mono">{{
                            formatPeso(recovery.extra)
                        }}</span>
                        a month on top of your minimums, so the
                        <span class="bp-mono">{{
                            formatPeso(recovery.carryover)
                        }}</span>
                        carried over on your cards clears in
                        {{ recovery.months }} months. It is already taken off
                        safe to spend.
                    </template>
                    <template v-else>
                        <span class="bp-mono">{{
                            formatPeso(recovery.carryover)
                        }}</span>
                        is carried over on your cards and charging interest.
                        Pick how fast to clear it and the extra comes off safe
                        to spend.
                    </template>
                </p>
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                    <button
                        v-for="option in recovery.options"
                        :key="option.months"
                        type="button"
                        class="flex flex-col gap-1 rounded border px-3.5 py-3 text-left"
                        :class="
                            recovery.months === option.months
                                ? 'border-[var(--bp-accent)] bg-[var(--bp-tint)]'
                                : 'border-[var(--bp-line)] bg-[var(--bp-card)] hover:border-[var(--bp-ink)]'
                        "
                        :aria-pressed="recovery.months === option.months"
                        @click="setRecovery(option.months)"
                    >
                        <span class="text-[14px] font-semibold"
                            >{{ option.months }} months</span
                        >
                        <span class="bp-mono text-[12px] text-[var(--bp-muted)]"
                            >{{ formatPeso(option.extra) }} more a month</span
                        >
                    </button>
                </div>
            </template>

            <!-- C. Coming up -->
            <BlueprintSectionHeading
                :letter="recovery ? 'C' : 'B'"
                title="Coming up in the next 14 days"
                class="mt-11"
            >
                <span
                    class="bp-mono hidden text-[11px] tracking-[0.1em] text-[var(--bp-faint)] sm:inline"
                    >{{ timelineRange }}</span
                >
            </BlueprintSectionHeading>
            <div
                class="mt-[18px] grid grid-cols-[repeat(15,minmax(0,1fr))]"
                aria-hidden="true"
            >
                <div
                    v-for="day in timeline"
                    :key="day.iso"
                    class="flex flex-col items-center"
                >
                    <div class="flex h-[18px] items-end gap-1 pb-[5px]">
                        <span
                            v-if="day.pay"
                            class="h-2 w-2 rotate-45 bg-[var(--bp-accent)]"
                        ></span>
                        <span
                            v-if="day.due"
                            class="h-[7px] w-[7px] rotate-45 border-[1.5px] border-[var(--bp-ink)] bg-[var(--bp-card)]"
                        ></span>
                    </div>
                    <div class="h-px w-full bg-[var(--bp-ink)]"></div>
                    <div
                        v-if="day.today"
                        class="h-3.5 w-[1.5px] bg-[var(--bp-accent)]"
                    ></div>
                    <div
                        v-else
                        class="mb-1.5 h-2 w-px bg-[var(--bp-ink)]"
                    ></div>
                    <span class="bp-mono mt-1 text-[10.5px] font-medium">{{
                        day.day
                    }}</span>
                    <span class="bp-mono text-[9.5px] text-[var(--bp-faint)]">{{
                        day.weekday
                    }}</span>
                </div>
            </div>
            <InfoNote id="overview-upcoming" blueprint class="mt-4">
                Paydays, bills and debt dues from your income, bills and debts.
                A pay day you mark as received stops counting in the forecast,
                since the money is already in your balance.
            </InfoNote>
            <p
                v-if="upcoming.length === 0"
                class="mt-3 text-[14px] text-[var(--bp-muted)]"
            >
                Nothing due or paid in the next two weeks.
            </p>
            <div v-else class="mt-2.5 flex flex-col">
                <div
                    v-for="(item, index) in upcoming"
                    :key="`${item.kind}-${item.name}-${item.date}-${index}`"
                    class="grid min-h-14 grid-cols-[64px_32px_minmax(0,1fr)_auto] items-center gap-x-3 sm:grid-cols-[96px_40px_minmax(0,1fr)_auto_120px]"
                >
                    <span class="bp-mono text-[12px] text-[var(--bp-ink-2)]">
                        {{ formatDay(item.date) }}
                        <span class="hidden text-[var(--bp-faint)] sm:inline">{{
                            weekday(item.date)
                        }}</span>
                    </span>
                    <div
                        class="relative flex items-center justify-center self-stretch"
                        aria-hidden="true"
                    >
                        <div
                            class="absolute inset-y-0 left-1/2 w-px bg-[var(--bp-line)]"
                        ></div>
                        <div
                            class="relative flex h-8 w-8 items-center justify-center rounded-full border border-[var(--bp-line)] bg-[var(--bp-card)]"
                        >
                            <span class="bp-duo text-[18px]">
                                <span class="text-[var(--bp-soft)]">{{
                                    kindIcon[item.kind]
                                }}</span>
                                <span>{{ kindIcon[item.kind] }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="min-w-0 py-2">
                        <p class="truncate text-[14px] font-semibold">
                            {{ item.name }}
                        </p>
                        <p class="text-[12px] text-[var(--bp-muted)]">
                            {{ kindLabel[item.kind]
                            }}<template v-if="item.autopay">
                                · automatic</template
                            >
                            <span
                                class="bp-mono font-semibold sm:hidden"
                                :class="
                                    item.kind === 'income'
                                        ? 'text-[var(--bp-accent)]'
                                        : 'text-[var(--bp-ink)]'
                                "
                            >
                                · {{ item.kind === 'income' ? '+' : ''
                                }}{{ formatPeso(item.amount) }}</span
                            >
                        </p>
                    </div>
                    <div class="flex justify-end">
                        <span
                            v-if="item.received"
                            class="bp-mono rounded-sm border border-[var(--bp-accent)] px-[7px] py-0.5 text-[10.5px] tracking-[0.08em] text-[var(--bp-accent)] uppercase"
                            >{{
                                item.kind === 'income' ? 'Received' : 'Paid'
                            }}</span
                        >
                        <Link
                            v-else-if="item.kind === 'income' && item.sourceId"
                            :href="
                                transactions({
                                    query: {
                                        income: item.sourceId,
                                        date: item.date,
                                    },
                                }).url
                            "
                            class="text-[12px] font-semibold underline underline-offset-[3px]"
                            >Received?</Link
                        >
                        <Link
                            v-else-if="item.kind === 'bill' && item.billId"
                            :href="
                                transactions({
                                    query: {
                                        bill: item.billId,
                                        date: item.date,
                                    },
                                }).url
                            "
                            class="text-[12px] font-semibold underline underline-offset-[3px]"
                            >Mark paid</Link
                        >
                    </div>
                    <span
                        class="bp-mono hidden text-right text-[13.5px] font-semibold sm:inline"
                        :class="{
                            'text-[var(--bp-accent)]': item.kind === 'income',
                        }"
                    >
                        {{ item.kind === 'income' ? '+' : ''
                        }}{{ formatPeso(item.amount) }}
                    </span>
                </div>
            </div>

            <!-- D–F. Accounts, income and bills -->
            <div
                class="mt-11 grid grid-cols-1 items-start gap-8 md:grid-cols-3"
            >
                <section>
                    <BlueprintSectionHeading
                        :letter="recovery ? 'D' : 'C'"
                        title="Accounts"
                    />
                    <p
                        class="mt-2 text-[12.5px] leading-normal text-[var(--bp-muted)]"
                    >
                        <span
                            class="msf align-[-2px] text-[var(--bp-accent)]"
                            style="font-size: 14px"
                            aria-hidden="true"
                            >check_box</span
                        >
                        counts toward on hand. Only what you log on Transactions
                        or edit here changes a balance.
                    </p>
                    <div class="mt-3 border-t border-[var(--bp-line)]">
                        <p
                            v-if="accounts.length === 0"
                            class="border-b border-[var(--bp-hair)] py-[9px] text-[13.5px] text-[var(--bp-muted)]"
                        >
                            Add where your money sits to see what is on hand.
                        </p>
                        <button
                            v-for="account in accounts"
                            :key="account.id"
                            type="button"
                            :class="listRowClass"
                            @click="openAccount(account)"
                        >
                            <span
                                class="shrink-0"
                                :class="
                                    account.isSpendable
                                        ? 'msf text-[var(--bp-accent)]'
                                        : 'ms text-[var(--bp-faint)]'
                                "
                                style="font-size: 18px"
                                :aria-label="
                                    account.isSpendable
                                        ? 'Counts toward on hand'
                                        : 'Kept aside, not counted'
                                "
                                >{{
                                    account.isSpendable
                                        ? 'check_box'
                                        : 'check_box_outline_blank'
                                }}</span
                            >
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[14px] font-medium"
                                    >{{ account.name }}</span
                                >
                                <span
                                    class="block text-[12px] text-[var(--bp-muted)]"
                                >
                                    {{ accountTypeLabel[account.type]
                                    }}<template v-if="!account.isSpendable">
                                        · kept aside</template
                                    >
                                </span>
                            </span>
                            <span
                                class="bp-mono shrink-0 text-[13px]"
                                :class="{
                                    'text-[var(--bp-muted)]':
                                        !account.isSpendable,
                                }"
                                >{{ formatPeso(account.balance) }}</span
                            >
                        </button>
                        <button
                            type="button"
                            :class="addRowClass"
                            @click="openAccount(null)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >add</span
                            >
                            Add account
                        </button>
                    </div>
                </section>

                <section>
                    <BlueprintSectionHeading
                        :letter="recovery ? 'E' : 'D'"
                        title="Income"
                    />
                    <p
                        class="mt-2 text-[12.5px] leading-normal text-[var(--bp-muted)]"
                    >
                        <span class="font-semibold text-[var(--bp-ink)]"
                            >Forecast only.</span
                        >
                        These amounts do not change any account balance. When
                        pay arrives, mark it received under Coming up.
                    </p>
                    <div class="mt-3 border-t border-[var(--bp-line)]">
                        <p
                            v-if="incomeSources.length === 0"
                            class="border-b border-[var(--bp-hair)] py-[9px] text-[13.5px] text-[var(--bp-muted)]"
                        >
                            Add your salary or other income to see what is safe
                            to spend.
                        </p>
                        <button
                            v-for="source in incomeSources"
                            :key="source.id"
                            type="button"
                            :class="listRowClass"
                            @click="openIncome(source)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-medium"
                                    >{{ source.name }}</span
                                >
                                <span
                                    class="block text-[12px] text-[var(--bp-muted)]"
                                    >{{ scheduleText(source) }}</span
                                >
                            </span>
                            <span class="bp-mono shrink-0 text-[13px]">{{
                                formatPeso(source.amount)
                            }}</span>
                        </button>
                        <button
                            type="button"
                            :class="addRowClass"
                            @click="openIncome(null)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >add</span
                            >
                            Add income
                        </button>
                    </div>
                </section>

                <section>
                    <BlueprintSectionHeading
                        :letter="recovery ? 'F' : 'E'"
                        title="Bills"
                    />
                    <p
                        class="mt-2 text-[12.5px] leading-normal text-[var(--bp-muted)]"
                    >
                        Yearly bills are set aside at a twelfth each month. Log
                        a payment on Transactions to mark one paid.
                    </p>
                    <div class="mt-3 border-t border-[var(--bp-line)]">
                        <p
                            v-if="bills.length === 0"
                            class="border-b border-[var(--bp-hair)] py-[9px] text-[13.5px] text-[var(--bp-muted)]"
                        >
                            Add rent, utilities and subscriptions so they come
                            off what is safe to spend.
                        </p>
                        <button
                            v-for="bill in bills"
                            :key="bill.id"
                            type="button"
                            :class="listRowClass"
                            @click="openBill(bill)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-medium"
                                    >{{ bill.name }}</span
                                >
                                <span
                                    class="block text-[12px] text-[var(--bp-muted)]"
                                    >{{ billText(bill) }}</span
                                >
                            </span>
                            <span class="bp-mono shrink-0 text-[13px]">{{
                                formatPeso(bill.amount)
                            }}</span>
                        </button>
                        <button
                            type="button"
                            :class="addRowClass"
                            @click="openBill(null)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >add</span
                            >
                            Add bill
                        </button>
                    </div>
                </section>
            </div>

            <BlueprintTitleBlock drawing="Money / Overview" sheet="M-01" />
        </div>

        <AccountDialog v-model:open="accountOpen" :account="editingAccount" />
        <IncomeSourceDialog v-model:open="incomeOpen" :source="editingIncome" />
        <BillDialog
            v-model:open="billOpen"
            :bill="editingBill"
            :accounts="accounts"
            :cards="cards"
        />
    </div>
</template>
