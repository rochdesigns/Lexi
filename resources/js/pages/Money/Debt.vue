<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    destroy as destroyDebt,
    index,
} from '@/actions/App/Http/Controllers/Money/DebtController';
import { destroy as destroyPayment } from '@/actions/App/Http/Controllers/Money/DebtPaymentController';
import { destroy as destroyPlan } from '@/actions/App/Http/Controllers/Money/PaymentPlanController';
import ClearingEstimate from '@/components/ClearingEstimate.vue';
import DebtFormDialog from '@/components/DebtFormDialog.vue';
import type { DebtFormDebt } from '@/components/DebtFormDialog.vue';
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
import {
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
const utilizationPct = computed(() =>
    Math.round((card.value?.utilization ?? 0) * 100),
);

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
            label: 'Projected next statement',
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

    <div
        class="mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
    >
        <Link
            :href="index().url"
            class="mb-3 inline-flex items-center gap-1 text-[13px]"
            style="color: var(--hab-muted)"
        >
            <span class="material-symbols-rounded" style="font-size: 16px"
                >arrow_back</span
            >
            Debts
        </Link>

        <div class="mb-6 flex items-end justify-between gap-3">
            <div>
                <h1
                    class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                    style="color: var(--hab-ink)"
                >
                    {{ debt.name }}
                </h1>
                <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                    {{ debt.lender ? debt.lender + ' · ' : ''
                    }}{{ interestLabel(debt.interestType)
                    }}<template v-if="debt.monthlyInterestRate !== null">
                        at {{ debt.monthlyInterestRate }}% a month</template
                    ><template v-if="debt.nextDueDate">
                        · Next due {{ formatDay(debt.nextDueDate) }}</template
                    ><template v-if="card">
                        · Closes {{ formatDay(card.closesOn) }}</template
                    >
                </p>
                <p
                    v-if="debt.notes"
                    class="mt-1 text-[13px]"
                    style="color: var(--hab-faint)"
                >
                    {{ debt.notes }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="editingDebt = true"
                    >Edit</Button
                >
                <Button variant="outline" @click="deletingDebt = true"
                    >Delete</Button
                >
            </div>
        </div>

        <div class="mb-3 grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="rounded-[22px] p-5"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <p class="text-[12px]" style="color: var(--hab-muted)">
                    {{ stat.label }}
                </p>
                <p
                    class="font-['Bricolage_Grotesque'] text-[20px] font-extrabold tabular-nums"
                    style="color: var(--hab-ink)"
                >
                    {{ stat.value }}
                </p>
            </div>
        </div>
        <p
            v-if="card && card.creditLimit > 0"
            class="mb-6 text-[12.5px]"
            style="color: var(--hab-muted)"
        >
            {{ utilizationPct }}% of the {{ formatPeso(card.creditLimit) }}
            limit is in use.
        </p>
        <p
            v-else-if="isInformal && debt.targetPayoffDate"
            class="mb-6 text-[12.5px]"
            style="color: var(--hab-muted)"
        >
            Aiming to clear this by {{ formatDay(debt.targetPayoffDate) }}.
        </p>
        <div v-else class="mb-6"></div>

        <div
            class="mb-6 rounded-[22px] p-6"
            style="
                background: var(--hab-card-bg);
                border: 1px solid var(--hab-card-border);
                box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
            "
        >
            <h2
                class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                style="color: var(--hab-ink)"
            >
                Payments rolling off
            </h2>
            <RolloffChart :series="rollOff" />
        </div>

        <ClearingEstimate
            v-if="card && card.revolvingBalance > 0 && clearingDefault !== null"
            :debt-id="debt.id"
            :default-payment="clearingDefault"
            :revolving-balance="card.revolvingBalance"
            :monthly-rate="debt.monthlyInterestRate"
        />

        <div
            v-if="hasPlans"
            class="mb-6 rounded-[22px] p-6"
            style="
                background: var(--hab-card-bg);
                border: 1px solid var(--hab-card-border);
                box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
            "
        >
            <div class="mb-4 flex items-center justify-between">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    {{ card ? 'Installments' : 'Payment plans' }}
                </h2>
                <Button size="sm" @click="addPlan">{{
                    card ? 'Add installment' : 'Add payment plan'
                }}</Button>
            </div>

            <p
                v-if="plans.length === 0"
                class="py-6 text-center text-[14px]"
                style="color: var(--hab-muted)"
            >
                Nothing scheduled yet.
            </p>

            <div v-else class="overflow-x-auto">
                <table
                    class="w-full text-left text-[13.5px]"
                    style="color: var(--hab-ink)"
                >
                    <thead>
                        <tr class="text-[12px]" style="color: var(--hab-muted)">
                            <th class="pb-2 font-semibold">What</th>
                            <th class="pb-2 text-right font-semibold">
                                Per month
                            </th>
                            <th class="pb-2 text-right font-semibold">Left</th>
                            <th class="pb-2 text-right font-semibold">
                                Remaining
                            </th>
                            <th class="pb-2 font-semibold">Ends</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="plan in plans"
                            :key="plan.id"
                            class="border-t align-top"
                            :class="{
                                'opacity-50': plan.status === 'paid_off',
                            }"
                            style="border-color: var(--hab-hairline)"
                        >
                            <td class="py-2.5">
                                {{ plan.description }}
                                <span
                                    v-if="plan.status === 'paid_off'"
                                    class="ml-1 text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                    >paid off</span
                                >
                                <p
                                    v-else-if="plan.interestType !== 'none'"
                                    class="text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                >
                                    {{ interestLabel(plan.interestType) }},
                                    {{ formatPeso(plan.totalInterest) }}
                                    interest in total
                                    <template
                                        v-if="
                                            plan.interestThisMonth !== null &&
                                            plan.interestType === 'amortized'
                                        "
                                        >, this month
                                        {{ formatPeso(plan.interestThisMonth) }}
                                        interest and
                                        {{
                                            formatPeso(
                                                plan.principalThisMonth ?? 0,
                                            )
                                        }}
                                        principal</template
                                    >
                                </p>
                                <p
                                    v-if="
                                        plan.status !== 'paid_off' &&
                                        !plan.earlyPayoffSavesInterest &&
                                        plan.interestType === 'add_on'
                                    "
                                    class="text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                >
                                    Paying this off early may not reduce the
                                    interest.
                                </p>
                            </td>
                            <td class="py-2.5 text-right tabular-nums">
                                {{ formatPeso(plan.monthlyAmount) }}
                            </td>
                            <td class="py-2.5 text-right tabular-nums">
                                {{ plan.monthsLeft }} of {{ plan.totalMonths }}
                            </td>
                            <td class="py-2.5 text-right tabular-nums">
                                {{ formatPeso(plan.remainingBalance) }}
                            </td>
                            <td class="py-2.5">
                                {{ formatMonth(plan.endMonth) }}
                            </td>
                            <td class="py-2.5 text-right whitespace-nowrap">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="editPlan(plan)"
                                    >Edit</Button
                                >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="removePlan(plan)"
                                    >Delete</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            class="rounded-[22px] p-6"
            style="
                background: var(--hab-card-bg);
                border: 1px solid var(--hab-card-border);
                box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
            "
        >
            <div class="mb-4 flex items-center justify-between">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Payment history
                </h2>
                <Button size="sm" @click="paymentDialog = true"
                    >Record payment</Button
                >
            </div>

            <p
                v-if="payments.length === 0"
                class="py-6 text-center text-[14px]"
                style="color: var(--hab-muted)"
            >
                No payments recorded yet.
            </p>

            <table
                v-else
                class="w-full text-left text-[13.5px]"
                style="color: var(--hab-ink)"
            >
                <tbody>
                    <tr
                        v-for="payment in payments"
                        :key="payment.id"
                        class="border-t first:border-t-0"
                        style="border-color: var(--hab-hairline)"
                    >
                        <td class="py-2.5">{{ formatDay(payment.date) }}</td>
                        <td class="py-2.5" style="color: var(--hab-muted)">
                            {{ planName(payment.paymentPlanId)
                            }}<span v-if="payment.isExtra"> · extra</span>
                        </td>
                        <td class="py-2.5 text-right tabular-nums">
                            {{ formatPeso(payment.amount) }}
                        </td>
                        <td class="py-2.5 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="removePayment(payment)"
                                >Delete</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
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
