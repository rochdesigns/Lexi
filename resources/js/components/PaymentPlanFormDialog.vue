<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    preview as previewPlan,
    store,
    update,
} from '@/actions/App/Http/Controllers/Money/PaymentPlanController';
import InterestTypePicker from '@/components/InterestTypePicker.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formError, formField, formLabel } from '@/utils/blueprint';
import { formatPeso, interestTypes, toPesos } from '@/utils/money';

export type PaymentPlanItem = {
    id: number;
    description: string;
    principal: number;
    monthlyAmount: number;
    totalMonths: number;
    startMonth: string;
    ownInterestType: string | null;
    inMinimum: boolean;
    status: string;
};

const props = defineProps<{
    open: boolean;
    debtId: number;
    isCard: boolean;
    debtInterestType: string;
    plan?: PaymentPlanItem | null;
}>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function blank() {
    return {
        description: '',
        principal: null as number | null,
        monthly_amount: null as number | null,
        total_months: 12 as number | null,
        start_month: currentMonth(),
        interest_type: '' as string,
        in_minimum: false,
        status: 'active',
    };
}

const form = useForm(blank());

type PlanSummary = {
    interestType: string;
    monthlyAmount: number;
    finalPayment: number;
    totalRepayment: number;
    totalInterest: number;
    earlyPayoffSavesInterest: boolean;
    repaysLessThanBorrowed: boolean;
};
type Preview = { summary: PlanSummary | null; missing: string | null };

const preview = ref<Preview | null>(null);
const previewHttp = useHttp<
    {
        principal: number | null;
        monthly_amount: number | null;
        total_months: number | null;
        interest_type: string | null;
    },
    Preview
>({
    principal: null,
    monthly_amount: null,
    total_months: null,
    interest_type: null,
});
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewRun = 0;

/** Asks the server what this plan would cost. The math stays on the server. */
function refreshPreview(): void {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => {
        const run = ++previewRun;

        previewHttp.principal = form.principal;
        previewHttp.monthly_amount = form.monthly_amount;
        previewHttp.total_months = form.total_months;
        previewHttp.interest_type = form.interest_type || null;

        previewHttp.post(previewPlan(props.debtId).url, {
            onSuccess: (response) => {
                if (run === previewRun) {
                    preview.value = response;
                }
            },
            onError: () => {
                if (run === previewRun) {
                    preview.value = null;
                }
            },
        });
    }, 300);
}

watch(
    () => [
        props.open,
        form.principal,
        form.monthly_amount,
        form.total_months,
        form.interest_type,
    ],
    () => {
        if (props.open) {
            refreshPreview();
        }
    },
);

onBeforeUnmount(() => clearTimeout(previewTimer));

const debtInterestLabel = computed(
    () =>
        interestTypes.find((type) => type.value === props.debtInterestType)
            ?.short ?? '',
);

const inheritLabel = computed(() =>
    props.debtInterestType === 'revolving'
        ? 'No interest (usual for card installments)'
        : `Same as the debt (${debtInterestLabel.value})`,
);

const inheritDescription = computed(
    () =>
        interestTypes.find(
            (type) =>
                type.value ===
                (props.debtInterestType === 'revolving'
                    ? 'none'
                    : props.debtInterestType),
        )?.description ?? '',
);

const missingMessage = computed(() => {
    switch (preview.value?.missing) {
        case 'months':
            return 'Enter the number of months to see what this costs.';
        case 'monthly':
            return 'Enter the monthly amount to see what this costs.';
        case 'principal':
            return isAddOn.value
                ? 'Enter the amount borrowed to see what this costs.'
                : 'Enter the principal to see what this costs.';
        case 'rate':
            return 'Set a monthly interest rate on the debt to see what this costs.';
        default:
            return null;
    }
});

/** Plain-language lines describing the previewed plan. */
const previewLines = computed(() => {
    const summary = preview.value?.summary;

    if (!summary) {
        return [];
    }

    const total = formatPeso(summary.totalRepayment);
    const interest = formatPeso(summary.totalInterest);
    const lines: string[] = [];

    if (summary.interestType === 'amortized') {
        lines.push(
            `About ${formatPeso(summary.monthlyAmount)} a month, ${total} in total. ${interest} of that is interest.`,
            'Paying ahead reduces the interest.',
        );

        if (summary.finalPayment > summary.monthlyAmount + 100) {
            lines.push(
                `Your last payment would be ${formatPeso(summary.finalPayment)}, because the monthly amount does not quite clear it.`,
            );
        }
    } else if (summary.interestType === 'add_on') {
        lines.push(
            `You will pay ${total} in total. ${interest} of that is interest, set upfront.`,
            'Paying this off early will not reduce it.',
        );

        if (summary.repaysLessThanBorrowed) {
            lines.push(
                'These payments add up to less than the amount borrowed. Check the numbers.',
            );
        }
    } else {
        lines.push(`You will pay ${total} in total, with no interest.`);
    }

    return lines;
});

function monthNumber(value: string): number {
    const [year, month] = value.split('-').map(Number);

    return year * 12 + month - 1;
}

/** The last payment's month, kept in step with the first payment and the months. */
const endMonth = computed({
    get(): string {
        if (!form.start_month || !form.total_months) {
            return '';
        }

        const last = monthNumber(form.start_month) + form.total_months - 1;

        return `${Math.floor(last / 12)}-${String((last % 12) + 1).padStart(2, '0')}`;
    },
    set(value: string) {
        if (!value || !form.start_month) {
            return;
        }

        const months = monthNumber(value) - monthNumber(form.start_month) + 1;

        if (months >= 1) {
            form.total_months = months;
        }
    },
});

const effectiveType = computed(
    () => form.interest_type || props.debtInterestType,
);
const isAmortized = computed(() => effectiveType.value === 'amortized');
const isAddOn = computed(() => effectiveType.value === 'add_on');

watch(
    () => [props.open, props.plan] as const,
    ([open, plan]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults(
            plan
                ? {
                      description: plan.description,
                      principal: toPesos(plan.principal),
                      monthly_amount: toPesos(plan.monthlyAmount),
                      total_months: plan.totalMonths,
                      start_month: plan.startMonth,
                      interest_type: plan.ownInterestType ?? '',
                      in_minimum: plan.inMinimum,
                      status: plan.status,
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

    form.transform((data) => ({
        ...data,
        interest_type: data.interest_type || null,
    }));

    if (props.plan) {
        form.patch(update(props.plan.id).url, options);
    } else {
        form.post(store(props.debtId).url, options);
    }
}

const fieldClass = formField;
const labelClass = formLabel;
const errorClass = formError;
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent style="max-width: 500px">
            <DialogHeader>
                <DialogTitle>{{
                    plan
                        ? 'Edit payment plan'
                        : isCard
                          ? 'Add an installment'
                          : 'Add a payment plan'
                }}</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div>
                    <label :class="labelClass">What is it for</label>
                    <input
                        v-model="form.description"
                        :class="fieldClass"
                        placeholder="Laptop"
                    />
                    <p v-if="form.errors.description" :class="errorClass">
                        {{ form.errors.description }}
                    </p>
                </div>

                <div>
                    <label :class="labelClass"
                        >How is interest charged on this plan?</label
                    >
                    <InterestTypePicker
                        v-model="form.interest_type"
                        :options="['none', 'amortized', 'add_on']"
                        :inherit-label="inheritLabel"
                        :inherit-description="inheritDescription"
                    />
                    <p v-if="form.errors.interest_type" :class="errorClass">
                        {{ form.errors.interest_type }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label :class="labelClass">First payment</label>
                        <input
                            v-model="form.start_month"
                            type="month"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.start_month" :class="errorClass">
                            {{ form.errors.start_month }}
                        </p>
                    </div>
                    <div>
                        <label :class="labelClass">Last payment</label>
                        <input
                            v-model="endMonth"
                            type="month"
                            :min="form.start_month"
                            :class="fieldClass"
                        />
                        <p
                            class="mt-1 text-[12px]"
                            style="color: var(--bp-muted)"
                        >
                            Changing this changes the months.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label :class="labelClass">{{
                            isAddOn ? 'Amount borrowed' : 'Principal'
                        }}</label>
                        <MoneyInput
                            v-model="form.principal"
                            :empty-value="null"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.principal" :class="errorClass">
                            {{ form.errors.principal }}
                        </p>
                    </div>
                    <div>
                        <label :class="labelClass">Months</label>
                        <MoneyInput
                            v-model="form.total_months"
                            unit=""
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.total_months" :class="errorClass">
                            {{ form.errors.total_months }}
                        </p>
                    </div>
                    <div>
                        <label :class="labelClass">Per month</label>
                        <MoneyInput
                            v-model="form.monthly_amount"
                            :empty-value="null"
                            :class="fieldClass"
                        />
                        <p
                            v-if="form.errors.monthly_amount"
                            :class="errorClass"
                        >
                            {{ form.errors.monthly_amount }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="isAmortized"
                    class="-mt-2 text-[12px]"
                    style="color: var(--bp-muted)"
                >
                    Leave per month empty to work it out from the principal,
                    rate and months.
                </p>

                <div
                    class="rounded-[3px] px-3 py-2.5 text-[13px]"
                    style="background: var(--bp-paper); color: var(--bp-ink-2)"
                    aria-live="polite"
                >
                    <template v-if="previewLines.length > 0">
                        <p
                            v-for="line in previewLines"
                            :key="line"
                            class="tabular-nums"
                        >
                            {{ line }}
                        </p>
                    </template>
                    <p v-else style="color: var(--bp-muted)">
                        {{
                            missingMessage ??
                            'Fill in the amounts to see what this costs.'
                        }}
                    </p>
                </div>

                <div
                    class="flex flex-col gap-2 text-[13.5px]"
                    style="color: var(--bp-ink)"
                >
                    <label v-if="isCard" class="flex items-center gap-2">
                        <input v-model="form.in_minimum" type="checkbox" />
                        Counts toward the card's minimum payment
                    </label>
                    <label v-if="plan" class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            :checked="form.status === 'paid_off'"
                            @change="
                                form.status = (
                                    $event.target as HTMLInputElement
                                ).checked
                                    ? 'paid_off'
                                    : 'active'
                            "
                        />
                        Paid off early
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        plan ? 'Save' : 'Add'
                    }}</Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
