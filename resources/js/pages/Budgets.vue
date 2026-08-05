<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, reactive, nextTick } from 'vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { onClickOutside } from '@vueuse/core';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { index } from '@/actions/App/Http/Controllers/BudgetController';
import { store, update, destroy } from '@/actions/App/Http/Controllers/BudgetCategoryController';
import { store as storeRecurringBill, update as updateRecurringBill, destroy as destroyRecurringBill } from '@/actions/App/Http/Controllers/RecurringBillController';
import { store as storeTransaction, update as updateTransaction, destroy as destroyTransaction } from '@/actions/App/Http/Controllers/TransactionController';

type Category = {
    id: number;
    name: string;
    group: string | null;
    icon: string;
    color: string;
    limit_amount: number;
    spent: number;
};

type Transaction = {
    id: number;
    type: 'expense' | 'income' | 'transfer';
    amount: number;
    merchant: string | null;
    transacted_at: string;
    account: { id: number; name: string };
    to_account_id: number | null;
    category: { id: number; name: string; icon: string; color: string } | null;
};

type Account = {
    id: number;
    name: string;
    institution: string | null;
    balance: number;
    type: string;
};

type Cycle = {
    start: string;
    end: string;
    label: string;
    budget: number;
    daysLeft: number;
};

type TodayContext = {
    date: string;
    tasksLeft: number;
    eventsCount: number;
};

type UpcomingBill = {
    id: string;
    name: string;
    icon: string;
    color: string;
    bg: string;
    amount: number;
    dueLabel: string;
    soon: boolean;
    type: 'set_aside' | 'recurring';
    day_of_month?: number;
    budget_category_id?: number | null;
    category: { id: number; name: string; color: string } | null;
};

type RecurringBill = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    amount: number;
    day_of_month: number;
    budget_category_id: number | null;
    category: { id: number; name: string; color: string } | null;
};

const props = defineProps<{
    categories: Category[];
    transactions: Transaction[];
    allTransactions: Transaction[];
    accounts: Account[];
    cycle: Cycle;
    totalSpent: number;
    safeToSpend: number;
    dailyBudget: number;
    prevCycleStart: string;
    nextCycleStart: string;
    isCurrentCycle: boolean;
    todayContext: TodayContext;
    saPerMonth: number;
    saCount: number;
    upcomingBills: UpcomingBill[];
    recurringBills: RecurringBill[];
    readyToAssign: number;
    prevCycleLeftover: number;
}>();

// ── Cycle navigation ──────────────────────────────────────────────────────────

const detailsView = ref<'bars' | 'details' | 'bills'>('bars');

// ── Category transactions panel ───────────────────────────────────────────────

const catTxOpen = ref(false);
const catTxCategory = ref<Category | null>(null);

const catTxList = computed(() =>
    catTxCategory.value
        ? props.transactions.filter(tx => tx.category?.id === catTxCategory.value!.id)
        : [],
);

function openCatTransactions(cat: Category) {
    catTxCategory.value = cat;
    catTxOpen.value = true;
}

function navigateCycle(start: string) {
    router.get(index({ query: { cycle_start: start } }).url, {}, { preserveScroll: false });
}

// ── Formatters ────────────────────────────────────────────────────────────────

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? '-' + formatted : formatted;
}

function formatDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

// ── Hero computeds ────────────────────────────────────────────────────────────

const isCalendarMonth = computed(() => {
    const start = new Date(props.cycle.start + 'T00:00:00');
    const end = new Date(props.cycle.end + 'T00:00:00');
    const lastDay = new Date(start.getFullYear(), start.getMonth() + 1, 0);
    return start.getDate() === 1 && end.getDate() === lastDay.getDate() && start.getMonth() === end.getMonth();
});

const spentPct = computed(() => {
    if (!props.cycle.budget) { return 0; }
    return Math.min(100, Math.round((props.totalSpent / props.cycle.budget) * 100));
});

const moodChip = computed(() => {
    const pct = spentPct.value;
    if (pct >= 100) { return { emoji: '😬', label: 'Over budget' }; }
    if (pct >= 85) { return { emoji: '👀', label: 'Getting close' }; }
    if (pct >= 60) { return { emoji: '👍', label: 'On track' }; }
    return { emoji: '😎', label: 'Cruising' };
});

const billsTotal = computed(() => props.upcomingBills.reduce((s, b) => s + b.amount, 0));

const totalAccountBalance = computed(() => props.accounts.reduce((s, a) => s + a.balance, 0));
const totalSaSaved = computed(() => totalAccountBalance.value - props.readyToAssign - props.cycle.budget);

// ── Grouped categories ────────────────────────────────────────────────────────

const groupedCategories = computed(() => {
    const groups: Record<string, Category[]> = {};
    for (const cat of props.categories) {
        const key = cat.group ?? '';
        if (!groups[key]) { groups[key] = []; }
        groups[key].push(cat);
    }
    // Sort: named groups first (alphabetically), then ungrouped
    return Object.entries(groups).sort(([a], [b]) => {
        if (a === '' && b !== '') { return 1; }
        if (a !== '' && b === '') { return -1; }
        return a.localeCompare(b);
    });
});

// ── Category CRUD ─────────────────────────────────────────────────────────────

const ICON_OPTIONS = [
    'shopping_cart', 'restaurant', 'directions_car', 'movie', 'favorite',
    'home', 'bolt', 'phone_iphone', 'local_pharmacy', 'checkroom',
    'school', 'flight', 'fitness_center', 'pets', 'subscriptions',
    'coffee', 'spa', 'sports_esports', 'celebration', 'card_giftcard',
    'build', 'brush', 'music_note', 'child_care', 'savings',
];

const COLOR_OPTIONS = [
    '#2f7d5b', '#3f7fb0', '#a85fc0', '#d98a2b', '#c0512c', '#6b6355',
    '#1e7e6b', '#2e5fa3', '#7c3aad', '#b8602a', '#9b3a1f', '#4a5568',
];

const catModalOpen = ref(false);
const editingCat = ref<Category | null>(null);
const catForm = reactive({ name: '', group: '', icon: 'shopping_cart', color: '#2f7d5b', limit_amount: '' });
const deletingCatId = ref<number | null>(null);
let deleteTimer: ReturnType<typeof setTimeout> | null = null;

function openAddCat() {
    editingCat.value = null;
    Object.assign(catForm, { name: '', group: '', icon: 'shopping_cart', color: '#2f7d5b', limit_amount: '' });
    catModalOpen.value = true;
}

function openEditCat(cat: Category) {
    editingCat.value = cat;
    Object.assign(catForm, {
        name: cat.name,
        group: cat.group ?? '',
        icon: cat.icon,
        color: cat.color,
        limit_amount: String(cat.limit_amount),
    });
    catModalOpen.value = true;
}

const catLimitEvaluated = computed(() => evaluateArithmetic(catForm.limit_amount));
const catLimitHasExpr = computed(() => hasArithmetic(catForm.limit_amount) && catLimitEvaluated.value !== null);

const txAmountEvaluated = computed(() => evaluateArithmetic(txForm.amount));
const txAmountHasExpr = computed(() => hasArithmetic(txForm.amount) && txAmountEvaluated.value !== null);

function submitCat() {
    const data = {
        name: catForm.name,
        group: catForm.group || null,
        icon: catForm.icon,
        color: catForm.color,
        limit_amount: (catLimitEvaluated.value ?? parseFloat(catForm.limit_amount)) || 0,
    };
    if (editingCat.value) {
        router.patch(update({ budgetCategory: editingCat.value.id }).url, data, {
            onSuccess: () => { catModalOpen.value = false; },
            preserveScroll: true,
        });
    } else {
        router.post(store().url, data, {
            onSuccess: () => { catModalOpen.value = false; },
            preserveScroll: true,
        });
    }
}

function startDeleteCat(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deletingCatId.value = id;
    deleteTimer = setTimeout(() => { deletingCatId.value = null; }, 3000);
}

function confirmDeleteCat(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deletingCatId.value = null;
    router.delete(destroy({ budgetCategory: id }).url, { preserveScroll: true });
}

function cancelDeleteCat() {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deletingCatId.value = null;
}

// ── Recurring bill CRUD ───────────────────────────────────────────────────────

const billModalOpen = ref(false);
const editingBill = ref<{ id: number; name: string; amount: number; day_of_month: number; budget_category_id: number | null } | null>(null);
const billForm = reactive({ name: '', amount: '', day_of_month: '1', budget_category_id: null as number | null });

// Category searchable dropdown for bill modal
const billCatSearch = ref('');
const billCatOpen = ref(false);
const billCatContainerRef = ref<HTMLElement | null>(null);
const billCatInputRef = ref<HTMLInputElement | null>(null);

const billSelectedCategory = computed(() =>
    billForm.budget_category_id ? (props.categories.find((c) => c.id === billForm.budget_category_id) ?? null) : null,
);

const billFilteredCategories = computed(() => {
    const q = billCatSearch.value.toLowerCase().trim();
    if (!q) { return props.categories; }
    return props.categories.filter((c) => c.name.toLowerCase().includes(q));
});

function openBillCatDropdown() {
    billCatSearch.value = '';
    billCatOpen.value = true;
    nextTick(() => billCatInputRef.value?.focus());
}

function selectBillCategory(cat: Category) {
    billForm.budget_category_id = cat.id;
    billCatSearch.value = cat.name;
    billCatOpen.value = false;
}

function clearBillCategory() {
    billForm.budget_category_id = null;
    billCatSearch.value = '';
    billCatOpen.value = false;
}

onClickOutside(billCatContainerRef, () => {
    billCatOpen.value = false;
    billCatSearch.value = billSelectedCategory.value ? billSelectedCategory.value.name : '';
});

function openAddBill() {
    editingBill.value = null;
    Object.assign(billForm, { name: '', amount: '', day_of_month: '1', budget_category_id: null });
    billCatSearch.value = '';
    billCatOpen.value = false;
    billModalOpen.value = true;
}

function openEditBill(bill: UpcomingBill) {
    if (bill.type !== 'recurring') { return; }
    const idNum = parseInt(bill.id.replace('rb-', ''));
    editingBill.value = { id: idNum, name: bill.name, amount: bill.amount, day_of_month: bill.day_of_month ?? 1, budget_category_id: bill.budget_category_id ?? null };
    const initialCat = props.categories.find((c) => c.id === (bill.budget_category_id ?? null)) ?? null;
    Object.assign(billForm, {
        name: bill.name,
        amount: String(bill.amount),
        day_of_month: String(bill.day_of_month ?? 1),
        budget_category_id: bill.budget_category_id ?? null,
    });
    billCatSearch.value = initialCat ? initialCat.name : '';
    billCatOpen.value = false;
    billModalOpen.value = true;
}

function submitBill() {
    const data = {
        name: billForm.name,
        amount: parseFloat(billForm.amount) || 0,
        day_of_month: parseInt(String(billForm.day_of_month)) || 1,
        budget_category_id: billForm.budget_category_id,
    };
    if (editingBill.value) {
        router.patch(updateRecurringBill({ recurringBill: editingBill.value.id }).url, data, {
            onSuccess: () => { billModalOpen.value = false; },
            preserveScroll: true,
        });
    } else {
        router.post(storeRecurringBill().url, data, {
            onSuccess: () => { billModalOpen.value = false; },
            preserveScroll: true,
        });
    }
}

const deletingBillId = ref<string | null>(null);
let deleteBillTimer: ReturnType<typeof setTimeout> | null = null;

function startDeleteBill(id: string) {
    if (deleteBillTimer) { clearTimeout(deleteBillTimer); }
    deletingBillId.value = id;
    deleteBillTimer = setTimeout(() => { deletingBillId.value = null; }, 3000);
}

function confirmDeleteBill(id: string) {
    if (deleteBillTimer) { clearTimeout(deleteBillTimer); }
    deletingBillId.value = null;
    const idNum = parseInt(id.replace('rb-', ''));
    router.delete(destroyRecurringBill({ recurringBill: idNum }).url, { preserveScroll: true });
}

function cancelDeleteBill() {
    if (deleteBillTimer) { clearTimeout(deleteBillTimer); }
    deletingBillId.value = null;
}

// ── Recurring bills tab (all bills, direct CRUD) ──────────────────────────────

function ordinal(n: number): string {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return s[(v - 20) % 10] || s[v] || s[0];
}

const deletingRawBillId = ref<number | null>(null);
let deleteRawBillTimer: ReturnType<typeof setTimeout> | null = null;

function openEditBillDirect(bill: RecurringBill) {
    editingBill.value = {
        id: bill.id,
        name: bill.name,
        amount: bill.amount,
        day_of_month: bill.day_of_month,
        budget_category_id: bill.budget_category_id,
    };
    const initialCat = props.categories.find(c => c.id === bill.budget_category_id) ?? null;
    Object.assign(billForm, {
        name: bill.name,
        amount: String(bill.amount),
        day_of_month: String(bill.day_of_month),
        budget_category_id: bill.budget_category_id,
    });
    billCatSearch.value = initialCat ? initialCat.name : '';
    billCatOpen.value = false;
    billModalOpen.value = true;
}

function startDeleteRawBill(id: number) {
    if (deleteRawBillTimer) { clearTimeout(deleteRawBillTimer); }
    deletingRawBillId.value = id;
    deleteRawBillTimer = setTimeout(() => { deletingRawBillId.value = null; }, 3000);
}

function confirmDeleteRawBill(id: number) {
    if (deleteRawBillTimer) { clearTimeout(deleteRawBillTimer); }
    deletingRawBillId.value = null;
    router.delete(destroyRecurringBill({ recurringBill: id }).url, { preserveScroll: true });
}

function cancelDeleteRawBill() {
    if (deleteRawBillTimer) { clearTimeout(deleteRawBillTimer); }
    deletingRawBillId.value = null;
}

// ── Transaction edit ──────────────────────────────────────────────────────────

const txModalOpen = ref(false);
const editingTx = ref<Transaction | null>(null);
const payingBillId = ref<number | null>(null);
const txForm = reactive({
    type: 'expense' as 'expense' | 'income' | 'transfer',
    amount: '',
    merchant: '',
    account_id: 0,
    to_account_id: null as number | null,
    budget_category_id: null as number | null,
    transacted_at: '',
});

function openEditTx(tx: Transaction) {
    editingTx.value = tx;
    Object.assign(txForm, {
        type: tx.type,
        amount: String(tx.amount),
        merchant: tx.merchant ?? '',
        account_id: tx.account.id,
        to_account_id: tx.to_account_id,
        budget_category_id: tx.category?.id ?? null,
        transacted_at: tx.transacted_at,
    });
    txModalOpen.value = true;
}

function openPayBill(bill: UpcomingBill) {
    editingTx.value = null;
    payingBillId.value = bill.type === 'recurring' ? parseInt(bill.id.replace('rb-', '')) : null;
    Object.assign(txForm, {
        type: 'expense',
        amount: String(bill.amount),
        merchant: bill.name,
        account_id: props.accounts[0]?.id ?? 0,
        to_account_id: null,
        budget_category_id: bill.budget_category_id ?? null,
        transacted_at: props.todayContext.date,
    });
    txModalOpen.value = true;
}

function submitTxModal() {
    const amount = txAmountEvaluated.value ?? parseFloat(txForm.amount);
    const opts = { onSuccess: () => { txModalOpen.value = false; payingBillId.value = null; }, preserveScroll: true };

    if (editingTx.value) {
        const payload = {
            type: txForm.type,
            amount,
            merchant: txForm.merchant || null,
            account_id: txForm.account_id,
            to_account_id: txForm.type === 'transfer' ? txForm.to_account_id : null,
            budget_category_id: txForm.type === 'expense' ? txForm.budget_category_id : null,
            transacted_at: txForm.transacted_at,
        };
        router.patch(updateTransaction({ transaction: editingTx.value.id }).url, payload, opts);
    } else if (payingBillId.value) {
        const payload = {
            amount,
            merchant: txForm.merchant || null,
            account_id: txForm.account_id,
            budget_category_id: txForm.budget_category_id,
            transacted_at: txForm.transacted_at,
        };
        router.post(`/recurring-bills/${payingBillId.value}/pay`, payload, opts);
    } else {
        const payload = {
            type: txForm.type,
            amount,
            merchant: txForm.merchant || null,
            account_id: txForm.account_id,
            to_account_id: txForm.type === 'transfer' ? txForm.to_account_id : null,
            budget_category_id: txForm.type === 'expense' ? txForm.budget_category_id : null,
            transacted_at: txForm.transacted_at,
        };
        router.post(storeTransaction().url, payload, opts);
    }
}

// ── Transaction delete ────────────────────────────────────────────────────────

const deletingTxId = ref<number | null>(null);
let deleteTxTimer: ReturnType<typeof setTimeout> | null = null;

function startDeleteTx(id: number) {
    if (deleteTxTimer) { clearTimeout(deleteTxTimer); }
    deletingTxId.value = id;
    deleteTxTimer = setTimeout(() => { deletingTxId.value = null; }, 3000);
}

function confirmDeleteTx(id: number) {
    if (deleteTxTimer) { clearTimeout(deleteTxTimer); }
    deletingTxId.value = null;
    router.delete(destroyTransaction({ transaction: id }).url, { preserveScroll: true });
}

function cancelDeleteTx() {
    if (deleteTxTimer) { clearTimeout(deleteTxTimer); }
    deletingTxId.value = null;
}
</script>

<template>
    <Head title="Budgets" />

    <TooltipProvider :delay-duration="400">
    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">

        <!-- Today context strip -->
        <div style="display: flex; align-items: center; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 0 16px; height: 44px; font-size: 13px; color: #6b6355; overflow: hidden">
            <span class="material-symbols-rounded" style="font-size: 17px; color: #8a8172; margin-right: 7px">calendar_today</span>
            <span style="font-weight: 600; color: #4a4436">{{ todayContext.date }}</span>
            <span style="margin: 0 10px; color: #c3bbab">|</span>
            <span v-if="todayContext.eventsCount > 0">{{ todayContext.eventsCount }} event{{ todayContext.eventsCount !== 1 ? 's' : '' }} today</span>
            <span v-else>No events today</span>
            <span style="margin: 0 10px; color: #c3bbab">|</span>
            <span>{{ todayContext.tasksLeft }} task{{ todayContext.tasksLeft !== 1 ? 's' : '' }} left</span>
            <Link href="/today" style="margin-left: auto; display: flex; align-items: center; gap: 5px; color: var(--hab-accent); font-weight: 600; text-decoration: none; font-size: 13px">
                Open Today
                <span class="material-symbols-rounded" style="font-size: 16px">arrow_forward</span>
            </Link>
        </div>

        <!-- Page header + cycle switcher -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Budgets</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Where your money's going this month</p>
            </div>
            <div style="margin-left: auto; display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                <button @click="navigateCycle(prevCycleStart)"
                    style="width: 38px; height: 38px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                    <span class="material-symbols-rounded" style="font-size: 20px">chevron_left</span>
                </button>
                <span style="padding: 0 12px; font-size: 13.5px; font-weight: 600; color: #211d17; border-left: 1px solid #e0dacd; border-right: 1px solid #e0dacd; height: 38px; display: flex; align-items: center">
                    {{ cycle.label }}
                </span>
                <button @click="navigateCycle(nextCycleStart)"
                    :disabled="isCurrentCycle"
                    style="width: 38px; height: 38px; border: none; background: transparent; display: flex; align-items: center; justify-content: center; color: #6b6355"
                    :style="{ opacity: isCurrentCycle ? 0.35 : 1, cursor: isCurrentCycle ? 'default' : 'pointer' }">
                    <span class="material-symbols-rounded" style="font-size: 20px">chevron_right</span>
                </button>
            </div>
        </div>

        <!-- Hero + Upcoming bills -->
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_340px] items-start">

            <!-- Left column: hero + ready to assign -->
            <div style="display: flex; flex-direction: column; gap: 16px">

            <!-- Safe to spend hero -->
            <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
                <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
                <div style="position: relative">
                    <div style="font-size: 13.5px; font-weight: 500; color: rgba(255,255,255,.72)">Safe to spend this {{ isCurrentCycle && isCalendarMonth ? 'month' : 'cycle' }}</div>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <div style="cursor: help; font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em; margin-top: 8px"
                                :style="{ color: safeToSpend < 0 ? '#ffd27a' : '#fff' }">
                                {{ formatMoney(safeToSpend) }}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            {{ formatMoney(cycle.budget) }} budget − {{ formatMoney(totalSpent) }} spent{{ saPerMonth > 0 ? ` − ${formatMoney(saPerMonth)}/mo set-asides` : '' }}
                        </TooltipContent>
                    </Tooltip>
                    <div style="font-size: 14px; color: rgba(255,255,255,.7); margin-top: 8px">of {{ formatMoney(cycle.budget) }}</div>
                    <div style="margin-top: 16px; height: 10px; border-radius: 20px; background: rgba(255,255,255,.2); overflow: hidden">
                        <div style="height: 100%; border-radius: 20px; background: linear-gradient(90deg, #ffd27a, #ffe9a8); transition: width .5s cubic-bezier(.2,.8,.2,1)"
                            :style="{ width: spentPct + '%' }"></div>
                    </div>
                    <div style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px">
                        <span style="font-size: 13px; color: rgba(255,255,255,.7)">
                            Spent {{ formatMoney(totalSpent) }}
                            <span v-if="isCurrentCycle && cycle.budget > 0"> · {{ cycle.daysLeft }} day{{ cycle.daysLeft !== 1 ? 's' : '' }} left · <Tooltip><TooltipTrigger as-child><span style="cursor: help; text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 2px">{{ formatMoney(dailyBudget) }}/day</span></TooltipTrigger><TooltipContent>{{ formatMoney(safeToSpend) }} ÷ {{ cycle.daysLeft }} day{{ cycle.daysLeft !== 1 ? 's' : '' }} left</TooltipContent></Tooltip></span>
                        </span>
                        <div v-if="cycle.budget > 0" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 5px 11px; border-radius: 20px; font-size: 13px; white-space: nowrap">
                            {{ moodChip.emoji }} {{ moodChip.label }}
                        </div>
                    </div>
                    <div v-if="!cycle.budget" style="margin-top: 14px; background: rgba(255,255,255,.1); border-radius: 12px; padding: 12px 14px; font-size: 13px; color: rgba(255,255,255,.75)">
                        No budget set for this cycle yet.
                    </div>
                    <div v-if="saCount > 0" style="margin-top: 12px; display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: rgba(255,255,255,.72)">
                        <span class="material-symbols-rounded" style="font-size: 15px">lock</span>
                        <span>{{ formatMoney(saPerMonth) }}/mo tucked into {{ saCount }} set-aside{{ saCount !== 1 ? 's' : '' }} — already kept out of this number</span>
                    </div>
                </div>
            </div>

            <!-- Ready to assign -->
            <div v-if="cycle.budget > 0" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 20px; padding: 18px 24px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5); display: flex; align-items: center; justify-content: space-between">
                <div style="display: flex; align-items: center; gap: 8px; font-size: 14px; color: #6b6355; font-weight: 500">
                    <span class="material-symbols-rounded" style="font-size: 18px; color: #a09070">savings</span>
                    Ready to assign
                </div>
                <div style="display: flex; align-items: center; gap: 8px">
                    <span v-if="readyToAssign < 0" style="display: flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; color: #c0512c; background: #fdeee8; padding: 3px 10px; border-radius: 20px">
                        <span class="material-symbols-rounded" style="font-size: 14px">warning</span>
                        {{ formatMoney(Math.abs(readyToAssign)) }} over-assigned
                    </span>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <div style="cursor: help; font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 26px; letter-spacing: -.02em"
                                :style="{ color: readyToAssign < 0 ? '#c0512c' : '#2f7d5b' }">
                                {{ formatMoney(readyToAssign) }}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="left">
                            {{ formatMoney(totalAccountBalance) }} balance − {{ formatMoney(totalSaSaved) }} saved in set-asides − {{ formatMoney(cycle.budget) }} budgeted<template v-if="prevCycleLeftover > 0"> + {{ formatMoney(prevCycleLeftover) }} from last cycle</template>
                        </TooltipContent>
                    </Tooltip>
                </div>
            </div>

            </div><!-- end left column -->

            <!-- Upcoming bills -->
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 20px 20px 8px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px">
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px">Upcoming bills</div>
                    <div style="display: flex; align-items: center; gap: 8px">
                        <div v-if="upcomingBills.length" style="font-size: 12.5px; color: #a06a2b; font-weight: 600; background: #f8ecd6; padding: 2px 9px; border-radius: 20px">
                            {{ formatMoney(billsTotal) }} due
                        </div>
                        <button @click="openAddBill"
                            style="width: 28px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                            <span class="material-symbols-rounded" style="font-size: 16px">add</span>
                        </button>
                    </div>
                </div>

                <!-- Zero state -->
                <div v-if="!upcomingBills.length" style="display: flex; flex-direction: column; align-items: center; padding: 16px 0 12px; gap: 8px">
                    <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">receipt_long</span>
                    <div style="font-size: 13.5px; color: #8a8172; text-align: center">No upcoming bills</div>
                    <button @click="openAddBill" style="display: flex; align-items: center; gap: 5px; height: 32px; padding: 0 12px; border: none; background: #211d17; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; color: #fdfbf5; margin-top: 4px">
                        <span class="material-symbols-rounded" style="font-size: 15px">add</span> Add a bill
                    </button>
                </div>

                <!-- Bill list -->
                <div v-else style="display: flex; flex-direction: column">
                    <div v-for="bill in upcomingBills" :key="bill.id" class="group/bill"
                        style="display: flex; align-items: center; gap: 12px; padding: 11px 0; border-top: 1px solid #efe9dc">
                        <div :style="{ background: bill.bg }"
                            style="width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" :style="{ color: bill.color }" style="font-size: 21px">{{ bill.icon }}</span>
                        </div>
                        <div style="min-width: 0; flex: 1">
                            <div style="font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #211d17">{{ bill.name }}</div>
                            <div style="display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap">
                                <span style="font-size: 12px" :style="{ color: bill.soon ? '#c0512c' : '#8a8172' }">{{ bill.dueLabel }}</span>
                                <span v-if="bill.category" style="font-size: 11px; font-weight: 600; border-radius: 5px; padding: 1px 6px"
                                    :style="{ background: bill.category.color + '22', color: bill.category.color }">
                                    {{ bill.category.name }}
                                </span>
                            </div>
                        </div>
                        <!-- Amount + pay/edit/delete for recurring bills -->
                        <template v-if="bill.type === 'recurring' && deletingBillId !== bill.id">
                            <div class="flex items-center flex-none">
                                <div style="font-weight: 700; font-size: 14px; font-family: 'Bricolage Grotesque'; color: #211d17">{{ formatMoney(bill.amount) }}</div>
                                <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/bill:max-w-[100px] flex-shrink-0">
                                    <div class="flex items-center gap-1" style="padding-left: 8px">
                                        <button @click.stop="openPayBill(bill)"
                                            style="width: 26px; height: 26px; border: 1px solid #d4edda; background: #d4edda; border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #2e7d46">
                                            <span class="material-symbols-rounded" style="font-size: 14px">price_check</span>
                                        </button>
                                        <button @click.stop="openEditBill(bill)"
                                            style="width: 26px; height: 26px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                            <span class="material-symbols-rounded" style="font-size: 14px">edit</span>
                                        </button>
                                        <button @click.stop="startDeleteBill(bill.id)"
                                            style="width: 26px; height: 26px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                            <span class="material-symbols-rounded" style="font-size: 14px">delete</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template v-else-if="bill.type === 'recurring' && deletingBillId === bill.id">
                            <div style="display: flex; align-items: center; gap: 5px; flex: none">
                                <button @click="cancelDeleteBill"
                                    style="padding: 0 8px; height: 26px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 7px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 11.5px; font-weight: 600; color: #6b6355">
                                    Cancel
                                </button>
                                <button @click="confirmDeleteBill(bill.id)"
                                    style="padding: 0 8px; height: 26px; border: none; background: #c0512c; border-radius: 7px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 11.5px; font-weight: 600; color: #fff">
                                    Delete?
                                </button>
                            </div>
                        </template>
                        <template v-else>
                            <div style="font-weight: 700; font-size: 14px; font-family: 'Bricolage Grotesque'; color: #211d17; flex: none">{{ formatMoney(bill.amount) }}</div>
                        </template>
                    </div>
                </div>
            </div>
        </div>



        <!-- Where it's going -->
        <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 22px 26px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px">
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 17px">Where it's going</div>
                <div style="display: flex; align-items: center; gap: 10px">
                    <!-- Add category / Add bill button -->
                    <button v-if="detailsView !== 'bills'" @click="openAddCat"
                        style="display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                        <span class="material-symbols-rounded" style="font-size: 16px">add</span>
                        Add category
                    </button>
                    <button v-else @click="openAddBill"
                        style="display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                        <span class="material-symbols-rounded" style="font-size: 16px">add</span>
                        Add bill
                    </button>
                    <!-- Bars / Details / Bills toggle -->
                    <div style="display: flex; background: #f1ede3; border-radius: 10px; padding: 3px; gap: 2px">
                        <button @click="detailsView = 'bars'"
                            style="padding: 0 14px; height: 32px; border: none; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; transition: all .15s"
                            :style="{
                                background: detailsView === 'bars' ? '#fffdf8' : 'transparent',
                                color: detailsView === 'bars' ? '#211d17' : '#8a8172',
                                boxShadow: detailsView === 'bars' ? '0 1px 3px rgba(0,0,0,.08)' : 'none',
                            }">Bars</button>
                        <button @click="detailsView = 'details'"
                            style="padding: 0 14px; height: 32px; border: none; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; transition: all .15s"
                            :style="{
                                background: detailsView === 'details' ? '#fffdf8' : 'transparent',
                                color: detailsView === 'details' ? '#211d17' : '#8a8172',
                                boxShadow: detailsView === 'details' ? '0 1px 3px rgba(0,0,0,.08)' : 'none',
                            }">Details</button>
                        <button @click="detailsView = 'bills'"
                            style="padding: 0 14px; height: 32px; border: none; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; transition: all .15s"
                            :style="{
                                background: detailsView === 'bills' ? '#fffdf8' : 'transparent',
                                color: detailsView === 'bills' ? '#211d17' : '#8a8172',
                                boxShadow: detailsView === 'bills' ? '0 1px 3px rgba(0,0,0,.08)' : 'none',
                            }">Bills</button>
                    </div>
                </div>
            </div>

            <!-- Bars view (grouped) -->
            <div v-if="detailsView === 'bars'">
                <div v-if="!categories.length" style="display: flex; flex-direction: column; align-items: center; padding: 36px 0; gap: 10px">
                    <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">category</span>
                    <div style="font-size: 13.5px; color: #8a8172">No categories yet</div>
                    <button @click="openAddCat"
                        style="display: flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fdfbf5">
                        <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add your first category
                    </button>
                </div>

                <div v-else style="display: flex; flex-direction: column; gap: 24px">
                    <div v-for="([groupName, cats]) in groupedCategories" :key="groupName">
                        <!-- Group label -->
                        <div v-if="groupName" style="font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #a49b8a; margin-bottom: 12px">
                            {{ groupName }}
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 14px">
                            <div v-for="cat in cats" :key="cat.id" class="group/cat" style="display: flex; align-items: center; gap: 11px">
                                <span :style="{ background: cat.color + '22' }"
                                    style="width: 36px; height: 36px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex: none">
                                    <span class="msf" :style="{ color: cat.color }" style="font-size: 19px">{{ cat.icon }}</span>
                                </span>
                                <div style="flex: 1; min-width: 0">
                                    <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 8px; margin-bottom: 6px">
                                        <span style="font-weight: 600; font-size: 14px; color: #211d17">{{ cat.name }}</span>
                                        <div style="display: flex; align-items: center; gap: 6px; flex: none">
                                            <!-- Edit / delete (hover reveal) -->
                                            <template v-if="deletingCatId !== cat.id">
                                                <div class="flex items-center flex-none">
                                                    <div style="display: flex; align-items: baseline; gap: 4px">
                                                        <span style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 14px; color: #211d17">{{ formatMoney(cat.spent) }}</span>
                                                        <span style="font-size: 12px; color: #8a8172">/ {{ formatMoney(cat.limit_amount) }}</span>
                                                        <span v-if="cat.limit_amount > 0 && cat.spent > cat.limit_amount"
                                                            style="font-size: 11px; font-weight: 600; color: #c0512c; background: #fbe2d6; border-radius: 6px; padding: 1px 6px">over</span>
                                                    </div>
                                                    <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/cat:max-w-[104px] flex-shrink-0">
                                                        <div class="flex items-center gap-1" style="padding-left: 8px">
                                                            <button @click.stop="openCatTransactions(cat)"
                                                                title="View transactions"
                                                                style="width: 28px; height: 28px; border: 1px solid #dcebf5; background: #dcebf5; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #3f7fb0">
                                                                <span class="material-symbols-rounded" style="font-size: 15px">receipt_long</span>
                                                            </button>
                                                            <button @click.stop="openEditCat(cat)"
                                                                style="width: 28px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                                                <span class="material-symbols-rounded" style="font-size: 15px">edit</span>
                                                            </button>
                                                            <button @click.stop="startDeleteCat(cat.id)"
                                                                style="width: 28px; height: 28px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                                                <span class="material-symbols-rounded" style="font-size: 15px">delete</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                            <!-- Delete confirm -->
                                            <template v-else>
                                                <button @click="cancelDeleteCat"
                                                    style="padding: 0 10px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #6b6355">
                                                    Cancel
                                                </button>
                                                <button @click="confirmDeleteCat(cat.id)"
                                                    style="padding: 0 10px; height: 28px; border: none; background: #c0512c; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #fff">
                                                    Delete?
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                    <div style="height: 9px; border-radius: 20px; background: #efe9dc; overflow: hidden">
                                        <div style="height: 100%; border-radius: 20px; transition: width .5s cubic-bezier(.2,.8,.2,1)"
                                            :style="{
                                                width: cat.limit_amount > 0 ? Math.min(100, Math.round((cat.spent / cat.limit_amount) * 100)) + '%' : '0%',
                                                background: cat.spent > cat.limit_amount ? '#c0512c' : cat.color,
                                            }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Details view -->
            <div v-else-if="detailsView === 'details'">
                <div v-if="!allTransactions.length" style="display: flex; flex-direction: column; align-items: center; padding: 36px 0; gap: 8px">
                    <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">receipt</span>
                    <div style="font-size: 13.5px; color: #8a8172">No transactions logged yet</div>
                    <div style="font-size: 13px; color: #a49b8a">Use "Log spending" to record a transaction</div>
                </div>
                <div v-else style="display: flex; flex-direction: column">
                    <div v-for="(tx, i) in allTransactions" :key="tx.id" class="group/tx"
                        style="display: flex; align-items: center; gap: 13px; padding: 12px 0"
                        :style="{ borderTop: i > 0 ? '1px solid #efe9dc' : 'none' }">
                        <span v-if="tx.category" :style="{ background: tx.category.color + '22' }"
                            style="width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" :style="{ color: tx.category.color }" style="font-size: 20px">{{ tx.category.icon }}</span>
                        </span>
                        <span v-else-if="tx.type === 'transfer'"
                            style="width: 38px; height: 38px; border-radius: 11px; background: #f1ede3; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="material-symbols-rounded" style="font-size: 20px; color: #8a8172">swap_horiz</span>
                        </span>
                        <span v-else-if="tx.type === 'income'"
                            style="width: 38px; height: 38px; border-radius: 11px; background: #dff3e6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 20px; color: #2f7d5b">south_west</span>
                        </span>
                        <span v-else style="width: 38px; height: 38px; border-radius: 11px; background: #f1ede3; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="material-symbols-rounded" style="font-size: 20px; color: #8a8172">receipt</span>
                        </span>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-weight: 600; font-size: 14px; color: #211d17">{{ tx.merchant || 'No merchant' }}</div>
                            <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px">
                                <span v-if="tx.category" style="font-size: 11.5px; font-weight: 600; border-radius: 6px; padding: 2px 8px"
                                    :style="{ background: tx.category.color + '22', color: tx.category.color }">
                                    {{ tx.category.name }}
                                </span>
                                <span style="font-size: 12px; color: #a49b8a">{{ formatDate(tx.transacted_at) }} · {{ tx.account.name }}</span>
                            </div>
                        </div>
                        <!-- Delete controls -->
                        <template v-if="deletingTxId !== tx.id">
                            <div class="flex items-center flex-none">
                                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px; color: #211d17">
                                    {{ formatMoney(tx.amount) }}
                                </div>
                                <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/tx:max-w-[68px] flex-shrink-0">
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
                            <button @click="cancelDeleteTx"
                                style="padding: 0 10px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #6b6355; flex: none">
                                Cancel
                            </button>
                            <button @click="confirmDeleteTx(tx.id)"
                                style="padding: 0 10px; height: 28px; border: none; background: #c0512c; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #fff; flex: none">
                                Delete?
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Bills view -->
            <div v-else-if="detailsView === 'bills'">
                <div v-if="!recurringBills.length" style="display: flex; flex-direction: column; align-items: center; padding: 36px 0; gap: 10px">
                    <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">receipt_long</span>
                    <div style="font-size: 13.5px; color: #8a8172">No recurring bills yet</div>
                    <button @click="openAddBill"
                        style="display: flex; align-items: center; gap: 5px; height: 36px; padding: 0 14px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fdfbf5; margin-top: 4px">
                        <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add your first bill
                    </button>
                </div>
                <div v-else style="display: flex; flex-direction: column">
                    <div v-for="bill in recurringBills" :key="bill.id" class="group/rbill"
                        style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-top: 1px solid #efe9dc">
                        <div :style="{ background: bill.bg }"
                            style="width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" :style="{ color: bill.color }" style="font-size: 21px">{{ bill.icon }}</span>
                        </div>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-weight: 600; font-size: 14px; color: #211d17; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ bill.name }}</div>
                            <div style="display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap">
                                <span style="font-size: 12px; color: #8a8172">{{ bill.day_of_month }}{{ ordinal(bill.day_of_month) }} of each month</span>
                                <span v-if="bill.category" style="font-size: 11px; font-weight: 600; border-radius: 5px; padding: 1px 6px"
                                    :style="{ background: bill.category.color + '22', color: bill.category.color }">
                                    {{ bill.category.name }}
                                </span>
                            </div>
                        </div>
                        <template v-if="deletingRawBillId !== bill.id">
                            <div class="flex items-center flex-none">
                                <div style="font-weight: 700; font-size: 14px; font-family: 'Bricolage Grotesque'; color: #211d17">{{ formatMoney(bill.amount) }}</div>
                                <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/rbill:max-w-[68px] flex-shrink-0">
                                    <div class="flex items-center gap-1" style="padding-left: 8px">
                                        <button @click.stop="openEditBillDirect(bill)"
                                            style="width: 26px; height: 26px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                            <span class="material-symbols-rounded" style="font-size: 14px">edit</span>
                                        </button>
                                        <button @click.stop="startDeleteRawBill(bill.id)"
                                            style="width: 26px; height: 26px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                            <span class="material-symbols-rounded" style="font-size: 14px">delete</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div style="display: flex; align-items: center; gap: 5px; flex: none">
                                <button @click="cancelDeleteRawBill"
                                    style="padding: 0 8px; height: 26px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 7px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 11.5px; font-weight: 600; color: #6b6355">
                                    Cancel
                                </button>
                                <button @click="confirmDeleteRawBill(bill.id)"
                                    style="padding: 0 8px; height: 26px; border: none; background: #c0512c; border-radius: 7px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 11.5px; font-weight: 600; color: #fff">
                                    Delete?
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Category transactions modal -->
    <Dialog v-model:open="catTxOpen">
        <DialogContent style="max-width: 520px; max-height: 80vh; display: flex; flex-direction: column">
            <DialogHeader>
                <DialogTitle style="display: flex; align-items: center; gap: 10px">
                    <span v-if="catTxCategory" :style="{ background: catTxCategory.color + '22' }"
                        style="width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: catTxCategory.color }" style="font-size: 17px">{{ catTxCategory.icon }}</span>
                    </span>
                    {{ catTxCategory?.name }} · {{ cycle.label }}
                </DialogTitle>
            </DialogHeader>

            <!-- Empty state -->
            <div v-if="!catTxList.length" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 0; gap: 8px">
                <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">receipt_long</span>
                <div style="font-size: 13.5px; color: #8a8172">No transactions this cycle</div>
            </div>

            <!-- Transaction list -->
            <div v-else style="overflow-y: auto; margin: 0 -24px; padding: 0 24px">
                <div v-for="(tx, i) in catTxList" :key="tx.id" class="group/ctx"
                    style="display: flex; align-items: center; gap: 12px; padding: 12px 0"
                    :style="{ borderTop: i > 0 ? '1px solid #efe9dc' : 'none' }">
                    <span :style="{ background: tx.category ? tx.category.color + '22' : '#f1ede3' }"
                        style="width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: tx.category?.color ?? '#8a8172' }" style="font-size: 19px">
                            {{ tx.category?.icon ?? 'receipt' }}
                        </span>
                    </span>
                    <div style="flex: 1; min-width: 0">
                        <div style="font-weight: 600; font-size: 14px; color: #211d17">{{ tx.merchant || 'No description' }}</div>
                        <div style="font-size: 12px; color: #8a8172; margin-top: 2px">{{ formatDate(tx.transacted_at) }} · {{ tx.account.name }}</div>
                    </div>
                    <template v-if="deletingTxId !== tx.id">
                        <div class="flex items-center flex-none">
                            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #211d17">
                                {{ formatMoney(tx.amount) }}
                            </div>
                            <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/ctx:max-w-[76px] flex-shrink-0">
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

    <!-- Add / Edit transaction modal -->
    <Dialog v-model:open="txModalOpen">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>{{ editingTx ? 'Edit transaction' : 'Log payment' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitTxModal" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Type -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Type</label>
                    <div style="display: flex; gap: 6px">
                        <button v-for="t in (['expense', 'income', 'transfer'] as const)" :key="t" type="button" @click="txForm.type = t"
                            style="flex: 1; height: 36px; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; border: 1px solid; text-transform: capitalize"
                            :style="{
                                borderColor: txForm.type === t ? 'var(--hab-accent)' : '#e0dacd',
                                background: txForm.type === t ? 'color-mix(in srgb, var(--hab-accent) 12%, transparent)' : '#fffdf8',
                                color: txForm.type === t ? 'var(--hab-accent-deep)' : '#6b6355',
                                fontWeight: txForm.type === t ? 600 : 500,
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
                            <input v-model="txForm.amount" type="text" inputmode="decimal" required
                                style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17" />
                        <div v-if="txAmountHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= ₱{{ txAmountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Date *</label>
                        <input v-model="txForm.transacted_at" type="date" required
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>

                <!-- Merchant (not for transfer) -->
                <div v-if="txForm.type !== 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Merchant</label>
                    <input v-model="txForm.merchant" placeholder="e.g. Jollibee"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                </div>

                <!-- Account -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">{{ txForm.type === 'transfer' ? 'From account' : 'Account' }} *</label>
                    <select v-model="txForm.account_id" required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none">
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>

                <!-- To account (transfer only) -->
                <div v-if="txForm.type === 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">To account *</label>
                    <select v-model="txForm.to_account_id" required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none">
                        <option v-for="a in accounts.filter(a => a.id !== txForm.account_id)" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>

                <!-- Category (expense only) -->
                <div v-if="txForm.type === 'expense'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Category *</label>
                    <select v-model="txForm.budget_category_id" required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none; box-sizing: border-box">
                        <option :value="null" disabled>Select a category</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="txModalOpen = false; payingBillId = null"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingTx ? 'Save changes' : 'Log payment' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Add / Edit category modal -->
    <Dialog v-model:open="catModalOpen">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>{{ editingCat ? 'Edit category' : 'Add category' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitCat" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Name + Group row -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                        <input v-model="catForm.name" required placeholder="e.g. Groceries"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Group</label>
                        <input v-model="catForm.group" placeholder="e.g. Food, Home, Fun"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>

                <!-- Monthly limit -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Cycle limit *</label>
                    <div style="position: relative">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #6b6355; font-weight: 600">₱</span>
                        <input v-model="catForm.limit_amount" type="text" inputmode="decimal" required placeholder="0"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17" />
                        <div v-if="catLimitHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= ₱{{ catLimitEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                    </div>
                </div>

                <!-- Color picker -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Color</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px">
                        <button v-for="c in COLOR_OPTIONS" :key="c" type="button" @click="catForm.color = c"
                            style="width: 28px; height: 28px; border-radius: 8px; border: 2.5px solid; cursor: pointer; transition: transform .1s"
                            :style="{
                                background: c,
                                borderColor: catForm.color === c ? '#211d17' : c,
                                transform: catForm.color === c ? 'scale(1.15)' : 'scale(1)',
                            }">
                        </button>
                    </div>
                </div>

                <!-- Icon picker -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Icon</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px">
                        <button v-for="ic in ICON_OPTIONS" :key="ic" type="button" @click="catForm.icon = ic"
                            style="width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .1s"
                            :style="{
                                background: catForm.icon === ic ? catForm.color + '20' : '#f1ede3',
                                borderColor: catForm.icon === ic ? catForm.color : 'transparent',
                            }">
                            <span class="msf" style="font-size: 18px" :style="{ color: catForm.icon === ic ? catForm.color : '#8a8172' }">{{ ic }}</span>
                        </button>
                    </div>
                </div>

                <!-- Preview -->
                <div style="display: flex; align-items: center; gap: 10px; background: #f7f4ee; border-radius: 12px; padding: 12px 14px">
                    <span :style="{ background: catForm.color + '22' }" style="width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: catForm.color }" style="font-size: 21px">{{ catForm.icon }}</span>
                    </span>
                    <div>
                        <div style="font-weight: 600; font-size: 14px; color: #211d17">{{ catForm.name || 'Category name' }}</div>
                        <div style="font-size: 12px; color: #8a8172; margin-top: 1px">
                            <span v-if="catForm.group">{{ catForm.group }} · </span>
                            Limit ₱{{ catForm.limit_amount || '0' }}
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="catModalOpen = false"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingCat ? 'Save changes' : 'Add category' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Add / Edit recurring bill modal -->
    <Dialog v-model:open="billModalOpen">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>{{ editingBill ? 'Edit bill' : 'Add recurring bill' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitBill" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Name + Day of month -->
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: end">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                        <input v-model="billForm.name" required placeholder="e.g. Netflix, Rent"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Due day *</label>
                        <div style="display: flex; align-items: center; gap: 6px">
                            <input v-model="billForm.day_of_month" type="number" min="1" max="31" required
                                style="width: 72px; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 10px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17; text-align: center" />
                            <span style="font-size: 13px; color: #8a8172; white-space: nowrap">of month</span>
                        </div>
                    </div>
                </div>

                <!-- Amount -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Amount *</label>
                    <div style="position: relative">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #6b6355; font-weight: 600">₱</span>
                        <input v-model="billForm.amount" type="text" inputmode="decimal" required placeholder="0.00"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>

                <!-- Budget category -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Budget category</label>
                    <div v-if="!categories.length" style="font-size: 13px; color: #8a8172; padding: 10px 0">
                        No categories yet — add some on the <a href="/budgets" style="color: var(--hab-accent)">Budgets page</a>
                    </div>
                    <div v-else ref="billCatContainerRef" style="position: relative">
                        <!-- Trigger -->
                        <div
                            style="display: flex; align-items: center; gap: 8px; width: 100%; height: 40px; border: 1px solid; border-radius: 10px; padding: 0 10px 0 12px; background: #fffdf8; cursor: text; transition: border-color .12s"
                            :style="{ borderColor: billCatOpen ? '#211d17' : '#e0dacd' }"
                            @click="openBillCatDropdown"
                        >
                            <span v-if="billSelectedCategory" class="msf" :style="{ color: billSelectedCategory.color }" style="font-size: 16px; flex-shrink: 0">{{ billSelectedCategory.icon }}</span>
                            <input
                                ref="billCatInputRef"
                                v-model="billCatSearch"
                                type="text"
                                :placeholder="billSelectedCategory ? billSelectedCategory.name : 'Search categories…'"
                                style="flex: 1; min-width: 0; background: transparent; border: none; outline: none; font-family: 'Instrument Sans'; font-size: 14px; color: #211d17"
                                @focus="openBillCatDropdown"
                                @input="billCatOpen = true"
                            />
                            <button v-if="billSelectedCategory" type="button" @click.stop="clearBillCategory"
                                style="display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 20px; height: 20px; border: none; background: none; cursor: pointer; padding: 0; color: #8a8172">
                                <span class="material-symbols-rounded" style="font-size: 16px">close</span>
                            </button>
                            <span v-else class="material-symbols-rounded" style="font-size: 16px; color: #8a8172; flex-shrink: 0; pointer-events: none">expand_more</span>
                        </div>
                        <!-- Dropdown list -->
                        <div v-if="billCatOpen && billFilteredCategories.length > 0"
                            style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 10px; z-index: 100; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,.08)">
                            <div style="max-height: 200px; overflow-y: auto">
                                <button v-for="cat in billFilteredCategories" :key="cat.id" type="button"
                                    @mousedown.prevent="selectBillCategory(cat)"
                                    style="display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 14px; border: none; cursor: pointer; font-family: 'Instrument Sans'; font-size: 14px; text-align: left; transition: background .08s"
                                    :style="{ background: billForm.budget_category_id === cat.id ? '#f5f0e8' : 'transparent', color: '#4a4436' }"
                                    @mouseenter="($event.currentTarget as HTMLElement).style.background = '#f5f0e8'"
                                    @mouseleave="($event.currentTarget as HTMLElement).style.background = billForm.budget_category_id === cat.id ? '#f5f0e8' : 'transparent'"
                                >
                                    <span class="msf" :style="{ color: cat.color }" style="font-size: 16px; flex-shrink: 0">{{ cat.icon }}</span>
                                    <span style="flex: 1">{{ cat.name }}</span>
                                    <span v-if="billForm.budget_category_id === cat.id" class="material-symbols-rounded" style="font-size: 16px; color: #211d17">check</span>
                                </button>
                            </div>
                        </div>
                        <!-- No results -->
                        <div v-if="billCatOpen && billCatSearch && billFilteredCategories.length === 0"
                            style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 10px; z-index: 100; padding: 12px 14px; font-family: 'Instrument Sans'; font-size: 13px; color: #8a8172">
                            No matching categories
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="billModalOpen = false"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingBill ? 'Save changes' : 'Add bill' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
    </TooltipProvider>
</template>
