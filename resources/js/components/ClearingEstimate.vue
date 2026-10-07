<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { clearing } from '@/actions/App/Http/Controllers/Money/DebtController';
import MoneyInput from '@/components/MoneyInput.vue';
import { formatMonth, formatPeso, monthNames, toPesos } from '@/utils/money';

type Clearing = {
    months: number;
    clearedBy: string | null;
    totalPaid: number;
    totalInterest: number;
    neverClears: boolean;
};

const props = defineProps<{
    debtId: number;
    defaultPayment: number;
    revolvingBalance: number;
    monthlyRate: number | null;
}>();

const amount = ref<number | null>(
    props.defaultPayment > 0 ? toPesos(props.defaultPayment) : null,
);
const result = ref<Clearing | null>(null);

const http = useHttp<{ amount: number | null }, Clearing>({ amount: null });
let timer: ReturnType<typeof setTimeout> | undefined;
let run = 0;

/** Asks the server when the balance clears. The math stays on the server. */
function refresh(): void {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const current = ++run;

        http.amount = amount.value;
        http.post(clearing(props.debtId).url, {
            onSuccess: (response) => {
                if (current === run) {
                    result.value = response;
                }
            },
            onError: () => {
                if (current === run) {
                    result.value = null;
                }
            },
        });
    }, 250);
}

watch(amount, refresh);
onMounted(refresh);
onBeforeUnmount(() => clearTimeout(timer));

/** One cell per payment, up to two years, so the length of the climb is visible. */
const stripLength = 24;
const strip = computed(() =>
    Array.from({ length: stripLength }, (_, index) => ({
        index,
        on: result.value ? index < result.value.months : false,
    })),
);

/** The month of the first payment: the cleared-by month, counted back. */
const firstMonthLabel = computed(() => {
    if (!result.value?.clearedBy) {
        return '';
    }

    const [year, month] = result.value.clearedBy.split('-').map(Number);
    const first = new Date(year, month - 1 - (result.value.months - 1), 1);

    return `${monthNames[first.getMonth()].slice(0, 3).toUpperCase()} ${String(first.getFullYear()).slice(2)}`;
});

const bigFieldClass =
    'bp-mono min-w-0 flex-1 bg-transparent text-[28px] font-medium outline-none placeholder:text-[var(--bp-fainter)]';
</script>

<template>
    <aside
        class="border border-[var(--bp-ink)] bg-[var(--bp-card)]"
        aria-label="Clearing estimate"
    >
        <div
            class="bp-mono flex items-center justify-between border-b border-[var(--bp-ink)] px-4 py-2.5 text-[10.5px] tracking-[0.12em]"
        >
            <span>CLEARING ESTIMATE</span>
            <span class="text-[var(--bp-faint)]">CARRIED BALANCE</span>
        </div>
        <div class="flex flex-col gap-4 px-4 py-[18px]">
            <p
                class="text-[13px] leading-normal text-pretty text-[var(--bp-ink-2)]"
            >
                The
                <span class="bp-mono">{{ formatPeso(revolvingBalance) }}</span>
                that carries over charges {{ monthlyRate ?? 0 }}% interest a
                month. Enter what you will pay towards it each month.
            </p>
            <div class="flex flex-col gap-1.5">
                <label for="clearing-amount" class="bp-caption"
                    >I pay each month</label
                >
                <div
                    class="flex items-baseline gap-1.5 border-b-2 border-[var(--bp-ink)] pb-1"
                >
                    <span class="text-[22px] text-[var(--bp-faint)]">₱</span>
                    <MoneyInput
                        id="clearing-amount"
                        v-model="amount"
                        :empty-value="null"
                        placeholder="0.00"
                        :class="bigFieldClass"
                    />
                </div>
                <div
                    v-if="defaultPayment > 0"
                    class="mt-1 flex flex-wrap gap-[5px]"
                >
                    <button
                        type="button"
                        class="bp-mono rounded-full border px-2.5 py-[3px] text-[12px]"
                        :class="
                            amount === toPesos(defaultPayment)
                                ? 'border-[var(--bp-ink)] bg-[var(--bp-ink)] text-[var(--bp-card)]'
                                : 'border-[var(--bp-line)] text-[var(--bp-ink-2)] hover:border-[var(--bp-ink)]'
                        "
                        @click="amount = toPesos(defaultPayment)"
                    >
                        {{ formatPeso(defaultPayment) }}
                    </button>
                </div>
                <span class="text-[12px] text-[var(--bp-muted)]">
                    <template v-if="defaultPayment > 0">
                        Starts at the part of your minimum that pays the
                        balance.
                    </template>
                    <template v-else>
                        Your minimum is all installments and charges, so none of
                        it pays the balance.
                    </template>
                </span>
            </div>

            <template v-if="result">
                <div
                    v-if="result.neverClears"
                    class="border border-[var(--bp-ink)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.055)_0_1px,transparent_1px_8px)] px-3 py-2.5 text-[12.5px] leading-normal text-pretty"
                    role="alert"
                >
                    {{
                        amount
                            ? 'That does not cover the interest, so the balance would never clear.'
                            : 'Enter an amount to see when it clears.'
                    }}
                </div>
                <div
                    v-else
                    class="border-t-[3px] border-double border-[var(--bp-ink)] pt-3"
                    role="status"
                >
                    <p class="bp-caption">Cleared by</p>
                    <p
                        class="mt-0.5 text-[30px] font-semibold tracking-[-0.02em] text-[var(--bp-accent)]"
                    >
                        {{ formatMonth(result.clearedBy ?? '') }}
                    </p>
                    <div
                        class="mt-2.5 grid grid-cols-3 border border-[var(--bp-line)]"
                    >
                        <div class="px-2.5 py-2">
                            <p class="text-[11px] text-[var(--bp-muted)]">
                                Payments
                            </p>
                            <p class="bp-mono text-[14px] font-semibold">
                                {{ result.months }}
                            </p>
                        </div>
                        <div
                            class="border-l border-[var(--bp-line)] px-2.5 py-2"
                        >
                            <p class="text-[11px] text-[var(--bp-muted)]">
                                Interest
                            </p>
                            <p
                                class="bp-mono text-[13px] font-semibold [overflow-wrap:anywhere]"
                            >
                                {{ formatPeso(result.totalInterest) }}
                            </p>
                        </div>
                        <div
                            class="border-l border-[var(--bp-line)] px-2.5 py-2"
                        >
                            <p class="text-[11px] text-[var(--bp-muted)]">
                                Paid in all
                            </p>
                            <p
                                class="bp-mono text-[13px] font-semibold [overflow-wrap:anywhere]"
                            >
                                {{ formatPeso(result.totalPaid) }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="mt-3 grid grid-cols-[repeat(24,minmax(0,1fr))] gap-0.5"
                        aria-hidden="true"
                    >
                        <div
                            v-for="cell in strip"
                            :key="cell.index"
                            class="box-border h-3"
                            :class="
                                cell.on
                                    ? 'bg-[var(--bp-accent)]'
                                    : 'border border-[var(--bp-line)]'
                            "
                        ></div>
                    </div>
                    <div
                        class="bp-mono mt-1 flex justify-between text-[9.5px] text-[var(--bp-faint)]"
                        aria-hidden="true"
                    >
                        <span>{{ firstMonthLabel }}</span>
                        <span>{{
                            result.months > stripLength
                                ? `${stripLength} OF ${result.months} MONTHS`
                                : `${stripLength} MONTHS`
                        }}</span>
                    </div>
                </div>
            </template>
        </div>
    </aside>
</template>
