<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/Money/GoalController';
import FieldLabel from '@/components/FieldLabel.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { toPesos } from '@/utils/money';

export type GoalRow = {
    id: number;
    kind: 'buffer' | 'sinking';
    name: string;
    monthlyContribution: number;
    saved: number;
    accountId: number | null;
    accountName: string | null;
    bufferMonths: number | null;
    targetAmount: number | null;
    targetDate: string | null;
    target: number;
    remaining: number;
    percent: number;
    monthsToGo: number | null;
    finishMonth: string | null;
    suggestedContribution: number | null;
};

const props = defineProps<{
    open: boolean;
    kind: 'buffer' | 'sinking';
    goal?: GoalRow | null;
    accounts: { id: number; name: string; isSpendable: boolean }[];
    bufferMonthOptions: number[];
}>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({
    name: '',
    monthly_contribution: null as number | null,
    saved: null as number | null,
    account_id: '' as number | string,
    buffer_months: 3,
    target_amount: null as number | null,
    target_date: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults({
            name: props.goal?.name ?? '',
            monthly_contribution: props.goal
                ? toPesos(props.goal.monthlyContribution)
                : null,
            saved: props.goal ? toPesos(props.goal.saved) : null,
            account_id: props.goal?.accountId ?? '',
            buffer_months: props.goal?.bufferMonths ?? 3,
            target_amount: props.goal?.targetAmount
                ? toPesos(props.goal.targetAmount)
                : null,
            target_date: props.goal?.targetDate ?? '',
        }).reset();
    },
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };
    const data = form.transform((values) => {
        const common = {
            kind: props.kind,
            monthly_contribution: values.monthly_contribution ?? 0,
            account_id: values.account_id || null,
            saved: values.account_id ? null : (values.saved ?? 0),
        };

        return props.kind === 'buffer'
            ? { ...common, buffer_months: values.buffer_months }
            : {
                  ...common,
                  name: values.name,
                  target_amount: values.target_amount,
                  target_date: values.target_date || null,
              };
    });

    if (props.goal) {
        data.patch(update(props.goal.id).url, options);
    } else {
        data.post(store().url, options);
    }
}

function remove(): void {
    if (!props.goal) {
        return;
    }

    form.delete(destroy(props.goal.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

/** The server reports a second buffer under the kind field, which is not on the form. */
const kindError = computed(
    () => (form.errors as Record<string, string | undefined>).kind,
);

const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
const errorClass = 'mt-1 text-[12px] text-red-600';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>{{
                    kind === 'buffer'
                        ? goal
                            ? 'Edit emergency buffer'
                            : 'Set up an emergency buffer'
                        : goal
                          ? 'Edit goal'
                          : 'Add a goal'
                }}</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <template v-if="kind === 'buffer'">
                    <div>
                        <FieldLabel
                            hint="How many months of bills and debt payments you want to be able to cover. The target follows your obligations, so it shrinks as installments end."
                            >Months to cover</FieldLabel
                        >
                        <select
                            v-model="form.buffer_months"
                            :class="fieldClass"
                        >
                            <option
                                v-for="months in bufferMonthOptions"
                                :key="months"
                                :value="months"
                            >
                                {{ months }}
                                {{ months === 1 ? 'month' : 'months' }}
                            </option>
                        </select>
                        <p v-if="form.errors.buffer_months" :class="errorClass">
                            {{ form.errors.buffer_months }}
                        </p>
                    </div>
                </template>
                <template v-else>
                    <div>
                        <FieldLabel
                            hint="What you are saving for, like a trip, gifts or a car repair. Yearly bills belong under Bills on the Overview."
                            >Name</FieldLabel
                        >
                        <input
                            v-model="form.name"
                            type="text"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.name" :class="errorClass">
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <FieldLabel hint="How much you need in all."
                                >Target</FieldLabel
                            >
                            <MoneyInput
                                v-model="form.target_amount"
                                :empty-value="null"
                                adorn
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors.target_amount"
                                :class="errorClass"
                            >
                                {{ form.errors.target_amount }}
                            </p>
                        </div>
                        <div>
                            <FieldLabel
                                hint="Optional. With a date, the page suggests how much to put in each month to be there on time."
                                >Needed by</FieldLabel
                            >
                            <input
                                v-model="form.target_date"
                                type="date"
                                :class="fieldClass"
                            />
                            <p
                                v-if="form.errors.target_date"
                                :class="errorClass"
                            >
                                {{ form.errors.target_date }}
                            </p>
                        </div>
                    </div>
                </template>

                <div>
                    <FieldLabel
                        hint="What you plan to put in every month. It comes off safe to spend until the goal is reached."
                        >Put in each month</FieldLabel
                    >
                    <MoneyInput
                        v-model="form.monthly_contribution"
                        :empty-value="null"
                        adorn
                        :class="fieldClass"
                    />
                    <p
                        v-if="form.errors.monthly_contribution"
                        :class="errorClass"
                    >
                        {{ form.errors.monthly_contribution }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <FieldLabel
                            hint="Pick the account where this money sits and its balance is used, so it stays current. Choose one that is marked not spendable, or the same money is counted as on hand too."
                            >Kept in</FieldLabel
                        >
                        <select v-model="form.account_id" :class="fieldClass">
                            <option value="">I will type the amount</option>
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.name
                                }}{{
                                    account.isSpendable ? ' (spendable)' : ''
                                }}
                            </option>
                        </select>
                        <p v-if="form.errors.account_id" :class="errorClass">
                            {{ form.errors.account_id }}
                        </p>
                    </div>
                    <div v-if="!form.account_id">
                        <FieldLabel
                            hint="What you have saved so far. Update it when it changes, nothing updates it for you."
                            >Saved so far</FieldLabel
                        >
                        <MoneyInput
                            v-model="form.saved"
                            :empty-value="null"
                            adorn
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.saved" :class="errorClass">
                            {{ form.errors.saved }}
                        </p>
                    </div>
                </div>

                <p v-if="kindError" :class="errorClass">
                    {{ kindError }}
                </p>

                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button
                        v-if="goal"
                        type="button"
                        variant="ghost"
                        class="text-red-600"
                        :disabled="form.processing"
                        @click="remove"
                        >Delete</Button
                    >
                    <span v-else></span>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="emit('update:open', false)"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="form.processing"
                            >Save</Button
                        >
                    </div>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
