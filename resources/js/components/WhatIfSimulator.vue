<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    afford,
    conversion,
    extraCash,
} from '@/actions/App/Http/Controllers/Money/WhatIfController';
import MoneyInput from '@/components/MoneyInput.vue';
import {
    formatMonth,
    formatPeso,
    formatPesoShort,
    monthNames,
} from '@/utils/money';

type Affordability = {
    monthlyAmount: number;
    firstMonth: string;
    lastMonth: string;
    rows: {
        month: string;
        before: number;
        after: number;
        short: boolean;
        newlyShort: boolean;
    }[];
    shortMonths: string[];
};

type ExtraCash = {
    amount: number;
    spent: number;
    leftover: number;
    freedMonthly: number;
    plans: {
        planId: number;
        description: string;
        debtName: string;
        remaining: number;
        monthlyAmount: number;
        monthsLeft: number;
        paysOff: boolean;
        interestType: string;
        savesInterest: boolean;
    }[];
};

type Conversion = {
    balance: number;
    monthlyPayment: number;
    conversion: { months: number; totalInterest: number; totalPaid: number };
    revolving: {
        months: number;
        totalInterest: number;
        totalPaid: number;
        neverClears: boolean;
    };
    cheaper: 'conversion' | 'revolving' | 'same';
    interestSaved: number;
};

const props = defineProps<{
    cards: { id: number; name: string; revolvingBalance: number }[];
    hasPlans: boolean;
}>();

type Tab = 'afford' | 'extra' | 'conversion';

const tabs: { value: Tab; label: string }[] = [
    { value: 'afford', label: 'Afford' },
    { value: 'extra', label: 'Extra cash' },
    { value: 'conversion', label: 'Conversion' },
];
const tab = ref<Tab>('afford');

const monthPresets = [3, 6, 12, 24];

/** Whole pesos with a real minus sign, for the before and after columns. */
function signedShort(centavos: number): string {
    return (centavos < 0 ? '−' : '') + formatPesoShort(Math.abs(centavos));
}

/** "2026-11" -> "NOV 26", narrow enough for the table's first column. */
function shortMonth(month: string): string {
    const [year, mon] = month.split('-');

    return `${monthNames[Number(mon) - 1].slice(0, 3).toUpperCase()} ${year.slice(2)}`;
}

const timers: ReturnType<typeof setTimeout>[] = [];

/** Runs a request a moment after the last change, so typing does not fire one per key. */
function later(run: () => void, slot: number): void {
    clearTimeout(timers[slot]);
    timers[slot] = setTimeout(run, 250);
}

onBeforeUnmount(() => timers.forEach((timer) => clearTimeout(timer)));

// ── Can I afford this? ──────────────────────────────────────────────────────

function thisMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

const price = ref<number | null>(null);
const months = ref(6);
const paidWith = ref<number | ''>(props.cards[0]?.id ?? '');
const startMonth = ref(thisMonth());
const affordResult = ref<Affordability | null>(null);
const affordHttp = useHttp<
    {
        price: number | null;
        months: number;
        debt_id: number | null;
        start_month: string | null;
    },
    Affordability
>({ price: null, months: 6, debt_id: null, start_month: null });
let affordRun = 0;

function runAfford(): void {
    if (!price.value || price.value <= 0 || !months.value) {
        affordResult.value = null;

        return;
    }

    const run = ++affordRun;

    affordHttp.price = price.value;
    affordHttp.months = months.value;
    affordHttp.debt_id = paidWith.value === '' ? null : paidWith.value;
    affordHttp.start_month = paidWith.value === '' ? startMonth.value : null;
    affordHttp.post(afford().url, {
        onSuccess: (response) => {
            if (run === affordRun) {
                affordResult.value = response;
            }
        },
        onError: () => {
            if (run === affordRun) {
                affordResult.value = null;
            }
        },
    });
}

watch([price, months, paidWith, startMonth], () => later(runAfford, 0));

const verdict = computed(() => {
    const result = affordResult.value;

    if (!result) {
        return '';
    }

    return result.shortMonths.length === 0
        ? 'You can afford this. Safe to spend stays above zero every month.'
        : `Safe to spend goes below zero in ${result.shortMonths.map(formatMonth).join(', ')}.`;
});

// ── Where should extra cash go? ─────────────────────────────────────────────

const extra = ref<number | null>(null);
const extraResult = ref<ExtraCash | null>(null);
const extraHttp = useHttp<{ amount: number | null }, ExtraCash>({
    amount: null,
});
let extraRun = 0;

function runExtra(): void {
    if (extra.value === null) {
        extraResult.value = null;

        return;
    }

    const run = ++extraRun;

    extraHttp.amount = extra.value;
    extraHttp.post(extraCash().url, {
        onSuccess: (response) => {
            if (run === extraRun) {
                extraResult.value = response;
            }
        },
        onError: () => {
            if (run === extraRun) {
                extraResult.value = null;
            }
        },
    });
}

watch(extra, () => later(runExtra, 1));

// ── Revolving or balance conversion? ────────────────────────────────────────

const carrying = computed(() =>
    props.cards.filter((card) => card.revolvingBalance > 0),
);
const convertCard = ref<number | ''>(carrying.value[0]?.id ?? '');
const convertMonths = ref(12);
const convertRate = ref<number | null>(null);
const convertResult = ref<Conversion | null>(null);
const convertHttp = useHttp<
    { debt_id: number | null; months: number; rate: number | null },
    Conversion
>({ debt_id: null, months: 12, rate: null });
let convertRun = 0;

function runConvert(): void {
    if (convertCard.value === '' || convertRate.value === null) {
        convertResult.value = null;

        return;
    }

    const run = ++convertRun;

    convertHttp.debt_id = convertCard.value;
    convertHttp.months = convertMonths.value;
    convertHttp.rate = convertRate.value;
    convertHttp.post(conversion().url, {
        onSuccess: (response) => {
            if (run === convertRun) {
                convertResult.value = response;
            }
        },
        onError: () => {
            if (run === convertRun) {
                convertResult.value = null;
            }
        },
    });
}

watch([convertCard, convertMonths, convertRate], () => later(runConvert, 2));

const convertVerdict = computed(() => {
    const result = convertResult.value;

    if (!result) {
        return '';
    }

    if (result.cheaper === 'same') {
        return 'Both cost the same in interest.';
    }

    const saving = formatPeso(result.interestSaved);

    if (result.revolving.neverClears) {
        return 'That payment never clears the balance while it stays revolving, so the conversion is the way out.';
    }

    return result.cheaper === 'conversion'
        ? `Converting saves about ${saving} in interest.`
        : `Staying revolving saves about ${saving} in interest.`;
});

const bigFieldClass =
    'bp-mono min-w-0 flex-1 bg-transparent text-[24px] font-medium outline-none placeholder:text-[var(--bp-fainter)]';
</script>

<template>
    <aside
        class="flex flex-col border border-[var(--bp-ink)] bg-[var(--bp-card)]"
        aria-label="What if"
    >
        <div
            class="bp-mono flex items-center justify-between border-b border-[var(--bp-ink)] px-4 py-2.5 text-[10.5px] tracking-[0.12em]"
        >
            <span>WHAT IF</span>
            <span class="text-[var(--bp-faint)]">NOTHING IS SAVED</span>
        </div>
        <div
            class="grid grid-cols-3 border-b border-[var(--bp-line)]"
            role="tablist"
        >
            <button
                v-for="option in tabs"
                :key="option.value"
                type="button"
                role="tab"
                :aria-selected="tab === option.value"
                class="py-[9px] text-center text-[12.5px]"
                :class="
                    tab === option.value
                        ? 'font-semibold text-[var(--bp-accent)] shadow-[inset_0_-2px_0_var(--bp-accent)]'
                        : 'text-[var(--bp-muted)] hover:text-[var(--bp-ink)]'
                "
                @click="tab = option.value"
            >
                {{ option.label }}
            </button>
        </div>

        <!-- Can I afford this? -->
        <div
            v-show="tab === 'afford'"
            class="flex flex-col gap-3.5 px-4 py-[18px]"
            role="tabpanel"
        >
            <div>
                <p class="text-[14px] font-semibold">Can I afford this?</p>
                <p class="mt-0.5 text-[12px] text-[var(--bp-muted)]">
                    A new 0% installment, split evenly. On a card it starts with
                    the next statement.
                </p>
            </div>
            <label class="flex flex-col gap-1.5">
                <span class="bp-caption">Price</span>
                <span
                    class="flex items-baseline gap-1.5 border-b-2 border-[var(--bp-ink)] pb-1"
                >
                    <span class="text-[20px] text-[var(--bp-faint)]">₱</span>
                    <MoneyInput
                        v-model="price"
                        :empty-value="null"
                        placeholder="0.00"
                        :class="bigFieldClass"
                    />
                </span>
            </label>
            <div
                class="flex items-center justify-between gap-2 border-b border-[var(--bp-hair)] pb-2.5 text-[13px]"
            >
                <span class="text-[var(--bp-muted)]">Over</span>
                <div class="flex items-center gap-1.5">
                    <div
                        class="flex overflow-hidden rounded-[3px] border border-[var(--bp-ink)]"
                    >
                        <button
                            v-for="preset in monthPresets"
                            :key="preset"
                            type="button"
                            class="px-2 py-[3px] text-[12px]"
                            :class="
                                months === preset
                                    ? 'bg-[var(--bp-ink)] font-semibold text-[var(--bp-card)]'
                                    : 'hover:bg-[var(--bp-hover)]'
                            "
                            :aria-pressed="months === preset"
                            @click="months = preset"
                        >
                            {{ preset }}
                        </button>
                    </div>
                    <input
                        v-model.number="months"
                        type="number"
                        min="1"
                        max="60"
                        aria-label="Months"
                        class="bp-mono w-11 border-b border-[var(--bp-line)] bg-transparent text-right text-[12px] outline-none focus:border-[var(--bp-ink)]"
                    />
                    <span class="text-[12px] text-[var(--bp-muted)]">mo</span>
                </div>
            </div>
            <label
                class="flex items-center justify-between gap-3 border-b border-[var(--bp-hair)] pb-2.5 text-[13px]"
            >
                <span class="shrink-0 text-[var(--bp-muted)]">Paid with</span>
                <select
                    v-model="paidWith"
                    class="min-w-0 bg-transparent text-right font-medium outline-none"
                >
                    <option
                        v-for="card in cards"
                        :key="card.id"
                        :value="card.id"
                    >
                        {{ card.name }}
                    </option>
                    <option value="">Not on a card</option>
                </select>
            </label>
            <label
                v-if="paidWith === ''"
                class="flex items-center justify-between gap-3 border-b border-[var(--bp-hair)] pb-2.5 text-[13px]"
            >
                <span class="shrink-0 text-[var(--bp-muted)]"
                    >First payment</span
                >
                <input
                    v-model="startMonth"
                    type="month"
                    class="bg-transparent text-right font-medium outline-none"
                />
            </label>

            <template v-if="affordResult">
                <div>
                    <div
                        class="bp-mono grid grid-cols-[56px_minmax(0,1fr)_minmax(0,1fr)] gap-2 border-b border-[var(--bp-ink)] pb-1.5 text-[9.5px] tracking-[0.1em] text-[var(--bp-muted)]"
                    >
                        <span></span>
                        <span class="text-right">BEFORE</span>
                        <span class="text-right">AFTER</span>
                    </div>
                    <div
                        v-for="row in affordResult.rows"
                        :key="row.month"
                        class="bp-mono grid grid-cols-[56px_minmax(0,1fr)_minmax(0,1fr)] gap-2 border-b border-[var(--bp-hair)] py-[5px] text-[11.5px]"
                    >
                        <span class="text-[var(--bp-muted)]">{{
                            shortMonth(row.month)
                        }}</span>
                        <span class="text-right text-[var(--bp-faint)]">{{
                            signedShort(row.before)
                        }}</span>
                        <span
                            class="text-right font-semibold"
                            :class="{
                                'bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.09)_0_1px,transparent_1px_5px)]':
                                    row.short,
                            }"
                        >
                            <span v-if="row.short" class="sr-only"
                                >Short:
                            </span>
                            {{ signedShort(row.after) }}
                        </span>
                    </div>
                </div>
                <p
                    class="border-t-[3px] border-double border-[var(--bp-ink)] pt-2.5 text-[13px] leading-normal font-semibold text-pretty"
                    role="status"
                >
                    {{ formatPeso(affordResult.monthlyAmount) }} a month from
                    {{ formatMonth(affordResult.firstMonth) }} to
                    {{ formatMonth(affordResult.lastMonth) }}. {{ verdict }}
                </p>
                <p class="text-[11.5px] text-[var(--bp-muted)]">
                    This month uses your real minimums. Later months only know
                    the installments your cards bill, so they look better than
                    they will if you carry a balance.
                </p>
            </template>
        </div>

        <!-- Where should extra cash go? -->
        <div
            v-show="tab === 'extra'"
            class="flex flex-col gap-3.5 px-4 py-[18px]"
            role="tabpanel"
        >
            <div>
                <p class="text-[14px] font-semibold">
                    Where should extra cash go?
                </p>
                <p class="mt-0.5 text-[12px] text-[var(--bp-muted)]">
                    Your payment plans, fastest to finish first, paid off while
                    the money lasts.
                </p>
            </div>
            <p v-if="!hasPlans" class="text-[13px] text-[var(--bp-muted)]">
                You have no payment plans to pay off.
            </p>
            <template v-else>
                <label class="flex flex-col gap-1.5">
                    <span class="bp-caption">Spare cash</span>
                    <span
                        class="flex items-baseline gap-1.5 border-b-2 border-[var(--bp-ink)] pb-1"
                    >
                        <span class="text-[20px] text-[var(--bp-faint)]"
                            >₱</span
                        >
                        <MoneyInput
                            v-model="extra"
                            :empty-value="null"
                            placeholder="0.00"
                            :class="bigFieldClass"
                        />
                    </span>
                </label>
                <template v-if="extraResult">
                    <div class="border-t border-[var(--bp-ink)]">
                        <div
                            v-for="plan in extraResult.plans"
                            :key="plan.planId"
                            class="border-b border-[var(--bp-hair)] py-[9px]"
                        >
                            <div class="flex justify-between gap-3 text-[13px]">
                                <span class="min-w-0 truncate font-semibold"
                                    >{{ plan.description }}
                                    <span
                                        class="font-normal text-[var(--bp-muted)]"
                                        >· {{ plan.debtName }}</span
                                    ></span
                                >
                                <span class="bp-mono shrink-0">{{
                                    formatPeso(plan.remaining)
                                }}</span>
                            </div>
                            <p class="text-[11.5px] text-[var(--bp-muted)]">
                                <template v-if="plan.paysOff"
                                    >Pays it off · frees
                                    {{ formatPesoShort(plan.monthlyAmount) }} a
                                    month</template
                                >
                                <template v-else
                                    >Not enough · {{ plan.monthsLeft }}
                                    {{
                                        plan.monthsLeft === 1
                                            ? 'month'
                                            : 'months'
                                    }}
                                    left</template
                                >
                                <template v-if="plan.interestType === 'add_on'">
                                    · fixed interest, no saving</template
                                >
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="extraResult.freedMonthly > 0"
                        class="flex justify-between border-t-[3px] border-double border-[var(--bp-ink)] pt-2.5 text-[13px]"
                        role="status"
                    >
                        <span class="font-semibold">Frees each month</span>
                        <span
                            class="bp-mono font-semibold text-[var(--bp-accent)]"
                            >{{ formatPeso(extraResult.freedMonthly) }}</span
                        >
                    </div>
                    <p
                        v-else
                        class="border-t-[3px] border-double border-[var(--bp-ink)] pt-2.5 text-[13px] font-semibold"
                        role="status"
                    >
                        That is not enough to finish any plan yet.
                    </p>
                    <span
                        v-if="extraResult.freedMonthly > 0"
                        class="text-[12px] text-[var(--bp-muted)]"
                        >Uses {{ formatPeso(extraResult.spent) }}.
                        {{ formatPeso(extraResult.leftover) }} is left
                        over.</span
                    >
                </template>
            </template>
        </div>

        <!-- Revolving or balance conversion? -->
        <div
            v-show="tab === 'conversion'"
            class="flex flex-col gap-3.5 px-4 py-[18px]"
            role="tabpanel"
        >
            <div>
                <p class="text-[14px] font-semibold">Convert a card balance?</p>
                <p class="mt-0.5 text-[12px] text-[var(--bp-muted)]">
                    Turn the balance a card carries into a fixed installment, or
                    keep paying it revolving. Both use the same monthly payment.
                </p>
            </div>
            <p
                v-if="carrying.length === 0"
                class="text-[13px] text-[var(--bp-muted)]"
            >
                None of your cards carries a balance over.
            </p>
            <template v-else>
                <label
                    class="flex items-center justify-between gap-3 border-b border-[var(--bp-hair)] pb-2.5 text-[13px]"
                >
                    <span class="shrink-0 text-[var(--bp-muted)]">Card</span>
                    <select
                        v-model="convertCard"
                        class="min-w-0 bg-transparent text-right font-medium outline-none"
                    >
                        <option
                            v-for="card in carrying"
                            :key="card.id"
                            :value="card.id"
                        >
                            {{ card.name }} ({{
                                formatPeso(card.revolvingBalance)
                            }})
                        </option>
                    </select>
                </label>
                <label
                    class="flex items-center justify-between gap-3 border-b border-[var(--bp-hair)] pb-2.5 text-[13px]"
                >
                    <span class="text-[var(--bp-muted)]">Conversion term</span>
                    <span class="flex items-baseline gap-1">
                        <input
                            v-model.number="convertMonths"
                            type="number"
                            min="2"
                            max="60"
                            class="bp-mono w-12 bg-transparent text-right outline-none"
                        />
                        <span class="text-[12px] text-[var(--bp-muted)]"
                            >months</span
                        >
                    </span>
                </label>
                <label
                    class="flex items-center justify-between gap-3 border-b border-[var(--bp-hair)] pb-2.5 text-[13px]"
                >
                    <span class="text-[var(--bp-muted)]"
                        >Bank interest a month</span
                    >
                    <span class="flex items-baseline gap-1">
                        <MoneyInput
                            v-model="convertRate"
                            :empty-value="null"
                            unit="%"
                            placeholder="0.00"
                            class="bp-mono w-16 bg-transparent text-right outline-none"
                        />
                        <span class="text-[12px] text-[var(--bp-muted)]"
                            >%</span
                        >
                    </span>
                </label>

                <template v-if="convertResult">
                    <div class="grid grid-cols-2 border border-[var(--bp-ink)]">
                        <div
                            class="px-3 py-2.5"
                            :class="{
                                'bg-[var(--bp-tint)]':
                                    convertResult.cheaper === 'conversion',
                            }"
                        >
                            <p
                                class="bp-mono text-[9.5px] tracking-[0.1em]"
                                :class="
                                    convertResult.cheaper === 'conversion'
                                        ? 'text-[var(--bp-accent)]'
                                        : 'text-[var(--bp-muted)]'
                                "
                            >
                                CONVERSION
                            </p>
                            <p class="bp-mono mt-1 text-[14px] font-semibold">
                                {{
                                    formatPeso(
                                        convertResult.conversion.totalInterest,
                                    )
                                }}
                            </p>
                            <p class="text-[11.5px] text-[var(--bp-muted)]">
                                interest over
                                {{ convertResult.conversion.months }} months
                            </p>
                        </div>
                        <div
                            class="border-l border-[var(--bp-ink)] px-3 py-2.5"
                            :class="{
                                'bg-[var(--bp-tint)]':
                                    convertResult.cheaper === 'revolving',
                            }"
                        >
                            <p
                                class="bp-mono text-[9.5px] tracking-[0.1em]"
                                :class="
                                    convertResult.cheaper === 'revolving'
                                        ? 'text-[var(--bp-accent)]'
                                        : 'text-[var(--bp-muted)]'
                                "
                            >
                                KEEP REVOLVING
                            </p>
                            <template
                                v-if="convertResult.revolving.neverClears"
                            >
                                <p
                                    class="bp-mono mt-1 text-[14px] font-semibold"
                                >
                                    Never clears
                                </p>
                                <p class="text-[11.5px] text-[var(--bp-muted)]">
                                    at this payment
                                </p>
                            </template>
                            <template v-else>
                                <p
                                    class="bp-mono mt-1 text-[14px] font-semibold"
                                >
                                    {{
                                        formatPeso(
                                            convertResult.revolving
                                                .totalInterest,
                                        )
                                    }}
                                </p>
                                <p class="text-[11.5px] text-[var(--bp-muted)]">
                                    interest over
                                    {{ convertResult.revolving.months }} months
                                </p>
                            </template>
                        </div>
                    </div>
                    <p
                        class="border-t-[3px] border-double border-[var(--bp-ink)] pt-2.5 text-[13px] leading-normal"
                        role="status"
                    >
                        <span class="bp-mono">{{
                            formatPeso(convertResult.monthlyPayment)
                        }}</span>
                        a month either way.
                        <span class="font-semibold">{{ convertVerdict }}</span>
                    </p>
                </template>
            </template>
        </div>
    </aside>
</template>
