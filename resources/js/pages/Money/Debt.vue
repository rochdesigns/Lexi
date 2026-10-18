<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    destroy as destroyDebt,
    index,
} from '@/actions/App/Http/Controllers/Money/DebtController';
import { destroy as destroyPayment } from '@/actions/App/Http/Controllers/Money/DebtPaymentController';
import { destroy as destroyPlan } from '@/actions/App/Http/Controllers/Money/PaymentPlanController';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import ClearingEstimate from '@/components/ClearingEstimate.vue';
import DebtFormDialog from '@/components/DebtFormDialog.vue';
import type { DebtFormDebt } from '@/components/DebtFormDialog.vue';
import InfoNote from '@/components/InfoNote.vue';
import PaymentFormDialog from '@/components/PaymentFormDialog.vue';
import PaymentPlanFormDialog from '@/components/PaymentPlanFormDialog.vue';
import type { PaymentPlanItem } from '@/components/PaymentPlanFormDialog.vue';
import RolloffChart from '@/components/RolloffChart.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { sheetWidth } from '@/utils/blueprint';
import {
    categoryLabel,
    debtTypes,
    formatDay,
    formatMonth,
    formatPeso,
    interestLabel,
} from '@/utils/money';

type Plan = PaymentPlanItem & {
    endMonth: string;
    monthsLeft: number;
    remainingBalance: number;
    interestType: string;
    totalInterest: number;
    interestThisMonth: number | null;
    principalThisMonth: number | null;
    earlyPayoffSavesInterest: boolean;
};

type Payment = {
    id: number;
    paymentPlanId: number | null;
    accountId: number | null;
    date: string;
    amount: number;
    isExtra: boolean;
};

type StatementEntry = {
    kind: 'charge' | 'payment';
    id: number;
    date: string;
    amount: number;
    label: string | null;
    category: string | null;
};

type Statement = {
    closesOn: string;
    opensOn: string;
    charged: number;
    paid: number;
    entries: StatementEntry[];
};

const props = defineProps<{
    debt: DebtFormDebt & {
        totalRemaining: number;
        dueThisMonth: number;
        paidThisMonth: number;
        nextDueDate: string | null;
        card:
            | (NonNullable<DebtFormDebt['card']> & {
                  closesOn: string;
                  projectedStatement: number;
                  minimumDue: number | null;
                  utilization: number | null;
              })
            | null;
    };
    plans: Plan[];
    accounts: { id: number; name: string; balance: number }[];
    payments: Payment[];
    rollOff: { month: string; total: number }[];
    clearingDefault: number | null;
    statements: Statement[];
}>();

const editingDebt = ref(false);
const deletingDebt = ref(false);
const planDialog = ref(false);
const editingPlan = ref<Plan | null>(null);
const paymentDialog = ref(false);

const isInformal = computed(() => props.debt.type === 'informal');
const hasPlans = computed(() => !isInformal.value);

function addPlan(): void {
    editingPlan.value = null;
    planDialog.value = true;
}

function editPlan(plan: Plan): void {
    editingPlan.value = plan;
    planDialog.value = true;
}

function removePlan(plan: Plan): void {
    router.delete(destroyPlan(plan.id).url, { preserveScroll: true });
}

function removePayment(payment: Payment): void {
    router.delete(destroyPayment(payment.id).url, { preserveScroll: true });
}

function removeDebt(): void {
    router.delete(destroyDebt(props.debt.id).url);
}

function planName(id: number | null): string {
    return props.plans.find((plan) => plan.id === id)?.description ?? 'General';
}

const card = computed(() => props.debt.card);

const openStatements = ref<string[]>(
    props.statements.length ? [props.statements[0].closesOn] : [],
);

function toggleStatement(closesOn: string): void {
    openStatements.value = openStatements.value.includes(closesOn)
        ? openStatements.value.filter((open) => open !== closesOn)
        : [...openStatements.value, closesOn];
}

function entryLabel(entry: StatementEntry): string {
    if (entry.kind === 'payment') {
        return 'Payment';
    }

    return entry.label ?? categoryLabel(entry.category ?? 'other');
}
const utilizationPct = computed(() =>
    Math.round((card.value?.utilization ?? 0) * 100),
);

const showsClearing = computed(
    () =>
        card.value !== null &&
        card.value.revolvingBalance > 0 &&
        props.clearingDefault !== null,
);

const typeLabel = computed(
    () =>
        debtTypes.find((type) => type.value === props.debt.type)?.single ??
        'Debt',
);

const typeIcons: Record<string, string> = {
    credit_card: 'credit_card',
    loan: 'account_balance',
    bnpl: 'shopping_bag',
    informal: 'handshake',
};

const subtitle = computed(() => {
    const parts = [
        props.debt.lender,
        interestLabel(props.debt.interestType) +
            (props.debt.monthlyInterestRate !== null
                ? ` at ${props.debt.monthlyInterestRate}% a month`
                : ''),
    ];

    if (props.debt.nextDueDate) {
        parts.push(`Next due ${formatDay(props.debt.nextDueDate)}`);
    }

    if (card.value) {
        parts.push(`Closes ${formatDay(card.value.closesOn)}`);
    }

    return parts.filter(Boolean).join(' · ');
});

const dimension = computed(() => {
    if (props.debt.status === 'paid_off') {
        return 'Paid off';
    }

    const parts: string[] = [];

    if (props.debt.nextDueDate) {
        parts.push(`Next due ${formatDay(props.debt.nextDueDate)}`);
    }

    if (card.value) {
        parts.push(`Statement closes ${formatDay(card.value.closesOn)}`);
    } else if (isInformal.value && props.debt.targetPayoffDate) {
        parts.push(`Aim to clear by ${formatDay(props.debt.targetPayoffDate)}`);
    }

    return parts.length ? parts.join(' · ') : null;
});

function utilizationColor(pct: number): string {
    return pct >= 80 ? '#a3311f' : pct >= 50 ? '#b4532a' : 'var(--bp-accent)';
}

/** The second line under a plan: its interest, and whether paying early helps. */
function planSub(plan: Plan): string {
    if (plan.interestType === 'none') {
        return `No interest · ${plan.totalMonths} months`;
    }

    const parts = [
        `${interestLabel(plan.interestType)}, ${formatPeso(plan.totalInterest)} interest in total`,
    ];

    if (
        plan.status !== 'paid_off' &&
        plan.interestThisMonth !== null &&
        plan.interestType === 'amortized'
    ) {
        parts.push(
            `this month ${formatPeso(plan.interestThisMonth)} interest and ${formatPeso(plan.principalThisMonth ?? 0)} principal`,
        );
    }

    if (
        plan.status !== 'paid_off' &&
        !plan.earlyPayoffSavesInterest &&
        plan.interestType === 'add_on'
    ) {
        parts.push('paying early may not reduce the interest');
    }

    return parts.join(' · ');
}

const iconButtonClass =
    'rounded-[3px] p-[3px] text-[var(--bp-faint)] hover:bg-[var(--bp-hover)] hover:text-[var(--bp-ink)]';
const headerButtonClass =
    'flex items-center gap-1.5 rounded px-2.5 py-1.5 text-[13px] text-[var(--bp-ink-2)] hover:bg-[var(--bp-hover)]';

const stats = computed(() => {
    const list = [
        { label: 'Still owed', value: formatPeso(props.debt.totalRemaining) },
        {
            label: 'Due this month',
            value: formatPeso(props.debt.dueThisMonth),
        },
        {
            label: 'Paid this month',
            value: formatPeso(props.debt.paidThisMonth),
        },
    ];

    if (card.value) {
        list.push({
            label: 'Projected statement',
            value: formatPeso(card.value.projectedStatement),
        });

        if (card.value.minimumDue !== null) {
            list.push({
                label: 'Minimum due',
                value: formatPeso(card.value.minimumDue),
            });
        }

        list.push({
            label: 'Revolving balance',
            value: formatPeso(card.value.revolvingBalance),
        });
    }

    return list;
});
</script>

<template>
    <Head :title="debt.name" />

    <Teleport defer to="#page-crumb">
        <span class="text-[var(--bp-faint)]">/</span>
        <span class="truncate font-medium text-[var(--bp-ink)]">{{
            debt.name
        }}</span>
    </Teleport>
    <Teleport defer to="#page-actions">
        <div class="bp flex items-center gap-1.5">
            <Link
                :href="index().url"
                :class="headerButtonClass"
                class="md:hidden"
                aria-label="Back to debts"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >arrow_back</span
                >
            </Link>
            <button
                type="button"
                :class="headerButtonClass"
                @click="editingDebt = true"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >edit</span
                >
                <span class="hidden sm:inline">Edit</span>
            </button>
            <button
                type="button"
                :class="headerButtonClass"
                @click="deletingDebt = true"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >delete</span
                >
                <span class="hidden sm:inline">Delete</span>
            </button>
            <button
                type="button"
                class="ml-1.5 flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
                @click="paymentDialog = true"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >add</span
                >
                <span class="hidden sm:inline">Record payment</span>
            </button>
        </div>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            :icon="typeIcons[debt.type] ?? 'credit_card'"
            code="M-02.1"
            :section="typeLabel"
            :title="debt.name"
            :subtitle="subtitle"
            :dimension="dimension"
        >
            <p
                v-if="debt.notes"
                class="mt-1.5 text-[13.5px] text-[var(--bp-faint)] italic"
            >
                {{ debt.notes }}
            </p>
        </BlueprintPageHead>

        <div
            class="grid grid-cols-1 items-start gap-10"
            :class="[
                sheetWidth.wide,
                { 'lg:grid-cols-[minmax(0,1fr)_340px]': showsClearing },
            ]"
        >
            <div class="min-w-0">
                <!-- Figures -->
                <div
                    class="grid grid-cols-2 border border-[var(--bp-ink)] bg-[var(--bp-card)] sm:grid-cols-3"
                >
                    <div
                        v-for="(stat, index) in stats"
                        :key="stat.label"
                        class="border-[var(--bp-ink)] px-[18px] py-3.5"
                        :class="{
                            'max-sm:border-l': index % 2 === 1,
                            'max-sm:border-t': index >= 2,
                            'sm:border-l': index % 3 !== 0,
                            'sm:border-t': index >= 3,
                        }"
                    >
                        <p class="bp-caption">{{ stat.label }}</p>
                        <p
                            class="mt-1 text-[18px] font-semibold tracking-[-0.02em] [overflow-wrap:anywhere] tabular-nums sm:text-[22px]"
                        >
                            {{ stat.value }}
                        </p>
                    </div>
                </div>

                <div v-if="card && card.creditLimit > 0" class="mt-3.5">
                    <div
                        class="flex flex-wrap justify-between gap-x-3 text-[12.5px] text-[var(--bp-muted)]"
                    >
                        <span
                            ><span
                                class="font-semibold"
                                :style="{
                                    color: utilizationColor(utilizationPct),
                                }"
                                >{{ utilizationPct }}%</span
                            >
                            of the limit is in use</span
                        >
                        <span class="bp-mono"
                            >{{
                                formatPeso(
                                    Math.round(
                                        (card.utilization ?? 0) *
                                            card.creditLimit,
                                    ),
                                )
                            }}
                            / {{ formatPeso(card.creditLimit) }}</span
                        >
                    </div>
                    <div
                        class="relative mt-1.5 h-2.5 border border-[var(--bp-ink)] bg-[var(--bp-card)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
                        role="progressbar"
                        :aria-valuenow="utilizationPct"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Limit used"
                    >
                        <div
                            class="h-full"
                            :style="{
                                width: Math.min(utilizationPct, 100) + '%',
                                background: utilizationColor(utilizationPct),
                            }"
                        ></div>
                        <div
                            class="absolute -top-1 -bottom-1 left-1/2 w-px bg-[var(--bp-ink)]"
                        ></div>
                    </div>
                    <div
                        class="bp-mono relative mt-1 h-3 text-[9.5px] text-[var(--bp-faint)]"
                        aria-hidden="true"
                    >
                        <span class="absolute left-0">0</span>
                        <span class="absolute left-1/2 -translate-x-1/2"
                            >50%</span
                        >
                        <span class="absolute right-0">100%</span>
                    </div>
                </div>

                <!-- A. Installments or payment plans -->
                <template v-if="hasPlans">
                    <BlueprintSectionHeading
                        letter="A"
                        :title="card ? 'Installments' : 'Payment plans'"
                        class="mt-11"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded border border-[var(--bp-line)] bg-[var(--bp-card)] px-2.5 py-[5px] text-[13px] font-medium hover:border-[var(--bp-ink)]"
                            @click="addPlan"
                        >
                            <span
                                class="ms"
                                style="font-size: 16px"
                                aria-hidden="true"
                                >add</span
                            >
                            <span class="hidden sm:inline">{{
                                card ? 'Add installment' : 'Add payment plan'
                            }}</span>
                        </button>
                    </BlueprintSectionHeading>

                    <p
                        v-if="plans.length === 0"
                        class="mt-3.5 border-y border-[var(--bp-hair)] py-6 text-center text-[14px] text-[var(--bp-muted)]"
                    >
                        Nothing scheduled yet.
                    </p>
                    <div v-else class="mt-3.5 overflow-x-auto">
                        <div class="min-w-[620px]">
                            <div
                                class="bp-mono grid grid-cols-[minmax(0,1fr)_110px_70px_120px_80px_56px] gap-4 border-b-2 border-[var(--bp-ink)] px-1 pb-2 text-[10px] tracking-[0.12em] text-[var(--bp-muted)]"
                            >
                                <span>WHAT</span>
                                <span class="text-right">PER MONTH</span>
                                <span class="text-right">LEFT</span>
                                <span class="text-right">REMAINING</span>
                                <span>ENDS</span>
                                <span></span>
                            </div>
                            <div
                                v-for="plan in plans"
                                :key="plan.id"
                                class="grid grid-cols-[minmax(0,1fr)_110px_70px_120px_80px_56px] items-start gap-4 border-b border-[var(--bp-hair)] px-1 py-3 text-[13.5px]"
                                :class="{
                                    'opacity-50': plan.status === 'paid_off',
                                }"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">
                                        {{ plan.description }}
                                        <span
                                            v-if="plan.status === 'paid_off'"
                                            class="bp-mono ml-1 rounded-sm border border-[var(--bp-line)] px-1.5 py-px text-[10px] tracking-[0.08em] text-[var(--bp-muted)]"
                                            >PAID OFF</span
                                        >
                                    </p>
                                    <p
                                        class="text-[12px] text-[var(--bp-muted)]"
                                    >
                                        {{ planSub(plan) }}
                                    </p>
                                </div>
                                <span class="bp-mono text-right">{{
                                    formatPeso(plan.monthlyAmount)
                                }}</span>
                                <span class="bp-mono text-right"
                                    >{{ plan.monthsLeft }} of
                                    {{ plan.totalMonths }}</span
                                >
                                <span class="bp-mono text-right">{{
                                    formatPeso(plan.remainingBalance)
                                }}</span>
                                <span class="bp-mono text-[12.5px]">{{
                                    formatMonth(plan.endMonth)
                                }}</span>
                                <div class="flex justify-end gap-0.5">
                                    <button
                                        type="button"
                                        :class="iconButtonClass"
                                        :aria-label="`Edit ${plan.description}`"
                                        @click="editPlan(plan)"
                                    >
                                        <span
                                            class="ms"
                                            style="font-size: 17px"
                                            aria-hidden="true"
                                            >edit</span
                                        >
                                    </button>
                                    <button
                                        type="button"
                                        :class="iconButtonClass"
                                        :aria-label="`Delete ${plan.description}`"
                                        @click="removePlan(plan)"
                                    >
                                        <span
                                            class="ms"
                                            style="font-size: 17px"
                                            aria-hidden="true"
                                            >delete</span
                                        >
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- B. Roll-off -->
                <BlueprintSectionHeading
                    :letter="hasPlans ? 'B' : 'A'"
                    title="Payments rolling off"
                    class="mt-11"
                >
                    <span
                        class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                        aria-hidden="true"
                        >FIG. 1</span
                    >
                </BlueprintSectionHeading>
                <RolloffChart :series="rollOff" class="mt-4" />

                <!-- C. Payment history -->
                <BlueprintSectionHeading
                    :letter="hasPlans ? 'C' : 'B'"
                    title="Payment history"
                    class="mt-11"
                >
                    <span
                        v-if="payments.length"
                        class="bp-mono hidden text-[11px] tracking-[0.1em] text-[var(--bp-faint)] sm:inline"
                        >NEWEST FIRST</span
                    >
                </BlueprintSectionHeading>
                <div class="mt-3.5 border-t border-[var(--bp-ink)]">
                    <p
                        v-if="payments.length === 0"
                        class="border-b border-[var(--bp-hair)] py-6 text-center text-[14px] text-[var(--bp-muted)]"
                    >
                        No payments recorded yet.
                    </p>
                    <div
                        v-for="payment in payments"
                        :key="payment.id"
                        class="grid grid-cols-[64px_minmax(0,1fr)_auto_28px] items-center gap-3 border-b border-[var(--bp-hair)] px-1 py-2.5 text-[13.5px] sm:grid-cols-[80px_minmax(0,1fr)_140px_32px] sm:gap-4"
                    >
                        <span class="bp-mono text-[12.5px]">{{
                            formatDay(payment.date)
                        }}</span>
                        <span
                            class="flex min-w-0 items-center gap-2 text-[var(--bp-ink-2)]"
                        >
                            <span class="truncate">{{
                                planName(payment.paymentPlanId)
                            }}</span>
                            <span
                                v-if="payment.isExtra"
                                class="bp-mono shrink-0 rounded-sm border border-[var(--bp-accent)] px-1.5 py-px text-[10px] tracking-[0.08em] text-[var(--bp-accent)]"
                                >EXTRA</span
                            >
                        </span>
                        <span class="bp-mono text-right font-semibold">{{
                            formatPeso(payment.amount)
                        }}</span>
                        <button
                            type="button"
                            :class="iconButtonClass"
                            class="justify-self-end"
                            :aria-label="`Delete the ${formatDay(payment.date)} payment`"
                            @click="removePayment(payment)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >delete</span
                            >
                        </button>
                    </div>
                </div>

                <!-- D. Statement of account -->
                <template v-if="card">
                    <BlueprintSectionHeading
                        :letter="hasPlans ? 'D' : 'C'"
                        title="Statement of account"
                        class="mt-11"
                    >
                        <span
                            v-if="statements.length"
                            class="bp-mono hidden text-[11px] tracking-[0.1em] text-[var(--bp-faint)] sm:inline"
                            >BY STATEMENT</span
                        >
                    </BlueprintSectionHeading>
                    <div class="mt-3.5 border-t border-[var(--bp-ink)]">
                        <p
                            v-if="statements.length === 0"
                            class="border-b border-[var(--bp-hair)] py-6 text-center text-[14px] text-[var(--bp-muted)]"
                        >
                            Nothing charged to or paid on this card yet.
                        </p>
                        <div
                            v-for="statement in statements"
                            :key="statement.closesOn"
                            class="border-b border-[var(--bp-hair)]"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-1 py-2.5 text-left text-[13.5px]"
                                :aria-expanded="
                                    openStatements.includes(statement.closesOn)
                                "
                                @click="toggleStatement(statement.closesOn)"
                            >
                                <span
                                    class="ms text-[var(--bp-muted)] transition-transform"
                                    :class="{
                                        'rotate-90': openStatements.includes(
                                            statement.closesOn,
                                        ),
                                    }"
                                    style="font-size: 16px"
                                    aria-hidden="true"
                                    >chevron_right</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span class="font-semibold"
                                        >Closes
                                        {{
                                            formatDay(statement.closesOn)
                                        }}</span
                                    >
                                    <span
                                        class="bp-mono ml-2 hidden text-[11px] text-[var(--bp-faint)] sm:inline"
                                        >{{ formatDay(statement.opensOn) }} –
                                        {{
                                            formatDay(statement.closesOn)
                                        }}</span
                                    >
                                </span>
                                <span
                                    class="bp-mono text-right text-[12.5px] tabular-nums"
                                    >{{ formatPeso(statement.charged) }}</span
                                >
                                <span
                                    v-if="statement.paid"
                                    class="bp-mono w-[92px] text-right text-[12.5px] text-[var(--bp-accent)] tabular-nums sm:w-[120px]"
                                    >−{{ formatPeso(statement.paid) }}</span
                                >
                                <span
                                    v-else
                                    class="w-[92px] sm:w-[120px]"
                                    aria-hidden="true"
                                ></span>
                            </button>
                            <ul
                                v-if="
                                    openStatements.includes(statement.closesOn)
                                "
                                class="mb-2 ml-6 text-[12.5px]"
                            >
                                <li
                                    v-for="entry in statement.entries"
                                    :key="`${entry.kind}-${entry.id}`"
                                    class="grid grid-cols-[52px_minmax(0,1fr)_auto] items-baseline gap-3 py-1"
                                >
                                    <span
                                        class="bp-mono text-[var(--bp-muted)]"
                                        >{{ formatDay(entry.date) }}</span
                                    >
                                    <span
                                        class="truncate"
                                        :class="
                                            entry.kind === 'payment'
                                                ? 'text-[var(--bp-accent)]'
                                                : 'text-[var(--bp-ink-2)]'
                                        "
                                        >{{ entryLabel(entry) }}</span
                                    >
                                    <span
                                        class="bp-mono text-right tabular-nums"
                                        :class="{
                                            'text-[var(--bp-accent)]':
                                                entry.kind === 'payment',
                                        }"
                                        >{{ entry.kind === 'payment' ? '−' : ''
                                        }}{{ formatPeso(entry.amount) }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </div>
                </template>
            </div>

            <div
                v-if="showsClearing && card && clearingDefault !== null"
                class="flex flex-col gap-3.5 lg:sticky lg:top-[76px]"
            >
                <ClearingEstimate
                    :debt-id="debt.id"
                    :default-payment="clearingDefault"
                    :revolving-balance="card.revolvingBalance"
                    :monthly-rate="debt.monthlyInterestRate"
                />
                <InfoNote id="debt-card-figures" blueprint>
                    Card figures come from the statement you last entered.
                    Installments are part of the minimum, so they do not pay
                    down the balance that carries over.
                </InfoNote>
            </div>
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock
                :drawing="`Debts / ${debt.name}`"
                sheet="M-02.1"
            />
        </div>

        <DebtFormDialog v-model:open="editingDebt" :debt="debt" />
        <PaymentPlanFormDialog
            v-model:open="planDialog"
            :debt-id="debt.id"
            :is-card="card !== null"
            :debt-interest-type="debt.interestType"
            :plan="editingPlan"
        />
        <PaymentFormDialog
            v-model:open="paymentDialog"
            :debt-id="debt.id"
            :plans="plans"
            :accounts="accounts"
        />

        <Dialog :open="deletingDebt" @update:open="deletingDebt = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ debt.name }}?</DialogTitle>
                    <DialogDescription>
                        Its payment plans and payment history will also be
                        deleted. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <div class="flex justify-end gap-2 pt-2">
                    <Button variant="outline" @click="deletingDebt = false"
                        >Cancel</Button
                    >
                    <Button variant="destructive" @click="removeDebt"
                        >Delete</Button
                    >
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
