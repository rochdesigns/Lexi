<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/Money/BillController';
import FieldLabel from '@/components/FieldLabel.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { monthNames, toPesos } from '@/utils/money';

export type BillRow = {
    id: number;
    name: string;
    amount: number;
    frequency: string;
    dueDay: number;
    dueMonth: number | null;
    autopay: boolean;
};

const props = defineProps<{ open: boolean; bill?: BillRow | null }>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({
    name: '',
    amount: null as number | null,
    frequency: 'monthly',
    due_day: 1 as number | string,
    due_month: 1 as number | string,
    autopay: false,
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults({
            name: props.bill?.name ?? '',
            amount: props.bill ? toPesos(props.bill.amount) : null,
            frequency: props.bill?.frequency ?? 'monthly',
            due_day: props.bill?.dueDay ?? 1,
            due_month: props.bill?.dueMonth ?? new Date().getMonth() + 1,
            autopay: props.bill?.autopay ?? false,
        }).reset();
    },
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };
    const data = form.transform((values) => ({
        ...values,
        due_month: values.frequency === 'annual' ? values.due_month : null,
    }));

    if (props.bill) {
        data.patch(update(props.bill.id).url, options);
    } else {
        data.post(store().url, options);
    }
}

function remove(): void {
    if (!props.bill) {
        return;
    }

    form.delete(destroy(props.bill.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
const errorClass = 'mt-1 text-[12px] text-red-600';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent style="max-width: 440px">
            <DialogHeader>
                <DialogTitle>{{ bill ? 'Edit bill' : 'Add bill' }}</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div>
                    <FieldLabel hint="Rent, electricity, a subscription."
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
                        <FieldLabel
                            hint="What you pay each time the bill comes due."
                            >Amount</FieldLabel
                        >
                        <MoneyInput
                            v-model="form.amount"
                            :empty-value="null"
                            adorn
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.amount" :class="errorClass">
                            {{ form.errors.amount }}
                        </p>
                    </div>
                    <div>
                        <FieldLabel
                            hint="Yearly bills are set aside a little each month, so the month they fall due is not a surprise."
                            >Paid</FieldLabel
                        >
                        <select v-model="form.frequency" :class="fieldClass">
                            <option value="monthly">Every month</option>
                            <option value="annual">Once a year</option>
                        </select>
                        <p v-if="form.errors.frequency" :class="errorClass">
                            {{ form.errors.frequency }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div v-if="form.frequency === 'annual'">
                        <FieldLabel hint="The month the yearly bill is due."
                            >Month</FieldLabel
                        >
                        <select v-model="form.due_month" :class="fieldClass">
                            <option
                                v-for="(name, index) in monthNames"
                                :key="name"
                                :value="index + 1"
                            >
                                {{ name }}
                            </option>
                        </select>
                        <p v-if="form.errors.due_month" :class="errorClass">
                            {{ form.errors.due_month }}
                        </p>
                    </div>
                    <div>
                        <FieldLabel
                            hint="Day of the month. Day 31 means the last day of shorter months."
                            >Due day</FieldLabel
                        >
                        <input
                            v-model="form.due_day"
                            type="number"
                            min="1"
                            max="31"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.due_day" :class="errorClass">
                            {{ form.errors.due_day }}
                        </p>
                    </div>
                </div>

                <label
                    class="flex items-center gap-2 text-[13.5px]"
                    style="color: var(--hab-ink)"
                >
                    <input v-model="form.autopay" type="checkbox" />
                    Paid automatically
                </label>

                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button
                        v-if="bill"
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
