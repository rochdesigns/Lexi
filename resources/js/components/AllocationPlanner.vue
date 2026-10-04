<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PaymentAllocationController from '@/actions/App/Http/Controllers/Money/PaymentAllocationController';
import MoneyInput from '@/components/MoneyInput.vue';
import { formatPeso, toPesos } from '@/utils/money';

type AllocationCard = {
    debtId: number;
    name: string;
    rate: number;
    minimum: number;
    minimumPaid: number;
    missedMinimum: number;
    extra: number;
    paid: number;
    revolvingBefore: number;
    revolvingAfter: number;
    interest: number;
};

type Allocation = {
    available: number;
    totalMinimums: number;
    shortBy: number;
    leftover: number;
    totalInterest: number;
    cards: AllocationCard[];
};

const props = defineProps<{ minimumsTotal: number }>();

const amount = ref<number | null>(toPesos(props.minimumsTotal));
const plan = ref<Allocation | null>(null);

const http = useHttp<{ amount: number | null }, Allocation>({ amount: null });
let timer: ReturnType<typeof setTimeout> | undefined;
let run = 0;

/** Asks the server how this amount would be split. The math stays on the server. */
function refresh(): void {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const current = ++run;

        http.amount = amount.value;
        http.post(PaymentAllocationController.url(), {
            onSuccess: (response) => {
                if (current === run) {
                    plan.value = response;
                }
            },
            onError: () => {
                if (current === run) {
                    plan.value = null;
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
            Where should this month's card payments go?
        </h2>
        <p class="mt-1 mb-4 text-[13px]" style="color: var(--hab-muted)">
            Enter what you can pay across your cards. Minimums are covered
            first, then the rest goes to the card with the highest interest.
        </p>

        <div class="mb-4 max-w-[260px]">
            <label
                class="mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]"
                >I can pay</label
            >
            <MoneyInput
                v-model="amount"
                :empty-value="null"
                adorn
                :class="fieldClass"
            />
            <p class="mt-1 text-[12px]" style="color: var(--hab-muted)">
                Your minimums add up to {{ formatPeso(minimumsTotal) }}.
            </p>
        </div>

        <template v-if="plan">
            <p
                v-if="plan.shortBy > 0"
                class="mb-3 rounded-[12px] px-4 py-3 text-[13.5px]"
                style="
                    background: #fdecea;
                    border: 1px solid #f5c6c0;
                    color: #8f2a1f;
                "
                role="alert"
            >
                This is {{ formatPeso(plan.shortBy) }} short of all the
                minimums. The card with the lowest interest goes unpaid, so
                expect a late fee there.
            </p>

            <ul class="flex flex-col">
                <li
                    v-for="(card, index) in plan.cards"
                    :key="card.debtId"
                    class="py-3"
                    :style="
                        index > 0
                            ? 'border-top: 1px solid var(--hab-hairline)'
                            : ''
                    "
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <p
                            class="text-[14px] font-semibold"
                            style="color: var(--hab-ink)"
                        >
                            {{ card.name }}
                            <span
                                class="text-[12px] font-normal"
                                style="color: var(--hab-muted)"
                                >{{ card.rate }}% a month</span
                            >
                        </p>
                        <p
                            class="text-[15px] font-bold tabular-nums"
                            style="color: var(--hab-ink)"
                        >
                            {{ formatPeso(card.paid) }}
                        </p>
                    </div>
                    <p class="text-[12.5px]" style="color: var(--hab-muted)">
                        Minimum {{ formatPeso(card.minimumPaid) }}
                        <template v-if="card.missedMinimum > 0">
                            (short {{ formatPeso(card.missedMinimum) }})
                        </template>
                        <template v-if="card.extra > 0">
                            + {{ formatPeso(card.extra) }} extra
                        </template>
                        · Interest-bearing balance after:
                        {{ formatPeso(card.revolvingAfter) }}, about
                        {{ formatPeso(card.interest) }} interest next month
                    </p>
                </li>
            </ul>

            <p
                v-if="plan.leftover > 0"
                class="mt-2 text-[13px]"
                style="color: var(--hab-muted)"
            >
                {{ formatPeso(plan.leftover) }} is more than the cards need, so
                it is not used.
            </p>

            <p
                class="mt-3 border-t pt-3 text-[14px] font-semibold"
                style="border-color: var(--hab-hairline); color: var(--hab-ink)"
            >
                Estimated interest next month:
                {{ formatPeso(plan.totalInterest) }}
            </p>
        </template>
    </div>
</template>
