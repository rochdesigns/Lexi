<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/Money/IncomeSourceController';
import FieldLabel from '@/components/FieldLabel.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formError, formField } from '@/utils/blueprint';
import { toPesos } from '@/utils/money';

export type IncomeSourceRow = {
    id: number;
    name: string;
    amount: number;
    schedule: string;
    payDay1: number;
    payDay2: number | null;
};

const props = defineProps<{ open: boolean; source?: IncomeSourceRow | null }>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({
    name: '',
    amount: null as number | null,
    schedule: 'monthly',
    pay_day_1: 30 as number | string,
    pay_day_2: '' as number | string,
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults({
            name: props.source?.name ?? '',
            amount: props.source ? toPesos(props.source.amount) : null,
            schedule: props.source?.schedule ?? 'monthly',
            pay_day_1: props.source?.payDay1 ?? 30,
            pay_day_2: props.source?.payDay2 ?? '',
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
        pay_day_2: values.pay_day_2 || null,
    }));

    if (props.source) {
        data.patch(update(props.source.id).url, options);
    } else {
        data.post(store().url, options);
    }
}

function remove(): void {
    if (!props.source) {
        return;
    }

    form.delete(destroy(props.source.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

const fieldClass = formField;
const errorClass = formError;
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent style="max-width: 440px">
            <DialogHeader>
                <DialogTitle>{{
                    source ? 'Edit income' : 'Add income'
                }}</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div>
                    <FieldLabel
                        hint="Where the money comes from, like Salary or a client."
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
                            hint="What you receive on each pay day, after tax."
                            >Amount per pay day</FieldLabel
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
                            hint="Semi-monthly pays twice a month, so the amount counts twice."
                            >Paid</FieldLabel
                        >
                        <select v-model="form.schedule" :class="fieldClass">
                            <option value="monthly">Once a month</option>
                            <option value="semi_monthly">Twice a month</option>
                        </select>
                        <p v-if="form.errors.schedule" :class="errorClass">
                            {{ form.errors.schedule }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <FieldLabel
                            hint="Day of the month. Day 31 means the last day of shorter months."
                            >{{
                                form.schedule === 'semi_monthly'
                                    ? 'First pay day'
                                    : 'Pay day'
                            }}</FieldLabel
                        >
                        <input
                            v-model="form.pay_day_1"
                            type="number"
                            min="1"
                            max="31"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.pay_day_1" :class="errorClass">
                            {{ form.errors.pay_day_1 }}
                        </p>
                    </div>
                    <div v-if="form.schedule === 'semi_monthly'">
                        <FieldLabel hint="The other pay day in the month."
                            >Second pay day</FieldLabel
                        >
                        <input
                            v-model="form.pay_day_2"
                            type="number"
                            min="1"
                            max="31"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.pay_day_2" :class="errorClass">
                            {{ form.errors.pay_day_2 }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button
                        v-if="source"
                        type="button"
                        variant="ghost"
                        class="text-[#a3311f]"
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
