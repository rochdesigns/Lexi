<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { updateIncome } from '@/actions/App/Http/Controllers/ForecastController';
import MoneyInput from '@/components/MoneyInput.vue';

type MonthRow = {
    year: number;
    month: number;
    income: number;
    bills: number;
    envelopes: number;
    debt: number;
    net: number;
    balance: number | null;
};

type TightMonth = { year: number; month: number; shortfall: number };

const props = defineProps<{
    expectedMonthlyIncome: number | null;
    suggestedMonthlyIncome: number | null;
    months: MonthRow[];
    openingBalance: number | null;
    closingBalance: number | null;
    tightMonth: TightMonth | null;
}>();

// ── Formatting ───────────────────────────────────────────────────────────

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted =
        '₱' +
        abs.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

    return n < 0 ? `-${formatted}` : formatted;
}

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

function monthName(month: number): string {
    return MONTH_NAMES[month - 1] ?? '';
}

function shortMonth(month: number): string {
    return monthName(month).slice(0, 3);
}

/** "March" for this year, "March 2027" once the rail crosses into the next. */
function monthLabel(row: { year: number; month: number }): string {
    const thisYear = props.months[0]?.year;

    return row.year === thisYear
        ? monthName(row.month)
        : `${monthName(row.month)} ${row.year}`;
}

// ── Hero ─────────────────────────────────────────────────────────────────

const hasIncome = computed(() => props.expectedMonthlyIncome !== null);

const tracksBalance = computed(() => props.openingBalance !== null);

const monthsUntilTight = computed(() => {
    if (props.tightMonth === null) {
        return null;
    }

    return props.months.findIndex(
        (row) =>
            row.year === props.tightMonth?.year &&
            row.month === props.tightMonth?.month,
    );
});

const heroHeadline = computed(() => {
    if (props.tightMonth === null) {
        return 'You clear all twelve months.';
    }

    return `${monthLabel(props.tightMonth)} gets tight.`;
});

const heroDetail = computed(() => {
    if (props.tightMonth === null) {
        return tracksBalance.value
            ? `Nothing in the next year outruns what comes in. You end the run with ${formatMoney(props.closingBalance ?? 0)}.`
            : 'Every month covers itself. Add a cash account and the rail can carry a running balance too.';
    }

    const away = monthsUntilTight.value ?? 0;
    const when =
        away === 0
            ? 'This month'
            : away === 1
              ? 'Next month'
              : `${away} months out`;

    return tracksBalance.value
        ? `${when} you run ${formatMoney(props.tightMonth.shortfall)} short. Everything before it holds.`
        : `${when} the plan claims ${formatMoney(props.tightMonth.shortfall)} more than comes in.`;
});

// ── The income figure ────────────────────────────────────────────────────

const editingIncome = ref(false);

const incomeForm = useForm<{ expected_monthly_income: number | null }>({
    expected_monthly_income:
        props.expectedMonthlyIncome ?? props.suggestedMonthlyIncome ?? null,
});

function saveIncome(): void {
    if (incomeForm.expected_monthly_income === null) {
        return;
    }

    incomeForm.submit(updateIncome(), {
        preserveScroll: true,
        onSuccess: () => {
            editingIncome.value = false;
        },
    });
}

function startEditingIncome(): void {
    incomeForm.expected_monthly_income = props.expectedMonthlyIncome;
    editingIncome.value = true;
}

function useSuggestion(): void {
    incomeForm.expected_monthly_income = props.suggestedMonthlyIncome;
}

// ── The rail ─────────────────────────────────────────────────────────────

const openMonth = ref<string | null>(null);

function monthKey(row: MonthRow): string {
    return `${row.year}-${row.month}`;
}

function toggleMonth(row: MonthRow): void {
    const key = monthKey(row);
    openMonth.value = openMonth.value === key ? null : key;
}

function outflowOf(row: MonthRow): number {
    return row.bills + row.envelopes + row.debt;
}

/**
 * The widest single month's flow, so every card's bars share one scale and a
 * heavy month reads as heavier than a light one rather than every card filling.
 */
const railScale = computed(() => {
    const widest = props.months.reduce(
        (max, row) => Math.max(max, row.income, outflowOf(row)),
        0,
    );

    return widest > 0 ? widest : 1;
});

function barWidth(amount: number): string {
    return `${Math.min(100, (amount / railScale.value) * 100)}%`;
}

function isTight(row: MonthRow): boolean {
    return row.balance !== null ? row.balance < 0 : row.net < 0;
}

function breakdownOf(
    row: MonthRow,
): { label: string; amount: number; icon: string }[] {
    return [
        { label: 'Coming in', amount: row.income, icon: 'south_west' },
        { label: 'Bills', amount: -row.bills, icon: 'receipt_long' },
        { label: 'Envelopes', amount: -row.envelopes, icon: 'wallet' },
        { label: 'Debt', amount: -row.debt, icon: 'trending_down' },
    ];
}
</script>

<template>
    <Head title="Forecast" />

    <div
        class="hab-garden mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
        style="
            background-image: radial-gradient(
                var(--hab-paper-dot) 1px,
                transparent 1px
            );
            background-size: 4px 4px;
        "
    >
        <!-- Header -->
        <div class="mb-[18px]">
            <h1
                class="m-0 font-['Bricolage_Grotesque'] text-[30px] font-bold tracking-[-.02em]"
                style="color: var(--hab-ink)"
            >
                Forecast
            </h1>
            <p class="m-0 mt-1 text-[13.5px]" style="color: var(--hab-muted)">
                The next twelve months, from where you stand today
            </p>
        </div>

        <!-- Ask for the income figure: everything below depends on it -->
        <div
            v-if="!hasIncome"
            class="hab-anim-rise rounded-[18px] p-6 sm:p-8"
            style="
                background: var(--hab-card-bg);
                border: 1px solid var(--hab-card-border);
            "
        >
            <span
                class="material-symbols-rounded"
                style="font-size: 30px; color: var(--hab-leaf-mid)"
                >savings</span
            >
            <h2
                class="m-0 mt-2 font-['Bricolage_Grotesque'] text-[23px] font-bold tracking-[-.02em]"
                style="color: var(--hab-ink)"
            >
                What do you expect to earn in a month?
            </h2>
            <p
                class="m-0 mt-2 max-w-[52ch] text-[13.5px] leading-[1.6]"
                style="color: var(--hab-ink-secondary)"
            >
                One number is enough. Your bills, envelopes and debt minimums
                are already known — this is the last piece the runway needs. You
                can change it whenever it changes.
            </p>

            <div class="mt-5 flex flex-wrap items-center gap-2">
                <MoneyInput
                    v-model="incomeForm.expected_monthly_income"
                    :empty-value="null"
                    class="w-[190px] rounded-[11px] px-3 py-2 text-[14px]"
                    style="
                        background: var(--hab-surface);
                        border: 1px solid var(--hab-card-border);
                        color: var(--hab-ink);
                    "
                    placeholder="0.00"
                    @keyup.enter="saveIncome"
                />
                <button
                    type="button"
                    class="rounded-[11px] px-4 py-2 text-[13px] font-semibold disabled:opacity-50"
                    style="
                        background: var(--hab-btn-dark-bg);
                        color: var(--hab-btn-dark-ink);
                    "
                    :disabled="
                        incomeForm.processing ||
                        incomeForm.expected_monthly_income === null
                    "
                    @click="saveIncome"
                >
                    Show me the year
                </button>
            </div>

            <button
                v-if="props.suggestedMonthlyIncome !== null"
                type="button"
                class="mt-3 text-[12.5px] underline underline-offset-2"
                style="color: var(--hab-muted)"
                @click="useSuggestion"
            >
                Lately you've averaged
                {{ formatMoney(props.suggestedMonthlyIncome) }} a month — use
                that
            </button>

            <p
                v-if="incomeForm.errors.expected_monthly_income"
                class="m-0 mt-3 text-[12.5px]"
                style="color: var(--hab-clay)"
            >
                {{ incomeForm.errors.expected_monthly_income }}
            </p>
        </div>

        <template v-else>
            <!-- Hero -->
            <div
                class="hab-anim-rise rounded-[18px] p-6 sm:p-7"
                :style="{
                    background:
                        props.tightMonth !== null
                            ? 'var(--hab-apricot-tint)'
                            : 'var(--hab-card-bg)',
                    border:
                        '1px solid ' +
                        (props.tightMonth !== null
                            ? 'var(--hab-apricot-border)'
                            : 'var(--hab-card-border)'),
                }"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2
                            class="m-0 font-['Bricolage_Grotesque'] text-[27px] leading-[1.15] font-bold tracking-[-.02em] sm:text-[32px]"
                            style="color: var(--hab-ink)"
                        >
                            {{ heroHeadline }}
                        </h2>
                        <p
                            class="m-0 mt-2 max-w-[56ch] text-[13.5px] leading-[1.6]"
                            style="color: var(--hab-ink-secondary)"
                        >
                            {{ heroDetail }}
                        </p>
                    </div>

                    <div
                        v-if="tracksBalance"
                        class="shrink-0 text-right"
                        style="font-family: var(--hab-mono)"
                    >
                        <div
                            class="text-[11px] tracking-[.08em] uppercase"
                            style="color: var(--hab-muted)"
                        >
                            On hand today
                        </div>
                        <div
                            class="text-[21px] font-semibold"
                            style="color: var(--hab-ink)"
                        >
                            {{ formatMoney(props.openingBalance ?? 0) }}
                        </div>
                    </div>
                </div>

                <!-- The income figure, editable in place -->
                <div
                    class="mt-5 flex flex-wrap items-center gap-2 border-t pt-4 text-[13px]"
                    style="border-color: var(--hab-hairline)"
                >
                    <template v-if="editingIncome">
                        <span style="color: var(--hab-muted)">Expecting</span>
                        <MoneyInput
                            v-model="incomeForm.expected_monthly_income"
                            :empty-value="null"
                            class="w-[140px] rounded-[10px] px-[10px] py-[6px] text-[13px]"
                            style="
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-card-border);
                                color: var(--hab-ink);
                            "
                            @keyup.enter="saveIncome"
                        />
                        <span style="color: var(--hab-muted)">a month</span>
                        <button
                            type="button"
                            class="rounded-[9px] px-3 py-[6px] text-[12.5px] font-semibold disabled:opacity-50"
                            style="
                                background: var(--hab-btn-dark-bg);
                                color: var(--hab-btn-dark-ink);
                            "
                            :disabled="incomeForm.processing"
                            @click="saveIncome"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="px-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                            @click="editingIncome = false"
                        >
                            Cancel
                        </button>
                    </template>
                    <template v-else>
                        <span style="color: var(--hab-muted)">
                            Built on
                            {{ formatMoney(props.expectedMonthlyIncome ?? 0) }}
                            coming in each month
                        </span>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 underline underline-offset-2"
                            style="color: var(--hab-accent)"
                            @click="startEditingIncome"
                        >
                            <span
                                class="material-symbols-rounded"
                                style="font-size: 15px"
                                >edit</span
                            >
                            change
                        </button>
                    </template>
                </div>
                <p
                    v-if="incomeForm.errors.expected_monthly_income"
                    class="m-0 mt-2 text-[12.5px]"
                    style="color: var(--hab-clay)"
                >
                    {{ incomeForm.errors.expected_monthly_income }}
                </p>
            </div>

            <!-- The month rail -->
            <div
                class="mt-6 grid gap-[10px] sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <div
                    v-for="(row, i) in props.months"
                    :key="monthKey(row)"
                    class="hab-anim-row-in rounded-[16px] p-4 text-left"
                    :style="{
                        animationDelay: `${Math.min(i, 11) * 28}ms`,
                        background: isTight(row)
                            ? 'var(--hab-clay-tint)'
                            : 'var(--hab-card-bg)',
                        border:
                            '1px solid ' +
                            (isTight(row)
                                ? 'var(--hab-clay-border)'
                                : 'var(--hab-card-border)'),
                    }"
                >
                    <button
                        type="button"
                        class="flex w-full items-baseline justify-between gap-2 text-left"
                        :aria-expanded="openMonth === monthKey(row)"
                        @click="toggleMonth(row)"
                    >
                        <span
                            class="text-[13.5px] font-semibold"
                            style="color: var(--hab-ink)"
                        >
                            {{ shortMonth(row.month) }}
                            <span
                                v-if="i === 0"
                                class="ml-1 text-[11px] font-medium"
                                style="color: var(--hab-muted)"
                                >· rest of this month</span
                            >
                        </span>
                        <span
                            class="text-[15px] font-semibold"
                            style="font-family: var(--hab-mono)"
                            :style="{
                                color: isTight(row)
                                    ? 'var(--hab-clay)'
                                    : 'var(--hab-ink)',
                            }"
                        >
                            {{
                                row.balance !== null
                                    ? formatMoney(row.balance)
                                    : formatMoney(row.net)
                            }}
                        </span>
                    </button>

                    <!-- In and out, on one shared scale across the rail -->
                    <div class="mt-3 space-y-[6px]">
                        <div
                            class="h-[7px] overflow-hidden rounded-full"
                            style="background: var(--hab-progress-track)"
                        >
                            <div
                                class="hab-anim-grow-x h-full origin-left rounded-full"
                                :style="{
                                    width: barWidth(row.income),
                                    background: 'var(--hab-leaf-mid)',
                                }"
                            />
                        </div>
                        <div
                            class="h-[7px] overflow-hidden rounded-full"
                            style="background: var(--hab-progress-track)"
                        >
                            <div
                                class="hab-anim-grow-x h-full origin-left rounded-full"
                                :style="{
                                    width: barWidth(outflowOf(row)),
                                    background: 'var(--hab-apricot-lit)',
                                }"
                            />
                        </div>
                    </div>

                    <div
                        class="mt-2 flex justify-between text-[11.5px]"
                        style="color: var(--hab-muted)"
                    >
                        <span>{{ formatMoney(row.income) }} in</span>
                        <span>{{ formatMoney(outflowOf(row)) }} out</span>
                    </div>

                    <!-- Expanded breakdown -->
                    <div
                        v-if="openMonth === monthKey(row)"
                        class="hab-anim-row-in mt-3 border-t pt-3"
                        style="border-color: var(--hab-hairline)"
                    >
                        <div
                            v-for="line in breakdownOf(row)"
                            :key="line.label"
                            class="flex items-center justify-between py-[3px] text-[12.5px]"
                        >
                            <span
                                class="flex items-center gap-[6px]"
                                style="color: var(--hab-ink-secondary)"
                            >
                                <span
                                    class="material-symbols-rounded"
                                    style="
                                        font-size: 15px;
                                        color: var(--hab-faint);
                                    "
                                    >{{ line.icon }}</span
                                >
                                {{ line.label }}
                            </span>
                            <span
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                {{ formatMoney(line.amount) }}
                            </span>
                        </div>
                        <div
                            class="mt-2 flex items-center justify-between border-t pt-2 text-[12.5px] font-semibold"
                            style="border-color: var(--hab-hairline)"
                        >
                            <span style="color: var(--hab-ink)">
                                {{
                                    row.balance !== null
                                        ? 'Left at the end of ' +
                                          monthLabel(row)
                                        : monthLabel(row) + ' on its own'
                                }}
                            </span>
                            <span
                                style="font-family: var(--hab-mono)"
                                :style="{
                                    color: isTight(row)
                                        ? 'var(--hab-clay)'
                                        : 'var(--hab-ink)',
                                }"
                            >
                                {{
                                    formatMoney(
                                        row.balance !== null
                                            ? row.balance
                                            : row.net,
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <p
                class="mt-5 max-w-[62ch] text-[12.5px] leading-[1.6]"
                style="color: var(--hab-faint)"
            >
                This month counts only what is still ahead of you — pay that has
                landed and bills already paid are in the balance above. Later
                months assume your bills, envelopes and payoff plan hold steady.
            </p>
        </template>
    </div>
</template>
