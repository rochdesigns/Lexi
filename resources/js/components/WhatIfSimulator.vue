<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    afford,
    conversion,
    extraCash,
} from '@/actions/App/Http/Controllers/Money/WhatIfController';
import MoneyInput from '@/components/MoneyInput.vue';
import { formatMonth, formatPeso } from '@/utils/money';

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

const cardStyle =
    'background: var(--hab-card-bg); border: 1px solid var(--hab-card-border);';
const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
const labelClass =
    'mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]';
const headingClass = "font-['Bricolage_Grotesque'] text-[18px] font-bold";
</script>

<template>
    <div class="mt-10">
        <h2
            class="font-['Bricolage_Grotesque'] text-[24px] font-extrabold"
            style="color: var(--hab-ink)"
        >
            What if
        </h2>
        <p class="mt-1 mb-4 text-[14px]" style="color: var(--hab-muted)">
            Try a change before you make it. Nothing here is saved.
        </p>

        <section class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <h3 :class="headingClass" style="color: var(--hab-ink)">
                Can I afford this?
            </h3>
            <p class="mt-1 mb-4 text-[13px]" style="color: var(--hab-muted)">
                A new 0% installment, split evenly. On a card it starts with the
                next statement.
            </p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div>
                    <label :class="labelClass">Price</label>
                    <MoneyInput
                        v-model="price"
                        :empty-value="null"
                        adorn
                        :class="fieldClass"
                    />
                </div>
                <div>
                    <label :class="labelClass">Months</label>
                    <input
                        v-model.number="months"
                        type="number"
                        min="1"
                        max="60"
                        :class="fieldClass"
                    />
                </div>
                <div>
                    <label :class="labelClass">Paid with</label>
                    <select v-model="paidWith" :class="fieldClass">
                        <option
                            v-for="card in cards"
                            :key="card.id"
                            :value="card.id"
                        >
                            {{ card.name }}
                        </option>
                        <option value="">Not on a card</option>
                    </select>
                </div>
                <div v-if="paidWith === ''">
                    <label :class="labelClass">First payment</label>
                    <input
                        v-model="startMonth"
                        type="month"
                        :class="fieldClass"
                    />
                </div>
            </div>

            <div v-if="affordResult" class="mt-4">
                <p
                    class="mb-3 text-[14px] font-semibold"
                    :style="{
                        color: affordResult.shortMonths.length
                            ? '#c0392b'
                            : 'var(--hab-ink)',
                    }"
                    role="status"
                >
                    {{ formatPeso(affordResult.monthlyAmount) }} a month from
                    {{ formatMonth(affordResult.firstMonth) }} to
                    {{ formatMonth(affordResult.lastMonth) }}. {{ verdict }}
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-[13px]">
                        <thead>
                            <tr style="color: var(--hab-muted)">
                                <th class="py-1.5 pr-3 font-semibold">Month</th>
                                <th
                                    class="py-1.5 pr-3 text-right font-semibold"
                                >
                                    Now
                                </th>
                                <th class="py-1.5 text-right font-semibold">
                                    With this
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in affordResult.rows"
                                :key="row.month"
                                style="
                                    border-top: 1px solid var(--hab-hairline);
                                "
                            >
                                <td
                                    class="py-1.5 pr-3"
                                    style="color: var(--hab-ink)"
                                >
                                    {{ formatMonth(row.month) }}
                                </td>
                                <td
                                    class="py-1.5 pr-3 text-right tabular-nums"
                                    style="color: var(--hab-muted)"
                                >
                                    {{ formatPeso(row.before) }}
                                </td>
                                <td
                                    class="py-1.5 text-right font-semibold tabular-nums"
                                    :style="{
                                        color: row.short
                                            ? '#c0392b'
                                            : 'var(--hab-ink)',
                                    }"
                                >
                                    {{ formatPeso(row.after) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-[12px]" style="color: var(--hab-muted)">
                    This month uses your real minimums. Later months only know
                    the installments your cards bill, so they look better than
                    they will if you carry a balance.
                </p>
            </div>
        </section>

        <section class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <h3 :class="headingClass" style="color: var(--hab-ink)">
                Where should extra cash go?
            </h3>
            <p class="mt-1 mb-4 text-[13px]" style="color: var(--hab-muted)">
                Your payment plans, fastest to finish first, paid off while the
                money lasts.
            </p>

            <p
                v-if="!hasPlans"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                You have no payment plans to pay off.
            </p>
            <template v-else>
                <div class="max-w-[260px]">
                    <label :class="labelClass">Spare cash</label>
                    <MoneyInput
                        v-model="extra"
                        :empty-value="null"
                        adorn
                        :class="fieldClass"
                    />
                </div>

                <div v-if="extraResult" class="mt-4">
                    <p
                        class="mb-2 text-[14px] font-semibold"
                        style="color: var(--hab-ink)"
                    >
                        <template v-if="extraResult.freedMonthly > 0">
                            Uses {{ formatPeso(extraResult.spent) }} and frees
                            {{ formatPeso(extraResult.freedMonthly) }} a month.
                            {{ formatPeso(extraResult.leftover) }} is left over.
                        </template>
                        <template v-else
                            >That is not enough to finish any plan
                            yet.</template
                        >
                    </p>
                    <ul>
                        <li
                            v-for="(plan, index) in extraResult.plans"
                            :key="plan.planId"
                            class="flex items-center justify-between gap-3 py-2"
                            :style="
                                index > 0
                                    ? 'border-top: 1px solid var(--hab-hairline)'
                                    : ''
                            "
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-semibold"
                                    style="color: var(--hab-ink)"
                                >
                                    {{ index + 1 }}. {{ plan.description }}
                                    <span
                                        class="text-[12px] font-normal"
                                        style="color: var(--hab-muted)"
                                        >{{ plan.debtName }}</span
                                    >
                                </span>
                                <span
                                    class="block text-[12px]"
                                    style="color: var(--hab-muted)"
                                >
                                    {{ formatPeso(plan.remaining) }} left,
                                    {{ formatPeso(plan.monthlyAmount) }} a
                                    month, {{ plan.monthsLeft }}
                                    {{
                                        plan.monthsLeft === 1
                                            ? 'month'
                                            : 'months'
                                    }}
                                    to go<template
                                        v-if="plan.interestType === 'add_on'"
                                        >. Paying early will not save interest,
                                        it is already fixed</template
                                    >
                                </span>
                            </span>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold"
                                :style="
                                    plan.paysOff
                                        ? 'background: color-mix(in srgb, var(--hab-accent) 16%, transparent); color: var(--hab-accent)'
                                        : 'background: var(--hab-hairline); color: var(--hab-muted)'
                                "
                            >
                                {{ plan.paysOff ? 'Paid off' : 'Not enough' }}
                            </span>
                        </li>
                    </ul>
                </div>
            </template>
        </section>

        <section class="rounded-[22px] p-6" :style="cardStyle">
            <h3 :class="headingClass" style="color: var(--hab-ink)">
                Revolving or balance conversion?
            </h3>
            <p class="mt-1 mb-4 text-[13px]" style="color: var(--hab-muted)">
                Convert the balance a card carries into a fixed installment, or
                keep paying it revolving. Both use the same monthly payment.
            </p>

            <p
                v-if="carrying.length === 0"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                None of your cards carries a balance over.
            </p>
            <template v-else>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label :class="labelClass">Card</label>
                        <select v-model="convertCard" :class="fieldClass">
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
                    </div>
                    <div>
                        <label :class="labelClass"
                            >Conversion term (months)</label
                        >
                        <input
                            v-model.number="convertMonths"
                            type="number"
                            min="2"
                            max="60"
                            :class="fieldClass"
                        />
                    </div>
                    <div>
                        <label :class="labelClass"
                            >Bank interest a month (%)</label
                        >
                        <MoneyInput
                            v-model="convertRate"
                            :empty-value="null"
                            unit="%"
                            adorn
                            :class="fieldClass"
                        />
                    </div>
                </div>

                <div v-if="convertResult" class="mt-4">
                    <p
                        class="mb-3 text-[14px] font-semibold"
                        style="color: var(--hab-ink)"
                        role="status"
                    >
                        {{ formatPeso(convertResult.monthlyPayment) }} a month
                        either way. {{ convertVerdict }}
                    </p>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div
                            class="rounded-[14px] p-4"
                            style="border: 1px solid var(--hab-hairline)"
                        >
                            <p
                                class="text-[12.5px] font-semibold"
                                style="color: var(--hab-muted)"
                            >
                                Balance conversion
                            </p>
                            <p
                                class="text-[13.5px]"
                                style="color: var(--hab-ink)"
                            >
                                {{ convertResult.conversion.months }} payments,
                                {{
                                    formatPeso(
                                        convertResult.conversion.totalInterest,
                                    )
                                }}
                                interest,
                                {{
                                    formatPeso(
                                        convertResult.conversion.totalPaid,
                                    )
                                }}
                                paid in all
                            </p>
                        </div>
                        <div
                            class="rounded-[14px] p-4"
                            style="border: 1px solid var(--hab-hairline)"
                        >
                            <p
                                class="text-[12.5px] font-semibold"
                                style="color: var(--hab-muted)"
                            >
                                Staying revolving
                            </p>
                            <p
                                v-if="convertResult.revolving.neverClears"
                                class="text-[13.5px]"
                                style="color: #c0392b"
                            >
                                Never clears at this payment
                            </p>
                            <p
                                v-else
                                class="text-[13.5px]"
                                style="color: var(--hab-ink)"
                            >
                                {{ convertResult.revolving.months }} payments,
                                {{
                                    formatPeso(
                                        convertResult.revolving.totalInterest,
                                    )
                                }}
                                interest,
                                {{
                                    formatPeso(
                                        convertResult.revolving.totalPaid,
                                    )
                                }}
                                paid in all
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </div>
</template>
