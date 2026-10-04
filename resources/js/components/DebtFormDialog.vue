<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Money/DebtController';
import FieldLabel from '@/components/FieldLabel.vue';
import InterestTypePicker from '@/components/InterestTypePicker.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { debtTypes, formatPeso, toPesos } from '@/utils/money';
import type { InterestValue } from '@/utils/money';

export type DebtFormDebt = {
    id: number;
    name: string;
    type: string;
    lender: string | null;
    interestType: string;
    monthlyInterestRate: number | null;
    dueDay: number | null;
    status: string;
    notes: string | null;
    outstandingBalance: number | null;
    targetPayoffDate: string | null;
    card: {
        creditLimit: number;
        statementDay: number;
        dueDaysAfterStatement: number | null;
        chargesDueInFull: number;
        minPaymentPercent: number | null;
        minPaymentFloor: number | null;
        revolvingBalance: number;
    } | null;
};

type InstallmentRow = {
    description: string;
    mode: 'monthly' | 'total';
    monthly_amount: number | null;
    total_amount: number | null;
    principal: number | null;
    start_month: string;
    end_month: string;
    in_minimum: boolean;
};

const props = defineProps<{ open: boolean; debt?: DebtFormDebt | null }>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function blank() {
    return {
        name: '',
        type: 'credit_card',
        lender: '',
        interest_type: 'revolving',
        monthly_interest_rate: null as number | null,
        due_day: null as number | null,
        status: 'active',
        notes: '',
        outstanding_balance: null as number | null,
        target_payoff_date: '',
        credit_limit: null as number | null,
        statement_day: null as number | null,
        due_mode: 'after_cutoff' as 'after_cutoff' | 'fixed',
        due_days_after_statement: null as number | null,
        min_payment_percent: null as number | null,
        min_payment_floor: null as number | null,
        revolving_balance: 0 as number | null,
        charges_due_in_full: 0 as number | null,
        total_due: null as number | null,
        installments: [] as InstallmentRow[],
        plan: {
            principal: null as number | null,
            monthly_amount: null as number | null,
            total_months: 12 as number | null,
            start_month: currentMonth(),
        },
    };
}

const form = useForm(blank());

const isCard = computed(() => form.type === 'credit_card');
const isInformal = computed(() => form.type === 'informal');
/** New loans and BNPL take their first plan here, so they never sit at zero. */
const needsFirstPlan = computed(
    () => !props.debt && ['loan', 'bnpl'].includes(form.type),
);
/** A new card takes the statement total plus its installments in one go. */
const isNewCard = computed(() => !props.debt && isCard.value);

/** The month of the card's latest cut-off, which is the statement the total due is for. */
const nextStatementMonth = computed(() => {
    const day = form.statement_day;
    const now = new Date();
    const closedThisMonth = day !== null && now.getDate() > day;
    const month = new Date(
        now.getFullYear(),
        now.getMonth() - (closedThisMonth ? 0 : 1),
        1,
    );

    return `${month.getFullYear()}-${String(month.getMonth() + 1).padStart(2, '0')}`;
});

/** Only installments running on that statement come off the total due. */
const installmentsTotal = computed(() =>
    form.installments
        .filter(
            (row) =>
                row.start_month !== '' &&
                row.end_month !== '' &&
                row.start_month <= nextStatementMonth.value &&
                row.end_month >= nextStatementMonth.value,
        )
        .reduce((sum, row) => sum + installmentMonthly(row), 0),
);
const everythingElse = computed(
    () => (form.total_due ?? 0) - installmentsTotal.value,
);

function installmentMonths(row: InstallmentRow): number {
    if (!row.start_month || !row.end_month) {
        return 0;
    }

    const [startYear, startMonth] = row.start_month.split('-').map(Number);
    const [endYear, endMonth] = row.end_month.split('-').map(Number);

    return (endYear - startYear) * 12 + endMonth - startMonth + 1;
}

/** What a row adds to each statement, in pesos, whichever way it was entered. */
function installmentMonthly(row: InstallmentRow): number {
    if (row.mode === 'monthly') {
        return row.monthly_amount ?? 0;
    }

    const months = installmentMonths(row);

    return months < 1 || !row.total_amount ? 0 : row.total_amount / months;
}

/** Spells out what a row adds up to, so a wrong amount is easy to spot. */
function installmentSummary(row: InstallmentRow): string {
    const months = installmentMonths(row);
    const monthly = installmentMonthly(row);

    if (months < 1 || monthly <= 0) {
        return '';
    }

    const total =
        row.mode === 'total' ? (row.total_amount ?? 0) : monthly * months;
    const count = `${months} ${months === 1 ? 'payment' : 'payments'}`;
    const interest = row.principal !== null ? total - row.principal : 0;
    const interestNote =
        interest > 0
            ? `, ${formatPeso(Math.round(interest * 100))} of it interest`
            : '';

    return `${count} of ${formatPeso(Math.round(monthly * 100))}, ${formatPeso(Math.round(total * 100))} in total${interestNote}.`;
}

function addInstallment(): void {
    form.installments.push({
        description: '',
        mode: 'monthly',
        monthly_amount: null,
        total_amount: null,
        principal: null,
        start_month: currentMonth(),
        end_month: '',
        in_minimum: false,
    });
}

const isAddOn = computed(() => form.interest_type === 'add_on');
const isAmortized = computed(() => form.interest_type === 'amortized');
const needsRate = computed(() =>
    ['amortized', 'revolving'].includes(form.interest_type),
);

/** Each type has a sensible default interest, so most people never touch the field. */
const defaultInterest: Record<string, string> = {
    credit_card: 'revolving',
    loan: 'amortized',
    bnpl: 'none',
    informal: 'none',
};

/** Which interest options make sense for each kind of debt. */
const interestOptions: Record<string, InterestValue[]> = {
    credit_card: ['revolving', 'none'],
    loan: ['amortized', 'add_on', 'none'],
    bnpl: ['none', 'add_on', 'amortized'],
    informal: ['none'],
};

function onTypeChange(): void {
    form.interest_type = defaultInterest[form.type];
}

watch(
    () => [props.open, props.debt] as const,
    ([open, debt]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults(
            debt
                ? {
                      name: debt.name,
                      type: debt.type,
                      lender: debt.lender ?? '',
                      interest_type: debt.interestType,
                      monthly_interest_rate: debt.monthlyInterestRate,
                      due_day: debt.dueDay,
                      status: debt.status,
                      notes: debt.notes ?? '',
                      outstanding_balance:
                          debt.outstandingBalance === null
                              ? null
                              : toPesos(debt.outstandingBalance),
                      target_payoff_date: debt.targetPayoffDate ?? '',
                      credit_limit: debt.card
                          ? toPesos(debt.card.creditLimit)
                          : null,
                      statement_day: debt.card?.statementDay ?? null,
                      due_mode:
                          debt.card && debt.card.dueDaysAfterStatement === null
                              ? 'fixed'
                              : 'after_cutoff',
                      due_days_after_statement:
                          debt.card?.dueDaysAfterStatement ?? null,
                      min_payment_percent: debt.card?.minPaymentPercent ?? null,
                      min_payment_floor:
                          debt.card?.minPaymentFloor == null
                              ? null
                              : toPesos(debt.card.minPaymentFloor),
                      revolving_balance: debt.card
                          ? toPesos(debt.card.revolvingBalance)
                          : 0,
                      charges_due_in_full: debt.card
                          ? toPesos(debt.card.chargesDueInFull)
                          : 0,
                  }
                : blank(),
        );
        form.reset();
    },
    { immediate: true },
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    form.transform((data) => {
        const unused = new Set<string>();

        if (!needsFirstPlan.value) {
            unused.add('plan');
        }

        unused.add('due_mode');

        if (isCard.value) {
            unused.add(
                form.due_mode === 'after_cutoff'
                    ? 'due_day'
                    : 'due_days_after_statement',
            );
        } else {
            unused.add('due_days_after_statement');
        }

        if (isNewCard.value) {
            unused.add('revolving_balance');
        } else {
            unused.add('total_due');
            unused.add('installments');
        }

        const kept = Object.fromEntries(
            Object.entries(data).filter(([key]) => !unused.has(key)),
        ) as typeof data;

        if (isNewCard.value) {
            kept.installments = kept.installments.map((row) => {
                const { mode, monthly_amount, total_amount, ...rest } = row;

                return {
                    ...rest,
                    ...(mode === 'monthly'
                        ? { monthly_amount }
                        : { total_amount }),
                };
            }) as typeof kept.installments;
        }

        return kept;
    });

    if (props.debt) {
        form.patch(update(props.debt.id).url, options);
    } else {
        form.post(store().url, options);
    }
}

const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
const errorClass = 'mt-1 text-[12px] text-red-600';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            style="max-width: 540px; max-height: 90vh; overflow-y: auto"
        >
            <DialogHeader>
                <DialogTitle>{{
                    debt ? 'Edit debt' : 'Add a debt'
                }}</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <FieldLabel
                            hint="A name you will recognise, such as the card or the thing you bought."
                            >Name</FieldLabel
                        >
                        <input
                            v-model="form.name"
                            :class="fieldClass"
                            placeholder="Platinum"
                        />
                        <p v-if="form.errors.name" :class="errorClass">
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div>
                        <FieldLabel
                            hint="Cards revolve a balance, loans and BNPL follow a payment schedule, and informal is money owed to a person."
                            >Type</FieldLabel
                        >
                        <select
                            v-model="form.type"
                            :class="fieldClass"
                            @change="onTypeChange"
                        >
                            <option
                                v-for="t in debtTypes"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.single }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <FieldLabel
                            :hint="
                                isInformal
                                    ? 'The person you borrowed from.'
                                    : 'The bank or company you owe.'
                            "
                            >{{ isInformal ? 'Who' : 'Lender' }}</FieldLabel
                        >
                        <input
                            v-model="form.lender"
                            :class="fieldClass"
                            :placeholder="isInformal ? 'Ate Mia' : 'BDO'"
                        />
                    </div>
                    <div v-if="!isInformal">
                        <template v-if="isCard">
                            <FieldLabel
                                hint="Most cards set the due date a set number of days after your statement closes, often around 20. Use a fixed day if your bank always gives the same date."
                                >Due date</FieldLabel
                            >
                            <select
                                v-model="form.due_mode"
                                :class="[fieldClass, 'mb-2']"
                                aria-label="How the due date is set"
                            >
                                <option value="after_cutoff">
                                    Days after cut-off
                                </option>
                                <option value="fixed">
                                    Fixed day of the month
                                </option>
                            </select>
                        </template>
                        <FieldLabel
                            v-else
                            hint="The day each month your payment is due. If a month is shorter, the last day is used."
                            >Due day of the month</FieldLabel
                        >
                        <MoneyInput
                            v-if="isCard && form.due_mode === 'after_cutoff'"
                            v-model="form.due_days_after_statement"
                            unit=""
                            adorn
                            suffix="days after cut-off"
                            :empty-value="null"
                            :class="fieldClass"
                        />
                        <MoneyInput
                            v-else
                            v-model="form.due_day"
                            unit=""
                            adorn
                            suffix="of the month"
                            :empty-value="null"
                            :class="fieldClass"
                        />
                        <p
                            v-if="form.errors.due_days_after_statement"
                            :class="errorClass"
                        >
                            {{ form.errors.due_days_after_statement }}
                        </p>
                        <p v-if="form.errors.due_day" :class="errorClass">
                            {{ form.errors.due_day }}
                        </p>
                    </div>
                </div>

                <div v-if="!isInformal">
                    <FieldLabel
                        hint="Pick how this debt grows. If you are not sure, the default for the type is usually right."
                        >How is interest charged?</FieldLabel
                    >
                    <InterestTypePicker
                        v-model="form.interest_type"
                        :options="interestOptions[form.type]"
                    />
                </div>

                <div v-if="!isInformal && needsRate">
                    <FieldLabel
                        hint="A monthly rate, not yearly. If your contract says per year, divide it by 12."
                        >Interest per month</FieldLabel
                    >
                    <MoneyInput
                        adorn
                        v-model="form.monthly_interest_rate"
                        unit="%"
                        :empty-value="null"
                        :class="fieldClass"
                    />
                    <p
                        v-if="form.errors.monthly_interest_rate"
                        :class="errorClass"
                    >
                        {{ form.errors.monthly_interest_rate }}
                    </p>
                    <p class="mt-1 text-[12px]" style="color: var(--hab-muted)">
                        A monthly rate, not yearly. If your contract says per
                        year, divide it by 12.
                    </p>
                </div>

                <template v-if="isCard">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <FieldLabel
                                hint="The most the bank lets you charge to this card."
                                >Credit limit</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.credit_limit"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors.credit_limit"
                                :class="errorClass"
                            >
                                {{ form.errors.credit_limit }}
                            </p>
                        </div>
                        <div v-if="isNewCard">
                            <FieldLabel
                                hint="The amount due on your latest statement. Add installments below and they are taken out of this."
                                >Total due</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.total_due"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                            <p v-if="form.errors.total_due" :class="errorClass">
                                {{ form.errors.total_due }}
                            </p>
                            <p
                                v-if="form.errors.revolving_balance"
                                :class="errorClass"
                            >
                                {{ form.errors.revolving_balance }}
                            </p>
                        </div>
                        <div v-else>
                            <FieldLabel
                                hint="What you owe on the card right now, not counting installments you add separately."
                                >Revolving balance</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.revolving_balance"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors.revolving_balance"
                                :class="errorClass"
                            >
                                {{ form.errors.revolving_balance }}
                            </p>
                        </div>
                    </div>
                    <div>
                        <FieldLabel
                            hint="The finance charge, late fee and overlimit fee on your statement. Banks make you pay these in full as part of the minimum, so they are added to it as they are and left out of the percent. They stay part of the balance above."
                            >Finance and late charges (this
                            statement)</FieldLabel
                        >
                        <MoneyInput
                            v-model="form.charges_due_in_full"
                            adorn
                            :class="fieldClass"
                        />
                        <p
                            v-if="form.errors.charges_due_in_full"
                            :class="errorClass"
                        >
                            {{ form.errors.charges_due_in_full }}
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <FieldLabel
                                hint="The day your billing cycle closes, also called the cut-off or statement date. Purchases after it go on the next statement. It is printed on your statement."
                                >Cut-off day (statement closes)</FieldLabel
                            >
                            <MoneyInput
                                v-model="form.statement_day"
                                unit=""
                                adorn
                                suffix="of the month"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors.statement_day"
                                :class="errorClass"
                            >
                                {{ form.errors.statement_day }}
                            </p>
                        </div>
                        <div>
                            <FieldLabel
                                hint="The percent of your balance the bank adds to the minimum each month, often 1% to 5%. It is not your interest rate. Look under Minimum Amount Due in your statement's fine print."
                                >Minimum payment (% of balance)</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.min_payment_percent"
                                unit="%"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                        </div>
                        <div>
                            <FieldLabel
                                hint="The minimum is never lower than this, however small your balance is, for example ₱500."
                                >Lowest minimum payment</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.min_payment_floor"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                        </div>
                    </div>

                    <div v-if="isNewCard" class="flex flex-col gap-2">
                        <FieldLabel
                            hint="Optional. Add each purchase you are paying in monthly installments. They count separately from the revolving balance."
                            >Installments (optional)</FieldLabel
                        >
                        <div
                            v-for="(row, index) in form.installments"
                            :key="index"
                            class="grid grid-cols-[1fr_8rem_auto] items-start gap-2 rounded-[11px] border p-2.5"
                            style="border-color: var(--hab-card-border)"
                        >
                            <div>
                                <FieldLabel
                                    hint="Anything that helps you recognise it, such as the item you bought."
                                    >What for</FieldLabel
                                >
                                <input
                                    v-model="row.description"
                                    :class="fieldClass"
                                    placeholder="Laptop"
                                    aria-label="What the installment is for"
                                />
                                <p
                                    v-if="
                                        form.errors[
                                            `installments.${index}.description`
                                        ]
                                    "
                                    :class="errorClass"
                                >
                                    {{
                                        form.errors[
                                            `installments.${index}.description`
                                        ]
                                    }}
                                </p>
                            </div>
                            <div>
                                <FieldLabel
                                    :hint="
                                        row.mode === 'monthly'
                                            ? 'What this installment adds to your statement each month, not the full price. The full amount is worked out from this and the months.'
                                            : 'The full amount you are paying off in installments. The monthly amount is worked out from this and the months.'
                                    "
                                    >{{
                                        row.mode === 'monthly'
                                            ? 'Per month'
                                            : 'Total amount'
                                    }}</FieldLabel
                                >
                                <MoneyInput
                                    v-if="row.mode === 'monthly'"
                                    v-model="row.monthly_amount"
                                    adorn
                                    :empty-value="null"
                                    :class="fieldClass"
                                    aria-label="Per month"
                                />
                                <MoneyInput
                                    v-else
                                    v-model="row.total_amount"
                                    adorn
                                    :empty-value="null"
                                    :class="fieldClass"
                                    aria-label="Total amount"
                                />
                                <button
                                    type="button"
                                    class="mt-1 text-[12px] underline"
                                    style="color: var(--hab-muted)"
                                    @click="
                                        row.mode =
                                            row.mode === 'monthly'
                                                ? 'total'
                                                : 'monthly'
                                    "
                                >
                                    {{
                                        row.mode === 'monthly'
                                            ? 'I know the total instead'
                                            : 'I know the monthly amount'
                                    }}
                                </button>
                                <p
                                    v-if="
                                        form.errors[
                                            `installments.${index}.monthly_amount`
                                        ]
                                    "
                                    :class="errorClass"
                                >
                                    {{
                                        form.errors[
                                            `installments.${index}.monthly_amount`
                                        ]
                                    }}
                                </p>
                                <p
                                    v-if="
                                        form.errors[
                                            `installments.${index}.total_amount`
                                        ]
                                    "
                                    :class="errorClass"
                                >
                                    {{
                                        form.errors[
                                            `installments.${index}.total_amount`
                                        ]
                                    }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="mt-5"
                                aria-label="Remove installment"
                                @click="form.installments.splice(index, 1)"
                                >✕</Button
                            >
                            <div class="col-span-full grid grid-cols-2 gap-2">
                                <div>
                                    <FieldLabel
                                        hint="The month of the first payment. Pick a past month if you are already partway through."
                                        >Starts</FieldLabel
                                    >
                                    <input
                                        v-model="row.start_month"
                                        type="month"
                                        :class="fieldClass"
                                    />
                                    <p
                                        v-if="
                                            form.errors[
                                                `installments.${index}.start_month`
                                            ]
                                        "
                                        :class="errorClass"
                                    >
                                        {{
                                            form.errors[
                                                `installments.${index}.start_month`
                                            ]
                                        }}
                                    </p>
                                </div>
                                <div>
                                    <FieldLabel
                                        hint="The month of the last payment. The number of payments is counted from start to end."
                                        >Ends</FieldLabel
                                    >
                                    <input
                                        v-model="row.end_month"
                                        type="month"
                                        :min="row.start_month"
                                        :class="fieldClass"
                                    />
                                    <p
                                        v-if="
                                            form.errors[
                                                `installments.${index}.end_month`
                                            ]
                                        "
                                        :class="errorClass"
                                    >
                                        {{
                                            form.errors[
                                                `installments.${index}.end_month`
                                            ]
                                        }}
                                    </p>
                                </div>
                            </div>
                            <div class="col-span-full">
                                <FieldLabel
                                    hint="Only if the installment has interest: what you borrowed or the price before interest, as shown on your conversion offer. The bank's monthly payment already includes the interest, so the difference is worked out as interest. Leave empty for 0% installments."
                                    >Amount borrowed (if it has
                                    interest)</FieldLabel
                                >
                                <MoneyInput
                                    v-model="row.principal"
                                    adorn
                                    :empty-value="null"
                                    :class="fieldClass"
                                />
                                <p
                                    v-if="
                                        form.errors[
                                            `installments.${index}.principal`
                                        ]
                                    "
                                    :class="errorClass"
                                >
                                    {{
                                        form.errors[
                                            `installments.${index}.principal`
                                        ]
                                    }}
                                </p>
                            </div>
                            <p
                                v-if="installmentSummary(row)"
                                class="col-span-full text-[12px] tabular-nums"
                                style="color: var(--hab-muted)"
                            >
                                {{ installmentSummary(row) }}
                            </p>
                            <label
                                class="col-span-full flex items-center gap-2 text-[12.5px]"
                                style="color: var(--hab-ink-secondary)"
                            >
                                <input
                                    v-model="row.in_minimum"
                                    type="checkbox"
                                />
                                Must be paid with the minimum due
                            </label>
                        </div>
                        <p
                            v-if="form.installments.length > 0"
                            class="text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            Only installments running on your next statement are
                            taken out of the total due.
                            <template v-if="form.total_due !== null">
                                The rest,
                                {{
                                    formatPeso(
                                        Math.round(everythingElse * 100),
                                    )
                                }}, is counted as revolving balance.
                            </template>
                        </p>
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addInstallment"
                                >+ Add an installment</Button
                            >
                        </div>
                    </div>
                </template>

                <template v-if="needsFirstPlan">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <FieldLabel
                                :hint="
                                    isAddOn
                                        ? 'The amount you received, before the add-on interest is added.'
                                        : 'The total you owe, before interest if it is charged.'
                                "
                                >{{
                                    isAddOn ? 'Amount borrowed' : 'Amount'
                                }}</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.plan.principal"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors['plan.principal']"
                                :class="errorClass"
                            >
                                {{ form.errors['plan.principal'] }}
                            </p>
                        </div>
                        <div>
                            <FieldLabel
                                hint="How many monthly payments it takes to finish paying this off."
                                >Months</FieldLabel
                            >
                            <MoneyInput
                                v-model="form.plan.total_months"
                                unit=""
                                adorn
                                suffix="months"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors['plan.total_months']"
                                :class="errorClass"
                            >
                                {{ form.errors['plan.total_months'] }}
                            </p>
                        </div>
                        <div>
                            <FieldLabel
                                hint="What you pay each month. For an amortized loan you can leave it empty and it is worked out for you."
                                >Per month</FieldLabel
                            >
                            <MoneyInput
                                adorn
                                v-model="form.plan.monthly_amount"
                                :empty-value="null"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors['plan.monthly_amount']"
                                :class="errorClass"
                            >
                                {{ form.errors['plan.monthly_amount'] }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="isAmortized"
                        class="-mt-2 text-[12px]"
                        style="color: var(--hab-muted)"
                    >
                        Leave per month empty to work it out from the amount,
                        rate and months.
                    </p>
                    <div>
                        <FieldLabel
                            hint="The month your first payment falls due. Pick a past month if you have already started paying."
                            >First payment</FieldLabel
                        >
                        <input
                            v-model="form.plan.start_month"
                            type="month"
                            :class="fieldClass"
                        />
                        <p
                            v-if="form.errors['plan.start_month']"
                            :class="errorClass"
                        >
                            {{ form.errors['plan.start_month'] }}
                        </p>
                    </div>
                </template>

                <p
                    v-if="debt && !isInformal"
                    class="text-[12.5px]"
                    style="color: var(--hab-muted)"
                >
                    {{
                        isCard
                            ? 'Installments are managed on'
                            : 'The amount borrowed, term, monthly amount and start month are set on'
                    }}
                    the payment plan. Edit them from this debt's page.
                </p>

                <div v-if="isInformal" class="grid grid-cols-2 gap-3">
                    <div>
                        <FieldLabel hint="How much you still have to pay back."
                            >Still owed</FieldLabel
                        >
                        <MoneyInput
                            adorn
                            v-model="form.outstanding_balance"
                            :empty-value="null"
                            :class="fieldClass"
                        />
                        <p
                            v-if="form.errors.outstanding_balance"
                            :class="errorClass"
                        >
                            {{ form.errors.outstanding_balance }}
                        </p>
                    </div>
                    <div>
                        <FieldLabel
                            hint="A date you would like to be done by. It helps plan, and nothing is enforced."
                            >Pay off by (optional)</FieldLabel
                        >
                        <input
                            v-model="form.target_payoff_date"
                            type="date"
                            :class="fieldClass"
                        />
                    </div>
                </div>

                <div>
                    <FieldLabel
                        hint="Anything worth remembering, such as an account number or an agreement."
                        >Notes</FieldLabel
                    >
                    <textarea
                        v-model="form.notes"
                        rows="2"
                        :class="fieldClass"
                    ></textarea>
                </div>

                <label
                    v-if="debt"
                    class="flex items-center gap-2 text-[13.5px]"
                    style="color: var(--hab-ink)"
                >
                    <input
                        type="checkbox"
                        :checked="form.status === 'paid_off'"
                        @change="
                            form.status = ($event.target as HTMLInputElement)
                                .checked
                                ? 'paid_off'
                                : 'active'
                        "
                    />
                    Fully paid off
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        debt ? 'Save' : 'Add debt'
                    }}</Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
