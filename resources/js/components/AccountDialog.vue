<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/Money/AccountController';
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

export type AccountRow = {
    id: number;
    name: string;
    type: string;
    balance: number;
    isSpendable: boolean;
};

const props = defineProps<{ open: boolean; account?: AccountRow | null }>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({
    name: '',
    type: 'bank',
    balance: null as number | null,
    is_spendable: true,
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults({
            name: props.account?.name ?? '',
            type: props.account?.type ?? 'bank',
            balance: props.account ? toPesos(props.account.balance) : null,
            is_spendable: props.account?.isSpendable ?? true,
        }).reset();
    },
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.account) {
        form.patch(update(props.account.id).url, options);
    } else {
        form.post(store().url, options);
    }
}

function remove(): void {
    if (!props.account) {
        return;
    }

    form.delete(destroy(props.account.id).url, {
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
                <DialogTitle>{{
                    account ? 'Edit account' : 'Add account'
                }}</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div>
                    <FieldLabel
                        hint="Where the money sits, like BPI Savings or GCash."
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
                            hint="Only a label, it does not change any calculation."
                            >Type</FieldLabel
                        >
                        <select v-model="form.type" :class="fieldClass">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank</option>
                            <option value="ewallet">E-wallet</option>
                        </select>
                        <p v-if="form.errors.type" :class="errorClass">
                            {{ form.errors.type }}
                        </p>
                    </div>
                    <div>
                        <FieldLabel
                            hint="What is in it right now. Update it when it changes, nothing updates it for you yet."
                            >Balance</FieldLabel
                        >
                        <MoneyInput
                            v-model="form.balance"
                            :empty-value="null"
                            adorn
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.balance" :class="errorClass">
                            {{ form.errors.balance }}
                        </p>
                    </div>
                </div>

                <label
                    class="flex items-start gap-2 text-[13.5px]"
                    style="color: var(--hab-ink)"
                >
                    <input
                        v-model="form.is_spendable"
                        type="checkbox"
                        class="mt-1"
                    />
                    <span>
                        I can spend this money
                        <span
                            class="block text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            Untick it for savings you keep aside. It stays
                            listed but is left out of what is on hand.
                        </span>
                    </span>
                </label>

                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button
                        v-if="account"
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
