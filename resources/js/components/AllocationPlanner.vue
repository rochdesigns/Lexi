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

const bigFieldClass =
    'bp-mono min-w-0 flex-1 bg-transparent text-[28px] font-medium outline-none placeholder:text-[var(--bp-fainter)]';
</script>

<template>
    <aside
        class="border border-[var(--bp-ink)] bg-[var(--bp-card)]"
        aria-label="Card payments"
    >
        <div
            class="bp-mono flex items-center justify-between border-b border-[var(--bp-ink)] px-4 py-2.5 text-[10.5px] tracking-[0.12em]"
        >
            <span>CARD PAYMENTS</span>
            <span class="text-[var(--bp-faint)]">THIS MONTH</span>
        </div>
        <div class="flex flex-col gap-4 px-4 py-[18px]">
            <p
                class="text-[13px] leading-normal text-pretty text-[var(--bp-ink-2)]"
            >
                Enter what you can pay across your cards. Minimums are covered
                first, then the rest goes to the card with the highest interest.
            </p>
            <div class="flex flex-col gap-1.5">
                <label for="allocation-amount" class="bp-caption"
                    >I can pay</label
                >
                <div
                    class="flex items-baseline gap-1.5 border-b-2 border-[var(--bp-ink)] pb-1"
                >
                    <span class="text-[22px] text-[var(--bp-faint)]">₱</span>
                    <MoneyInput
                        id="allocation-amount"
                        v-model="amount"
                        :empty-value="null"
                        placeholder="0.00"
                        :class="bigFieldClass"
                    />
                </div>
                <div class="mt-1 flex flex-wrap gap-[5px]">
                    <button
                        type="button"
                        class="bp-mono rounded-full border px-2.5 py-[3px] text-[12px]"
                        :class="
                            amount === toPesos(minimumsTotal)
                                ? 'border-[var(--bp-ink)] bg-[var(--bp-ink)] text-[var(--bp-card)]'
                                : 'border-[var(--bp-line)] text-[var(--bp-ink-2)] hover:border-[var(--bp-ink)]'
                        "
                        @click="amount = toPesos(minimumsTotal)"
                    >
                        Just the minimums
                    </button>
                </div>
                <span class="text-[12px] text-[var(--bp-muted)]"
                    >Your minimums add up to
                    {{ formatPeso(minimumsTotal) }}.</span
                >
            </div>

            <template v-if="plan">
                <div
                    v-if="plan.shortBy > 0"
                    class="border border-[var(--bp-ink)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.055)_0_1px,transparent_1px_8px)] px-3 py-2.5 text-[12.5px] leading-normal"
                    role="alert"
                >
                    This is {{ formatPeso(plan.shortBy) }} short of all the
                    minimums. The card with the lowest interest goes unpaid, so
                    expect a late fee there.
                </div>

                <div class="flex flex-col border-t border-[var(--bp-ink)]">
                    <div
                        v-for="card in plan.cards"
                        :key="card.debtId"
                        class="border-b border-[var(--bp-hair)] py-2.5"
                    >
                        <div
                            class="flex items-baseline justify-between gap-2.5"
                        >
                            <span class="text-[13.5px] font-semibold"
                                >{{ card.name }}
                                <span
                                    class="text-[11.5px] font-normal text-[var(--bp-muted)]"
                                    >{{ card.rate }}% a month</span
                                ></span
                            >
                            <span class="bp-mono text-[14px] font-semibold">{{
                                formatPeso(card.paid)
                            }}</span>
                        </div>
                        <p
                            class="mt-[3px] text-[12px] leading-normal text-[var(--bp-muted)]"
                        >
                            Minimum {{ formatPeso(card.minimumPaid)
                            }}<template v-if="card.missedMinimum > 0">
                                (short
                                {{ formatPeso(card.missedMinimum) }})</template
                            ><template v-if="card.extra > 0">
                                + {{ formatPeso(card.extra) }} extra</template
                            >
                            · balance after
                            {{ formatPeso(card.revolvingAfter) }}, about
                            {{ formatPeso(card.interest) }} interest
                        </p>
                    </div>
                </div>

                <span
                    v-if="plan.leftover > 0"
                    class="text-[12.5px] text-[var(--bp-muted)]"
                    >{{ formatPeso(plan.leftover) }} is more than the cards
                    need, so it is not used.</span
                >

                <div
                    class="-mt-1 flex items-baseline justify-between gap-2.5 border-t-[3px] border-double border-[var(--bp-ink)] pt-2.5"
                    role="status"
                >
                    <span class="text-[13px] font-semibold"
                        >Interest next month</span
                    >
                    <span
                        class="bp-mono text-[18px] font-semibold text-[var(--bp-accent)]"
                        >{{ formatPeso(plan.totalInterest) }}</span
                    >
                </div>
            </template>
        </div>
    </aside>
</template>
