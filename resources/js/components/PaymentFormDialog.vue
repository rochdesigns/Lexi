<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/Money/DebtPaymentController';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    debtId: number;
    plans: { id: number; description: string }[];
    accounts: { id: number; name: string; balance: number }[];
}>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

function today(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

const form = useForm({
    payment_plan_id: '' as number | string,
    account_id: '' as number | string,
    date: today(),
    amount: null as number | null,
    is_extra: false,
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.date = today();
        }
    },
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        payment_plan_id: data.payment_plan_id || null,
        account_id: data.account_id || null,
    })).post(store(props.debtId).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
const labelClass =
    'mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]';
const errorClass = 'mt-1 text-[12px] text-red-600';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent style="max-width: 440px">
            <DialogHeader>
                <DialogTitle>Record a payment</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label :class="labelClass">Amount</label>
                        <MoneyInput
                            v-model="form.amount"
                            :empty-value="null"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.amount" :class="errorClass">
                            {{ form.errors.amount }}
                        </p>
                    </div>
                    <div>
                        <label :class="labelClass">Date</label>
                        <input
                            v-model="form.date"
                            type="date"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.date" :class="errorClass">
                            {{ form.errors.date }}
                        </p>
                    </div>
                </div>

                <div v-if="plans.length > 0">
                    <label :class="labelClass">Towards</label>
                    <select v-model="form.payment_plan_id" :class="fieldClass">
                        <option value="">The debt in general</option>
                        <option
                            v-for="plan in plans"
                            :key="plan.id"
                            :value="plan.id"
                        >
                            {{ plan.description }}
                        </option>
                    </select>
                    <p v-if="form.errors.payment_plan_id" :class="errorClass">
                        {{ form.errors.payment_plan_id }}
                    </p>
                </div>

                <div v-if="accounts.length > 0">
                    <label :class="labelClass">Paid from</label>
                    <select v-model="form.account_id" :class="fieldClass">
                        <option value="">Don't change an account</option>
                        <option
                            v-for="account in accounts"
                            :key="account.id"
                            :value="account.id"
                        >
                            {{ account.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.account_id" :class="errorClass">
                        {{ form.errors.account_id }}
                    </p>
                </div>

                <label
                    class="flex items-center gap-2 text-[13.5px]"
                    style="color: var(--hab-ink)"
                >
                    <input v-model="form.is_extra" type="checkbox" />
                    Extra payment, beyond what was due
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing"
                        >Record payment</Button
                    >
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
