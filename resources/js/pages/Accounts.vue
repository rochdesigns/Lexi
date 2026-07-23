<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy, transactions as accountTransactionsUrl } from '@/actions/App/Http/Controllers/AccountController';
import { destroy as destroyTransaction, update as updateTransaction } from '@/actions/App/Http/Controllers/TransactionController';

type AccountType = 'cash' | 'credit' | 'investment';

type Account = {
    id: number;
    name: string;
    institution: string | null;
    last4: string | null;
    balance: string;
    type: AccountType;
};

type AccountGroup = {
    type: AccountType;
    label: string;
    subtotal: number;
    accounts: Account[];
};

const props = defineProps<{
    netWorth: number;
    netWorthChangePct: number;
    groups: AccountGroup[];
    cycleLabel: string;
    cashIn: number;
    cashOut: number;
    cashNet: number;
    cashOutPct: number;
}>();

const typeIcons: Record<AccountType, string> = {
    cash: 'account_balance_wallet',
    credit: 'credit_card',
    investment: 'candlestick_chart',
};

const typeColors: Record<AccountType, { color: string; bg: string }> = {
    cash: { color: '#2f7d5b', bg: '#dff3e6' },
    credit: { color: '#c0512c', bg: '#fbe2d6' },
    investment: { color: '#3f7fb0', bg: '#dcebf5' },
};

const typeOptions: { value: AccountType; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'credit', label: 'Credit' },
    { value: 'investment', label: 'Investment' },
];

// Modal state
const modalOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const form = reactive({ name: '', institution: '', last4: '', balance: '', type: 'cash' as AccountType });

// Two-step delete (accounts)
const deleteConfirmId = ref<number | null>(null);
let deleteTimer: ReturnType<typeof setTimeout> | null = null;

// Account transactions panel
type AccountTransaction = {
    id: number;
    type: 'expense' | 'income' | 'transfer';
    amount: number;
    merchant: string | null;
    transacted_at: string;
    account_id: number;
    to_account_id: number | null;
    budget_category_id: number | null;
    is_destination: boolean;
    category: { id: number; name: string; icon: string; color: string } | null;
    to_account: { id: number; name: string } | null;
};

type TxCategory = { id: number; name: string; icon: string; color: string };

const txPanelOpen = ref(false);
const txPanelAccount = ref<Account | null>(null);
const txPanelList = ref<AccountTransaction[]>([]);
const txPanelCategories = ref<TxCategory[]>([]);
const txPanelLoading = ref(false);
const deletingTxId = ref<number | null>(null);
let deleteTxTimer: ReturnType<typeof setTimeout> | null = null;

// Transaction edit form (within the panel)
const txEditOpen = ref(false);
const editingTx = ref<AccountTransaction | null>(null);
const txEditForm = reactive({
    type: 'expense' as 'expense' | 'income' | 'transfer',
    amount: '',
    merchant: '',
    account_id: 0,
    to_account_id: null as number | null,
    budget_category_id: null as number | null,
    transacted_at: '',
});

const allAccounts = computed(() => props.groups.flatMap(g => g.accounts));

const netWorthFormatted = computed(() => formatMoney(props.netWorth));
const netWorthChangeIcon = computed(() => {
    if (props.cashNet > 0) return 'trending_up';
    if (props.cashNet < 0) return 'trending_down';
    return 'trending_flat';
});
const netWorthChangeBadge = computed(() => {
    const pct = Math.abs(props.netWorthChangePct).toFixed(1) + '%';
    return `${formatMoney(props.cashNet)} · ${pct} this cycle`;
});

function formatMoney(amount: string | number): string {
    const n = typeof amount === 'string' ? parseFloat(amount) : amount;
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? '-' + formatted : formatted;
}

function openAdd() {
    editingAccount.value = null;
    Object.assign(form, { name: '', institution: '', last4: '', balance: '', type: 'cash' });
    modalOpen.value = true;
}

function openEdit(account: Account) {
    editingAccount.value = account;
    Object.assign(form, {
        name: account.name,
        institution: account.institution ?? '',
        last4: account.last4 ?? '',
        balance: account.balance,
        type: account.type,
    });
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    const data = {
        name: form.name,
        institution: form.institution || null,
        last4: form.last4 || null,
        balance: balanceEvaluated.value ?? parseFloat(form.balance),
        type: form.type,
    };
    if (editingAccount.value) {
        router.patch(update({ account: editingAccount.value.id }).url, data, {
            onSuccess: closeModal,
            preserveScroll: true,
        });
    } else {
        router.post(store().url, data, {
            onSuccess: closeModal,
            preserveScroll: true,
        });
    }
}

function startDelete(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deleteConfirmId.value = id;
    deleteTimer = setTimeout(() => { deleteConfirmId.value = null; }, 3000);
}

function confirmDelete(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deleteConfirmId.value = null;
    router.delete(destroy({ account: id }).url, { preserveScroll: true });
}

function cancelDelete() {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deleteConfirmId.value = null;
}

async function openAccountTransactions(account: Account) {
    txPanelAccount.value = account;
    txPanelList.value = [];
    txPanelCategories.value = [];
    txPanelLoading.value = true;
    txPanelOpen.value = true;
    try {
        const response = await fetch(accountTransactionsUrl({ account: account.id }).url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const data = await response.json();
        txPanelList.value = data.transactions;
        txPanelCategories.value = data.categories;
    } finally {
        txPanelLoading.value = false;
    }
}

function openEditTx(tx: AccountTransaction) {
    editingTx.value = tx;
    Object.assign(txEditForm, {
        type: tx.type,
        amount: String(tx.amount),
        merchant: tx.merchant ?? '',
        account_id: tx.account_id,
        to_account_id: tx.to_account_id,
        budget_category_id: tx.budget_category_id,
        transacted_at: tx.transacted_at,
    });
    txEditOpen.value = true;
}

const txEditAmountEvaluated = computed(() => evaluateArithmetic(txEditForm.amount));
const txEditAmountHasExpr = computed(() => hasArithmetic(txEditForm.amount) && txEditAmountEvaluated.value !== null);

const balanceField = computed(() => (form as any).balance as string);
const balanceEvaluated = computed(() => evaluateArithmetic(balanceField.value));
const balanceHasExpr = computed(() => hasArithmetic(balanceField.value) && balanceEvaluated.value !== null);

function submitEditTx() {
    if (!editingTx.value) { return; }
    router.patch(updateTransaction({ transaction: editingTx.value.id }).url, {
        type: txEditForm.type,
        amount: txEditAmountEvaluated.value ?? parseFloat(txEditForm.amount),
        merchant: txEditForm.merchant || null,
        account_id: txEditForm.account_id,
        to_account_id: txEditForm.type === 'transfer' ? txEditForm.to_account_id : null,
        budget_category_id: txEditForm.type === 'expense' ? txEditForm.budget_category_id : null,
        transacted_at: txEditForm.transacted_at,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            txEditOpen.value = false;
            openAccountTransactions(txPanelAccount.value!);
        },
    });
}

function txAmountColor(tx: AccountTransaction): string {
    if (tx.type === 'income') { return '#2f7d5b'; }
    if (tx.type === 'transfer') { return tx.is_destination ? '#2f7d5b' : '#211d17'; }
    return '#211d17';
}

function txAmountSign(tx: AccountTransaction): string {
    if (tx.type === 'income') { return '+'; }
    if (tx.type === 'transfer' && tx.is_destination) { return '+'; }
    return '';
}

function txSubline(tx: AccountTransaction): string {
    if (tx.type === 'transfer') {
        return tx.is_destination
            ? `Transfer from ${txPanelAccount.value?.name ?? ''}`
            : `Transfer to ${tx.to_account?.name ?? '—'}`;
    }
    return tx.category?.name ?? (tx.type === 'income' ? 'Income' : 'Uncategorized');
}

function startDeleteTx(id: number) {
    if (deleteTxTimer) { clearTimeout(deleteTxTimer); }
    deletingTxId.value = id;
    deleteTxTimer = setTimeout(() => { deletingTxId.value = null; }, 3000);
}

function cancelDeleteTx() {
    if (deleteTxTimer) { clearTimeout(deleteTxTimer); }
    deletingTxId.value = null;
}

function confirmDeleteTx(id: number) {
    if (deleteTxTimer) { clearTimeout(deleteTxTimer); }
    deletingTxId.value = null;
    router.delete(destroyTransaction({ transaction: id }).url, {
        preserveScroll: true,
        onSuccess: () => {
            txPanelList.value = txPanelList.value.filter(t => t.id !== id);
        },
    });
}

function formatDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}
</script>

<template>
    <Head title="Accounts" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">

        <!-- Page header -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Accounts</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Everything you own and owe, in one place</p>
            </div>
            <button @click="openAdd" style="margin-left: auto; display: flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 42px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add account
            </button>
        </div>

        <!-- Net worth hero + cash flow row -->
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_340px] items-start">

            <!-- Net worth hero -->
            <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
                <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
                <div style="position: relative; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap">
                    <div>
                        <div style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,.72)">Total net worth</div>
                        <div style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em; margin-top: 6px">
                            {{ netWorthFormatted }}
                        </div>
                        <div style="margin-top: 14px; display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 6px 12px; border-radius: 20px; font-size: 13px">
                            <span class="msf" style="font-size: 16px; color: #fff">{{ netWorthChangeIcon }}</span>
                            <span>{{ netWorthChangeBadge }}</span>
                        </div>
                    </div>
                    <!-- Flat sparkline (phase 1 — no transaction history) -->
                    <div style="display: flex; align-items: flex-end; gap: 6px; height: 74px; padding-top: 6px">
                        <div v-for="(h, i) in [30, 35, 28, 32, 30, 30]" :key="i"
                            style="width: 16px; border-radius: 5px; background: rgba(255,255,255,.25)"
                            :style="{ height: h + '%' }">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cash flow card -->
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 20px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px; margin-bottom: 16px">
                    Cash flow · {{ cycleLabel }}
                </div>
                <div style="display: flex; flex-direction: column; gap: 13px">
                    <div style="display: flex; align-items: center; gap: 10px">
                        <span style="width: 30px; height: 30px; border-radius: 9px; background: #dff3e6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 17px; color: #2f7d5b">south_west</span>
                        </span>
                        <span style="font-size: 13.5px; color: #6b6355">Money in</span>
                        <span style="margin-left: auto; font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #2f7d5b">{{ formatMoney(cashIn) }}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px">
                        <span style="width: 30px; height: 30px; border-radius: 9px; background: #f8ecd6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 17px; color: #a06a2b">north_east</span>
                        </span>
                        <span style="font-size: 13.5px; color: #6b6355">Money out</span>
                        <span style="margin-left: auto; font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #211d17">{{ formatMoney(cashOut) }}</span>
                    </div>
                    <div style="height: 9px; border-radius: 20px; background: #efe9dc; overflow: hidden; margin-top: 2px">
                        <div style="height: 100%; border-radius: 20px; background: #d98a2b; transition: width .5s cubic-bezier(.2,.8,.2,1)"
                            :style="{ width: cashOutPct + '%' }"></div>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 11px; border-top: 1px solid #efe9dc">
                        <span style="font-size: 13.5px; font-weight: 600">Net saved</span>
                        <span style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px"
                            :style="{ color: cashNet >= 0 ? 'var(--hab-accent)' : '#c0512c' }">{{ formatMoney(cashNet) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!groups.length" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 56px 32px; text-align: center; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">account_balance</span>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 18px; margin-top: 12px; color: #211d17">No accounts yet</div>
            <div style="color: #8a8172; font-size: 14px; margin-top: 4px">Add your first account to start tracking your net worth</div>
            <button @click="openAdd" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 40px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add account
            </button>
        </div>

        <!-- Grouped accounts card -->
        <div v-if="groups.length" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 10px 26px 16px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <div v-for="group in groups" :key="group.type" style="padding: 16px 0 4px">
                <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 4px">
                    <div style="font-size: 11.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #a49b8a">
                        {{ group.label }}
                    </div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 14px;" :style="{ color: group.subtotal < 0 ? '#c0512c' : '#4a4436' }">
                        {{ formatMoney(group.subtotal) }}
                    </div>
                </div>

                <div v-for="account in group.accounts" :key="account.id" class="group/account"
                    style="display: flex; align-items: center; gap: 13px; padding: 13px 0; border-top: 1px solid #efe9dc">
                    <span :style="{ background: typeColors[account.type].bg }"
                        style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: typeColors[account.type].color }" style="font-size: 21px">
                            {{ typeIcons[account.type] }}
                        </span>
                    </span>
                    <div style="min-width: 0; flex: 1">
                        <div style="font-weight: 600; font-size: 14.5px">{{ account.name }}</div>
                        <div style="font-size: 12.5px; color: #8a8172; margin-top: 1px">
                            {{ account.institution || '—' }}{{ account.last4 ? ' •• ' + account.last4 : '' }}
                        </div>
                    </div>

                    <!-- Normal state: balance + hover actions -->
                    <template v-if="deleteConfirmId !== account.id">
                        <div class="flex items-center flex-none">
                            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px"
                                :style="{ color: parseFloat(account.balance) < 0 ? '#c0512c' : '#211d17' }">
                                {{ formatMoney(account.balance) }}
                            </div>
                            <span class="material-symbols-rounded" style="font-size: 20px; color: #c3bbab; margin-left: 8px">chevron_right</span>
                            <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/account:max-w-[120px] flex-shrink-0">
                                <div class="flex items-center gap-1" style="padding-left: 12px">
                                    <button @click.stop="openAccountTransactions(account)"
                                        title="View transactions"
                                        style="width: 32px; height: 32px; border: 1px solid #dcebf5; background: #dcebf5; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #3f7fb0">
                                        <span class="material-symbols-rounded" style="font-size: 17px">receipt_long</span>
                                    </button>
                                    <button @click.stop="openEdit(account)"
                                        style="width: 32px; height: 32px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                        <span class="material-symbols-rounded" style="font-size: 17px">edit</span>
                                    </button>
                                    <button @click.stop="startDelete(account.id)"
                                        style="width: 32px; height: 32px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                        <span class="material-symbols-rounded" style="font-size: 17px">delete</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Delete confirm state -->
                    <template v-else>
                        <div style="display: flex; align-items: center; gap: 6px; margin-left: auto">
                            <button @click="cancelDelete"
                                style="padding: 0 12px; height: 32px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                                Cancel
                            </button>
                            <button @click="confirmDelete(account.id)"
                                style="padding: 0 12px; height: 32px; border: none; background: #c0512c; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fff">
                                Confirm?
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    <!-- Account transactions modal -->
    <Dialog v-model:open="txPanelOpen">
        <DialogContent style="max-width: 520px; max-height: 80vh; display: flex; flex-direction: column">
            <DialogHeader>
                <DialogTitle style="display: flex; align-items: center; gap: 10px">
                    <span v-if="txPanelAccount" :style="{ background: typeColors[txPanelAccount.type].bg }"
                        style="width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: txPanelAccount ? typeColors[txPanelAccount.type].color : '' }" style="font-size: 17px">
                            {{ txPanelAccount ? typeIcons[txPanelAccount.type] : '' }}
                        </span>
                    </span>
                    {{ txPanelAccount?.name }} Transactions
                </DialogTitle>
            </DialogHeader>

            <!-- Loading state -->
            <div v-if="txPanelLoading" style="display: flex; align-items: center; justify-content: center; padding: 48px 0; gap: 10px; color: #8a8172; font-size: 13.5px">
                <span class="material-symbols-rounded" style="font-size: 20px; animation: spin 1s linear infinite">progress_activity</span>
                Loading transactions…
            </div>

            <!-- Empty state -->
            <div v-else-if="!txPanelList.length" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 0; gap: 8px">
                <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">receipt_long</span>
                <div style="font-size: 13.5px; color: #8a8172">No transactions for this account yet</div>
            </div>

            <!-- Transaction list -->
            <div v-else style="overflow-y: auto; margin: 0 -24px; padding: 0 24px">
                <div v-for="(tx, i) in txPanelList" :key="tx.id" class="group/atx"
                    style="display: flex; align-items: center; gap: 12px; padding: 12px 0"
                    :style="{ borderTop: i > 0 ? '1px solid #efe9dc' : 'none' }">
                    <!-- Icon -->
                    <span v-if="tx.category" :style="{ background: tx.category.color + '22' }"
                        style="width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: tx.category.color }" style="font-size: 19px">{{ tx.category.icon }}</span>
                    </span>
                    <span v-else-if="tx.type === 'income'"
                        style="width: 36px; height: 36px; border-radius: 10px; background: #dff3e6; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" style="font-size: 19px; color: #2f7d5b">south_west</span>
                    </span>
                    <span v-else-if="tx.type === 'transfer'"
                        style="width: 36px; height: 36px; border-radius: 10px; background: #f1ede3; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="material-symbols-rounded" style="font-size: 19px; color: #8a8172">swap_horiz</span>
                    </span>
                    <span v-else
                        style="width: 36px; height: 36px; border-radius: 10px; background: #f1ede3; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="material-symbols-rounded" style="font-size: 19px; color: #8a8172">receipt</span>
                    </span>

                    <!-- Info -->
                    <div style="flex: 1; min-width: 0">
                        <div style="font-weight: 600; font-size: 14px; color: #211d17">{{ tx.merchant || 'No description' }}</div>
                        <div style="font-size: 12px; color: #8a8172; margin-top: 2px">
                            {{ txSubline(tx) }} · {{ formatDate(tx.transacted_at) }}
                        </div>
                    </div>

                    <!-- Amount + actions -->
                    <template v-if="deletingTxId !== tx.id">
                        <div class="flex items-center flex-none">
                            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px"
                                :style="{ color: txAmountColor(tx) }">
                                {{ txAmountSign(tx) }}{{ formatMoney(tx.amount) }}
                            </div>
                            <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/atx:max-w-[76px] flex-shrink-0">
                                <div class="flex items-center gap-1" style="padding-left: 8px">
                                    <button @click.stop="openEditTx(tx)"
                                        style="width: 28px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                        <span class="material-symbols-rounded" style="font-size: 15px">edit</span>
                                    </button>
                                    <button @click.stop="startDeleteTx(tx.id)"
                                        style="width: 28px; height: 28px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                        <span class="material-symbols-rounded" style="font-size: 15px">delete</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div style="display: flex; align-items: center; gap: 5px; flex: none">
                            <button @click="cancelDeleteTx"
                                style="padding: 0 10px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #6b6355">
                                Cancel
                            </button>
                            <button @click="confirmDeleteTx(tx.id)"
                                style="padding: 0 10px; height: 28px; border: none; background: #c0512c; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #fff">
                                Delete?
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- Edit transaction modal -->
    <Dialog v-model:open="txEditOpen">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>Edit transaction</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitEditTx" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Type -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Type</label>
                    <div style="display: flex; gap: 6px">
                        <button v-for="t in (['expense', 'income', 'transfer'] as const)" :key="t" type="button" @click="txEditForm.type = t"
                            style="flex: 1; height: 36px; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; border: 1px solid; text-transform: capitalize"
                            :style="{
                                borderColor: txEditForm.type === t ? 'var(--hab-accent)' : '#e0dacd',
                                background: txEditForm.type === t ? 'color-mix(in srgb, var(--hab-accent) 12%, transparent)' : '#fffdf8',
                                color: txEditForm.type === t ? 'var(--hab-accent-deep)' : '#6b6355',
                                fontWeight: txEditForm.type === t ? 600 : 500,
                            }">
                            {{ t }}
                        </button>
                    </div>
                </div>

                <!-- Amount + Date -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Amount *</label>
                        <div style="position: relative">
                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #6b6355; font-weight: 600">₱</span>
                            <input v-model="txEditForm.amount" type="text" inputmode="decimal" required
                                style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17" />
                            <div v-if="txEditAmountHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= ₱{{ txEditAmountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Date *</label>
                        <input v-model="txEditForm.transacted_at" type="date" required
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>

                <!-- Merchant -->
                <div v-if="txEditForm.type !== 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Merchant</label>
                    <input v-model="txEditForm.merchant" placeholder="e.g. Jollibee"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                </div>

                <!-- Account -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">{{ txEditForm.type === 'transfer' ? 'From account' : 'Account' }} *</label>
                    <select v-model="txEditForm.account_id" required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none">
                        <option v-for="a in allAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>

                <!-- To account (transfer) -->
                <div v-if="txEditForm.type === 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">To account *</label>
                    <select v-model="txEditForm.to_account_id" required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none">
                        <option v-for="a in allAccounts.filter(a => a.id !== txEditForm.account_id)" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>

                <!-- Category (expense only) -->
                <div v-if="txEditForm.type === 'expense'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Category *</label>
                    <select v-model="txEditForm.budget_category_id" required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none; box-sizing: border-box">
                        <option :value="null" disabled>Select a category</option>
                        <option v-for="c in txPanelCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="txEditOpen = false"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        Save changes
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Add / Edit modal -->
    <Dialog v-model:open="modalOpen">
        <DialogContent style="max-width: 440px">
            <DialogHeader>
                <DialogTitle>{{ editingAccount ? 'Edit account' : 'Add account' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                    <input v-model="form.name" required placeholder="e.g. BDO Checking"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Institution</label>
                        <input v-model="form.institution" placeholder="e.g. BDO"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Last 4 digits</label>
                        <input v-model="form.last4" maxlength="4" placeholder="1234"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Balance *</label>
                    <input v-model="form.balance" type="text" inputmode="decimal" required placeholder="e.g. 15000 (or -612 for debt)"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    <div v-if="balanceHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= {{ balanceEvaluated !== null && balanceEvaluated < 0 ? '-' : '' }}₱{{ Math.abs(balanceEvaluated ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                    <div style="font-size: 12px; color: #8a8172; margin-top: 4px">Enter a negative number for debt you owe</div>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Type *</label>
                    <div style="display: flex; gap: 8px">
                        <button v-for="t in typeOptions" :key="t.value" type="button" @click="form.type = t.value"
                            style="flex: 1; height: 38px; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; border: 1px solid"
                            :style="{
                                borderColor: form.type === t.value ? 'var(--hab-accent)' : '#e0dacd',
                                background: form.type === t.value ? 'color-mix(in srgb, var(--hab-accent) 12%, transparent)' : '#fffdf8',
                                color: form.type === t.value ? 'var(--hab-accent-deep)' : '#6b6355',
                                fontWeight: form.type === t.value ? 600 : 500,
                            }">
                            {{ t.label }}
                        </button>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="closeModal"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingAccount ? 'Save changes' : 'Add account' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
    </div>
</template>
