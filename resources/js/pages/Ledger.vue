<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { destroyAccount, storeAccount, updateAccount } from '@/actions/App/Http/Controllers/LedgerController';

type AccountType = 'checking' | 'savings' | 'credit' | 'cash' | 'investment';

type LedgerTransaction = {
    id: number;
    type: 'income' | 'expense' | 'transfer';
    amount: number;
    description: string | null;
    occurred_at: string;
    direction: 'in' | 'out';
    counterpart: string | null;
};

type LedgerAccount = {
    id: number;
    name: string;
    institution: string | null;
    type: AccountType;
    is_liability: boolean;
    balance: number;
    recent_transactions: LedgerTransaction[];
};

const props = defineProps<{ accounts: LedgerAccount[] }>();

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

function typeLabel(type: AccountType): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

function transactionLabel(tx: LedgerTransaction): string {
    if (tx.type === 'transfer' && tx.counterpart) {
        return tx.direction === 'out' ? `Transfer to ${tx.counterpart}` : `Transfer from ${tx.counterpart}`;
    }

    return tx.type === 'income' ? 'Income' : tx.type === 'expense' ? 'Expense' : 'Transfer';
}

// ── Expand / collapse transaction history ───────────────────────────────

const expandedId = ref<number | null>(null);

function toggleExpand(accountId: number): void {
    expandedId.value = expandedId.value === accountId ? null : accountId;
}

// ── Add / edit account (inline) ─────────────────────────────────────────

const editingId = ref<number | 'new' | null>(null);
const accountForm = useForm({
    name: '',
    institution: null as string | null,
    type: 'checking' as AccountType,
    is_liability: false,
    balance: 0,
});

function openAddRow(): void {
    editingId.value = 'new';
    expandedId.value = null;
    accountForm.clearErrors();
    accountForm.name = '';
    accountForm.institution = null;
    accountForm.type = 'checking';
    accountForm.is_liability = false;
    accountForm.balance = 0;
}

function openEditRow(account: LedgerAccount): void {
    editingId.value = account.id;
    expandedId.value = null;
    accountForm.clearErrors();
    accountForm.name = account.name;
    accountForm.institution = account.institution;
    accountForm.type = account.type;
    accountForm.is_liability = account.is_liability;
    accountForm.balance = account.balance;
}

function cancelRow(): void {
    editingId.value = null;
    accountForm.clearErrors();
}

function onTypeChange(): void {
    accountForm.is_liability = accountForm.type === 'credit';
}

function submitRow(): void {
    if (editingId.value === 'new') {
        accountForm.post(storeAccount().url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
    } else if (editingId.value !== null) {
        accountForm.patch(updateAccount(editingId.value).url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
    }
}

function removeAccount(account: LedgerAccount): void {
    if (!window.confirm(`Delete ${account.name}? This also removes any transfer records linking it to your other accounts.`)) {
        return;
    }

    router.delete(destroyAccount(account.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Ledger" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5">
            <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                Ledger
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">Your accounts and recent activity</p>
        </div>

        <!-- Account list -->
        <div
            class="rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Accounts</h2>

            <p v-if="props.accounts.length === 0 && editingId !== 'new'" class="text-[14px]" style="color: var(--hab-muted)">
                Add an account to start tracking your balances.
            </p>

            <div v-for="(account, i) in props.accounts" :key="account.id">
                <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

                <div v-if="editingId !== account.id" class="flex items-center gap-3 py-1">
                    <button type="button" class="min-w-0 flex-1 text-left" @click="toggleExpand(account.id)">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-[14px] font-medium" style="color: var(--hab-ink)">{{ account.name }}</span>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                            >
                                {{ typeLabel(account.type) }}
                            </span>
                            <span
                                v-if="account.is_liability"
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                style="background: var(--hab-chip-fill); color: #c0512c"
                            >
                                Liability
                            </span>
                        </div>
                        <div class="text-[12px]" style="color: var(--hab-muted)">
                            <span v-if="account.institution">{{ account.institution }} · </span>
                            {{ formatMoney(account.balance) }} {{ account.is_liability ? 'owed' : 'balance' }}
                        </div>
                    </button>
                    <div class="shrink-0 flex items-center gap-3">
                        <button type="button" class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)" @click="openEditRow(account)">
                            edit
                        </button>
                        <button type="button" class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)" @click="removeAccount(account)">
                            delete
                        </button>
                    </div>
                </div>

                <!-- Expanded transaction history -->
                <div v-if="editingId !== account.id && expandedId === account.id" class="mt-2 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <p v-if="account.recent_transactions.length === 0" class="text-[12px]" style="color: var(--hab-muted)">
                        No transactions yet.
                    </p>
                    <div v-for="tx in account.recent_transactions" :key="tx.id" class="flex items-center justify-between py-1 text-[12.5px]">
                        <span style="color: var(--hab-ink)">
                            {{ tx.occurred_at }} · {{ tx.description ?? transactionLabel(tx) }}
                        </span>
                        <span :style="{ color: tx.direction === 'in' ? 'var(--hab-accent)' : 'var(--hab-ink)' }">
                            {{ tx.direction === 'in' ? '+' : '−' }}{{ formatMoney(tx.amount) }}
                        </span>
                    </div>
                </div>

                <!-- Inline edit form -->
                <div v-else-if="editingId === account.id" class="rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Name</label>
                            <input v-model="accountForm.name" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Institution (optional)</label>
                            <input v-model="accountForm.institution" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Type</label>
                            <select v-model="accountForm.type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" @change="onTypeChange">
                                <option value="checking">Checking</option>
                                <option value="savings">Savings</option>
                                <option value="credit">Credit</option>
                                <option value="cash">Cash</option>
                                <option value="investment">Investment</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Starting balance</label>
                            <input v-model.number="accountForm.balance" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div class="flex items-end gap-2 pb-1.5">
                            <input id="is_liability" v-model="accountForm.is_liability" type="checkbox" class="h-4 w-4" />
                            <label for="is_liability" class="text-[11.5px]" style="color: var(--hab-muted)">This is a liability (I owe this balance)</label>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="accountForm.processing" @click="submitRow">
                            Confirm
                        </button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="accountForm.processing" @click="cancelRow">
                            Cancel
                        </button>
                    </div>
                    <div v-if="accountForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.name }}</div>
                    <div v-if="accountForm.errors.institution" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.institution }}</div>
                    <div v-if="accountForm.errors.type" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.type }}</div>
                    <div v-if="accountForm.errors.balance" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.balance }}</div>
                </div>
            </div>

            <div v-if="props.accounts.length > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

            <!-- Add row -->
            <button v-if="editingId !== 'new'" type="button" class="flex w-full items-center gap-2 py-2 text-[13.5px] font-medium" style="color: var(--hab-accent)" @click="openAddRow">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span>
                Add an account
            </button>
            <div v-else class="rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Name</label>
                        <input v-model="accountForm.name" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Institution (optional)</label>
                        <input v-model="accountForm.institution" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Type</label>
                        <select v-model="accountForm.type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" @change="onTypeChange">
                            <option value="checking">Checking</option>
                            <option value="savings">Savings</option>
                            <option value="credit">Credit</option>
                            <option value="cash">Cash</option>
                            <option value="investment">Investment</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Starting balance</label>
                        <input v-model.number="accountForm.balance" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div class="flex items-end gap-2 pb-1.5">
                        <input id="is_liability_new" v-model="accountForm.is_liability" type="checkbox" class="h-4 w-4" />
                        <label for="is_liability_new" class="text-[11.5px]" style="color: var(--hab-muted)">This is a liability (I owe this balance)</label>
                    </div>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="accountForm.processing" @click="submitRow">
                        Confirm
                    </button>
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="accountForm.processing" @click="cancelRow">
                        Cancel
                    </button>
                </div>
                <div v-if="accountForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.name }}</div>
                <div v-if="accountForm.errors.institution" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.institution }}</div>
                <div v-if="accountForm.errors.type" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.type }}</div>
                <div v-if="accountForm.errors.balance" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.balance }}</div>
            </div>
        </div>
    </div>
</template>
