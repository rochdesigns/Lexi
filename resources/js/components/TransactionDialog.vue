<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/Money/TransactionsController';
import MoneyInput from '@/components/MoneyInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { toPesos, transactionCategories } from '@/utils/money';

export type TransactionRow = {
    id: number;
    date: string;
    amount: number;
    category: string;
    note: string | null;
    source: string;
    accountId: number | null;
    debtId: number | null;
    paidWith: string | null;
};

const props = defineProps<{
    open: boolean;
    transaction: TransactionRow | null;
    accounts: { id: number; name: string }[];
    cards: { id: number; name: string }[];
}>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({
    date: '',
    amount: null as number | null,
    category: '',
    note: '',
    paidWith: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open || !props.transaction) {
            return;
        }

        const transaction = props.transaction;

        form.clearErrors();
        form.defaults({
            date: transaction.date,
            amount: toPesos(transaction.amount),
            category: transaction.category,
            note: transaction.note ?? '',
            paidWith: transaction.accountId
                ? `account:${transaction.accountId}`
                : `card:${transaction.debtId}`,
        }).reset();
    },
);

function submit(): void {
    if (!props.transaction) {
        return;
    }

    form.transform(({ paidWith, ...data }) => ({
        ...data,
        note: data.note || null,
        account_id: paidWith.startsWith('account:')
            ? Number(paidWith.slice(8))
            : null,
        debt_id: paidWith.startsWith('card:')
            ? Number(paidWith.slice(5))
            : null,
    })).patch(update(props.transaction.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

function remove(): void {
    if (!props.transaction) {
        return;
    }

    form.delete(destroy(props.transaction.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

/** The server reports a missing or invalid payer under the column it checks. */
const methodError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.account_id ?? errors.debt_id;
});

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
                <DialogTitle>Edit expense</DialogTitle>
            </DialogHeader>

            <form class="flex flex-col gap-3.5 pt-1" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label :class="labelClass">Amount</label>
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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label :class="labelClass">Category</label>
                        <select v-model="form.category" :class="fieldClass">
                            <option
                                v-for="category in transactionCategories"
                                :key="category.value"
                                :value="category.value"
                            >
                                {{ category.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.category" :class="errorClass">
                            {{ form.errors.category }}
                        </p>
                    </div>
                    <div>
                        <label :class="labelClass">Paid with</label>
                        <select v-model="form.paidWith" :class="fieldClass">
                            <optgroup v-if="accounts.length" label="Accounts">
                                <option
                                    v-for="account in accounts"
                                    :key="`a${account.id}`"
                                    :value="`account:${account.id}`"
                                >
                                    {{ account.name }}
                                </option>
                            </optgroup>
                            <optgroup v-if="cards.length" label="Cards">
                                <option
                                    v-for="card in cards"
                                    :key="`c${card.id}`"
                                    :value="`card:${card.id}`"
                                >
                                    {{ card.name }}
                                </option>
                            </optgroup>
                        </select>
                        <p v-if="methodError" :class="errorClass">
                            {{ methodError }}
                        </p>
                    </div>
                </div>

                <div>
                    <label :class="labelClass">Note</label>
                    <input
                        v-model="form.note"
                        type="text"
                        maxlength="200"
                        :class="fieldClass"
                    />
                    <p v-if="form.errors.note" :class="errorClass">
                        {{ form.errors.note }}
                    </p>
                </div>

                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button
                        type="button"
                        variant="ghost"
                        class="text-red-600"
                        :disabled="form.processing"
                        @click="remove"
                        >Delete</Button
                    >
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
