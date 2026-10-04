<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { clearing } from '@/actions/App/Http/Controllers/Money/DebtController';
import MoneyInput from '@/components/MoneyInput.vue';
import { formatMonth, formatPeso, toPesos } from '@/utils/money';

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

const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
</script>

<template>
    <div
        class="mb-6 rounded-[22px] p-6"
        style="
            background: var(--hab-card-bg);
            border: 1px solid var(--hab-card-border);
            box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
        "
    >
        <h2
            class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
            style="color: var(--hab-ink)"
        >
            When will the balance clear?
        </h2>
        <p class="mt-1 mb-4 text-[13px]" style="color: var(--hab-muted)">
            The {{ formatPeso(revolvingBalance) }} that carries over charges
            {{ monthlyRate ?? 0 }}% interest a month. Enter what you will pay
            towards it each month.
        </p>

        <div class="mb-4 max-w-[260px]">
            <label
                class="mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]"
                >I pay each month</label
            >
            <MoneyInput
                v-model="amount"
                :empty-value="null"
                adorn
                :class="fieldClass"
            />
            <p class="mt-1 text-[12px]" style="color: var(--hab-muted)">
                <template v-if="defaultPayment > 0">
                    Starts at the part of your minimum that pays the balance.
                </template>
                <template v-else>
                    Your minimum is all installments and charges, so none of it
                    pays the balance.
                </template>
            </p>
        </div>

        <template v-if="result">
            <p
                v-if="result.neverClears"
                class="rounded-[12px] px-4 py-3 text-[13.5px]"
                style="
                    background: #fdecea;
                    border: 1px solid #f5c6c0;
                    color: #8f2a1f;
                "
                role="alert"
            >
                {{
                    amount
                        ? 'That does not cover the interest, so the balance would never clear.'
                        : 'Enter an amount to see when it clears.'
                }}
            </p>
            <div v-else>
                <p
                    class="font-['Bricolage_Grotesque'] text-[26px] font-extrabold"
                    style="color: var(--hab-ink)"
                >
                    Cleared by {{ formatMonth(result.clearedBy ?? '') }}
                </p>
                <p class="text-[13px]" style="color: var(--hab-muted)">
                    {{ result.months }}
                    {{ result.months === 1 ? 'payment' : 'payments' }}, about
                    {{ formatPeso(result.totalInterest) }} in interest, and
                    {{ formatPeso(result.totalPaid) }} paid in all.
                </p>
            </div>
        </template>
    </div>
</template>
