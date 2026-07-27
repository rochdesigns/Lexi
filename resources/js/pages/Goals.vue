<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, reactive, watch } from 'vue';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy, topUp as topUpAction } from '@/actions/App/Http/Controllers/GoalController';
import { update as updateTransaction, destroy as destroyTransaction } from '@/actions/App/Http/Controllers/TransactionController';

type GoalTransaction = {
    id: number;
    amount: number;
    merchant: string | null;
    transacted_at: string;
    account: { id: number; name: string };
};

type Goal = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    saved: number;
    target: number;
    per_month: number;
    by_date: string | null;
    position: number;
    transactions: GoalTransaction[];
};

type Account = {
    id: number;
    name: string;
    type: string;
};

const props = defineProps<{
    goals: Goal[];
    goalTotal: number;
    goalTarget: number;
    goalPerMonth: number;
    accounts: Account[];
}>();

const COLOR_PAIRS = [
    { color: '#3f7fb0', bg: '#dcebf5' },
    { color: '#a85fc0', bg: '#efe0f5' },
    { color: '#2f7d5b', bg: '#dff3e6' },
    { color: '#d98a2b', bg: '#f8ecd6' },
    { color: '#c0512c', bg: '#fbe2d6' },
    { color: '#6b6355', bg: '#eee9dd' },
];

const ICONS = [
    'flight', 'laptop_mac', 'health_and_safety', 'home', 'directions_car',
    'school', 'beach_access', 'celebration', 'shopping_bag', 'savings',
    'restaurant', 'sports', 'music_note', 'volunteer_activism', 'pets',
    'gift', 'medical_services', 'account_balance', 'build', 'smartphone',
    'inventory_2', 'local_shipping', 'confirmation_number', 'shield', 'subscriptions',
];

const formatMoney = (n: number) => '₱' + Math.round(n).toLocaleString('en-PH');

const heroPct = computed(() => {
    if (!props.goalTarget) return 0;
    return Math.min(100, Math.round((props.goalTotal / props.goalTarget) * 100));
});

// ── CRUD modal ─────────────────────────────────────────────────────────────────

const modalOpen = ref(false);
const editingGoal = ref<Goal | null>(null);

const form = useForm({
    name: '',
    icon: 'flight',
    color: '#3f7fb0',
    bg: '#dcebf5',
    saved: '' as number | string,
    target: '' as number | string,
    per_month: '' as number | string,
    by_date: '',
});

watch([() => form.target, () => form.saved, () => form.by_date], ([target, saved, byDate]) => {
    const t = evaluateArithmetic(String(target));
    const s = evaluateArithmetic(String(saved)) ?? 0;
    if (!byDate || !t) return;
    const months = Math.max(1, Math.ceil(
        (new Date(byDate + 'T00:00:00').getTime() - Date.now()) / (1000 * 60 * 60 * 24 * 30.44)
    ));
    const needed = Math.ceil(Math.max(0, t - s) / months);
    if (needed > 0) form.per_month = needed;
});

function openCreate() {
    editingGoal.value = null;
    form.reset();
    modalOpen.value = true;
}

function openEdit(goal: Goal) {
    editingGoal.value = goal;
    form.name = goal.name;
    form.icon = goal.icon;
    form.color = goal.color;
    form.bg = goal.bg;
    form.saved = String(goal.saved);
    form.target = String(goal.target);
    form.per_month = String(goal.per_month);
    form.by_date = goal.by_date ?? '';
    form.clearErrors();
    modalOpen.value = true;
}

function submitForm() {
    form.transform((data) => ({
        ...data,
        target: (evaluateArithmetic(String(data.target)) ?? parseFloat(String(data.target))) || 0,
        saved: (evaluateArithmetic(String(data.saved)) ?? parseFloat(String(data.saved))) || 0,
        per_month: (evaluateArithmetic(String(data.per_month)) ?? parseFloat(String(data.per_month))) || 0,
    }));
    if (editingGoal.value) {
        form.patch(update(editingGoal.value.id).url, {
            onSuccess: () => { modalOpen.value = false; },
        });
    } else {
        form.post(store().url, {
            onSuccess: () => { modalOpen.value = false; form.reset(); },
        });
    }
}

function selectColorPair(pair: { color: string; bg: string }) {
    form.color = pair.color;
    form.bg = pair.bg;
}

// ── Two-step delete ────────────────────────────────────────────────────────────

const deleteId = ref<number | null>(null);
let deleteTimer: ReturnType<typeof setTimeout> | null = null;

function startDelete(goal: Goal) {
    if (deleteId.value === goal.id) {
        clearTimeout(deleteTimer!);
        deleteId.value = null;
        router.delete(destroy(goal.id).url);
    } else {
        if (deleteTimer) clearTimeout(deleteTimer);
        deleteId.value = goal.id;
        deleteTimer = setTimeout(() => { deleteId.value = null; }, 3000);
    }
}

function cancelDelete() {
    if (deleteTimer) clearTimeout(deleteTimer);
    deleteId.value = null;
}

// ── Top-up modal ───────────────────────────────────────────────────────────────

const topUpModalOpen = ref(false);
const toppingUpGoal = ref<Goal | null>(null);
const topUpForm = useForm({
    amount: '' as string,
    account_id: 0 as number,
    transacted_at: '',
});

function openTopUp(goal: Goal) {
    toppingUpGoal.value = goal;
    topUpForm.reset();
    topUpForm.amount = '';
    topUpForm.account_id = props.accounts[0]?.id ?? 0;
    topUpForm.transacted_at = new Date().toISOString().split('T')[0];
    topUpForm.clearErrors();
    topUpModalOpen.value = true;
}

function submitTopUp() {
    if (!toppingUpGoal.value) return;
    topUpForm
        .transform((data) => ({
            ...data,
            amount: evaluateArithmetic(data.amount) ?? parseFloat(data.amount),
        }))
        .post(topUpAction(toppingUpGoal.value.id).url, {
            onSuccess: () => { topUpModalOpen.value = false; },
        });
}

// ── Transaction edit/delete ────────────────────────────────────────────────────

const txModalOpen = ref(false);
const editingTx = ref<GoalTransaction | null>(null);
const editingTxGoalId = ref<number | null>(null);
const txForm = reactive({ amount: '', merchant: '', account_id: 0, transacted_at: '' });

const txAmountEvaluated = computed(() => evaluateArithmetic(txForm.amount));
const txAmountHasExpr = computed(() => hasArithmetic(txForm.amount) && txAmountEvaluated.value !== null);

function openEditTx(tx: GoalTransaction, goalId: number) {
    editingTx.value = tx;
    editingTxGoalId.value = goalId;
    Object.assign(txForm, {
        amount: String(tx.amount),
        merchant: tx.merchant ?? '',
        account_id: tx.account.id,
        transacted_at: tx.transacted_at,
    });
    txModalOpen.value = true;
}

function submitEditTx() {
    if (!editingTx.value) { return; }
    router.patch(updateTransaction({ transaction: editingTx.value.id }).url, {
        type: 'expense',
        amount: txAmountEvaluated.value ?? parseFloat(txForm.amount),
        merchant: txForm.merchant || null,
        account_id: txForm.account_id,
        transacted_at: txForm.transacted_at,
        goal_id: editingTxGoalId.value,
    }, {
        onSuccess: () => { txModalOpen.value = false; },
        preserveScroll: true,
    });
}

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

// ── Per-goal helpers ───────────────────────────────────────────────────────────

function goalPct(goal: Goal) {
    return Math.min(100, Math.round((goal.saved / goal.target) * 100));
}

function goalStatus(goal: Goal): { label: string; color: string; bg: string } {
    const pct = goalPct(goal);
    if (pct >= 100) return { label: 'Goal reached!', color: '#2f7d5b', bg: '#dff3e6' };
    if (pct >= 90) return { label: 'Almost there', color: '#a06a2b', bg: '#f8ecd6' };
    return { label: pct + '% there', color: '#8a8172', bg: '#f1ede3' };
}

function formatByDate(iso: string): string {
    return 'by ' + new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
}

function formatDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

const targetEvaluated = computed(() => evaluateArithmetic(String(form.target)));
const targetHasExpr = computed(() => hasArithmetic(String(form.target)) && targetEvaluated.value !== null);
const savedEvaluated = computed(() => evaluateArithmetic(String(form.saved)));
const savedHasExpr = computed(() => hasArithmetic(String(form.saved)) && savedEvaluated.value !== null);
const perMonthEvaluated = computed(() => evaluateArithmetic(String(form.per_month)));
const perMonthHasExpr = computed(() => hasArithmetic(String(form.per_month)) && perMonthEvaluated.value !== null);
const topUpEvaluated = computed(() => evaluateArithmetic(topUpForm.amount));
const topUpHasExpr = computed(() => hasArithmetic(topUpForm.amount) && topUpEvaluated.value !== null);
</script>

<template>
    <Head title="Goals" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">

        <!-- Page header -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Goals</h1>
                <div style="color: #8a8172; font-size: 14px; margin-top: 4px">The things you're saving up for</div>
            </div>
            <button @click="openCreate" style="margin-left: auto; display: flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 42px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> New goal
            </button>
        </div>

        <!-- Hero -->
        <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
            <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
            <div style="position: relative">
                <div style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,.72)">Saved toward your goals</div>
                <div style="display: flex; align-items: flex-end; gap: 14px; margin-top: 6px; flex-wrap: wrap">
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em">{{ formatMoney(goalTotal) }}</div>
                    <div style="padding-bottom: 9px; font-size: 14px; color: rgba(255,255,255,.7); white-space: nowrap">of {{ formatMoney(goalTarget) }}</div>
                </div>
                <!-- Overall progress bar -->
                <div style="margin-top: 18px; height: 14px; border-radius: 20px; background: rgba(255,255,255,.18); overflow: hidden">
                    <div style="height: 100%; border-radius: 20px; background: linear-gradient(90deg,#ffd27a,#ffe9a8); transition: width .5s cubic-bezier(.2,.8,.2,1)"
                        :style="{ width: heroPct + '%' }"></div>
                </div>
                <div style="margin-top: 18px; display: flex; gap: 9px; flex-wrap: wrap">
                    <div style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                        <span class="msf" style="font-size: 16px; color: #fff">savings</span>
                        <span>{{ formatMoney(goalPerMonth) }}/mo set aside</span>
                    </div>
                    <div style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                        <span class="msf" style="font-size: 16px; color: #fff">flag</span>
                        <span>{{ goals.length }} active {{ goals.length === 1 ? 'goal' : 'goals' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="goals.length === 0" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 48px 30px; text-align: center; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">flag</span>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 600; font-size: 16px; margin-top: 12px; color: #211d17">No goals yet</div>
            <div style="font-size: 13.5px; color: #8a8172; margin-top: 6px">Add a goal for something you're saving up for.</div>
            <button @click="openCreate" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 18px; height: 40px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 17px">add</span> New goal
            </button>
        </div>

        <!-- Goal cards grid -->
        <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px">
            <div v-for="goal in goals" :key="goal.id" class="group/goal" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 20px; padding: 20px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">

                <!-- Header row -->
                <div style="display: flex; align-items: center; gap: 11px; margin-bottom: 16px">
                    <!-- Icon tile -->
                    <span style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: goal.bg }">
                        <span class="msf" style="font-size: 21px" :style="{ color: goal.color }">{{ goal.icon }}</span>
                    </span>

                    <!-- Name -->
                    <span style="font-weight: 600; font-size: 14.5px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ goal.name }}</span>

                    <!-- Actions / badge -->
                    <div style="display: flex; align-items: center; gap: 6px; flex: none">
                        <template v-if="deleteId === goal.id">
                            <button @click="cancelDelete" style="font-size: 11.5px; font-weight: 600; color: #6b6355; background: #f1ede3; border: none; border-radius: 20px; padding: 4px 10px; cursor: pointer">Cancel</button>
                            <button @click="startDelete(goal)" style="font-size: 11.5px; font-weight: 600; color: #c0512c; background: #fbe2d6; border: none; border-radius: 20px; padding: 4px 10px; cursor: pointer">Delete?</button>
                        </template>
                        <template v-else>
                            <!-- Hover-reveal edit + delete -->
                            <div class="hidden group-hover/goal:flex" style="align-items: center">
                                <button @click="openEdit(goal)" title="Edit"
                                    style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: none; border: none; border-radius: 8px; cursor: pointer; color: #8a8172">
                                    <span class="material-symbols-rounded" style="font-size: 17px">edit</span>
                                </button>
                                <button @click="startDelete(goal)" title="Delete"
                                    style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: none; border: none; border-radius: 8px; cursor: pointer; color: #c0512c">
                                    <span class="material-symbols-rounded" style="font-size: 17px">delete</span>
                                </button>
                            </div>
                            <!-- Status badge -->
                            <span style="font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap"
                                :style="{ color: goalStatus(goal).color, background: goalStatus(goal).bg }">
                                {{ goalStatus(goal).label }}
                            </span>
                        </template>
                    </div>
                </div>

                <!-- Saved / target -->
                <div style="display: flex; align-items: flex-end; gap: 7px">
                    <span style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 26px; letter-spacing: -.02em">{{ formatMoney(goal.saved) }}</span>
                    <span style="font-size: 13px; color: #8a8172; padding-bottom: 3px">of {{ formatMoney(goal.target) }}</span>
                </div>

                <!-- Progress bar -->
                <div style="height: 10px; border-radius: 20px; background: #efe9dc; overflow: hidden; margin-top: 12px">
                    <div style="height: 100%; border-radius: 20px; transition: width .5s cubic-bezier(.2,.8,.2,1)"
                        :style="{ width: goalPct(goal) + '%', background: goal.saved >= goal.target ? '#2f7d5b' : goal.color }"></div>
                </div>

                <!-- Footer: monthly + target date -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 12px; font-size: 12.5px">
                    <span style="color: #4a4436; font-weight: 600">{{ formatMoney(goal.per_month) }}/mo</span>
                    <span v-if="goal.by_date" style="color: #8a8172">{{ formatByDate(goal.by_date) }}</span>
                </div>

                <!-- Top-up button -->
                <div style="margin-top: 14px">
                    <button @click="openTopUp(goal)"
                        style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border: 1.5px dashed #c8c0b0; border-radius: 10px; background: none; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; color: #6b6355; cursor: pointer">
                        <span class="material-symbols-rounded" style="font-size: 15px">add_circle</span> Top up
                    </button>
                </div>

                <!-- Top-up transactions -->
                <div v-if="goal.transactions.length" style="margin-top: 10px; border-top: 1px solid #efe9dc; padding-top: 2px">
                    <div v-for="(tx, i) in goal.transactions" :key="tx.id" class="group/tx"
                        style="display: flex; align-items: center; gap: 10px; padding: 8px 0"
                        :style="{ borderTop: i > 0 ? '1px solid #efe9dc' : 'none' }">
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 12px; color: #8a8172">{{ formatDate(tx.transacted_at) }} · {{ tx.account.name }}</div>
                        </div>
                        <template v-if="deletingTxId !== tx.id">
                            <div class="flex items-center flex-none">
                                <span style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 14px; color: #2f7d5b">+{{ formatMoney(tx.amount) }}</span>
                                <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/tx:max-w-[64px] flex-shrink-0">
                                    <div class="flex items-center gap-1" style="padding-left: 8px">
                                        <button @click.stop="openEditTx(tx, goal.id)"
                                            style="width: 26px; height: 26px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                            <span class="material-symbols-rounded" style="font-size: 14px">edit</span>
                                        </button>
                                        <button @click.stop="startDeleteTx(tx.id)"
                                            style="width: 26px; height: 26px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                            <span class="material-symbols-rounded" style="font-size: 14px">delete</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div style="display: flex; align-items: center; gap: 5px; flex: none">
                                <button @click="cancelDeleteTx"
                                    style="padding: 0 8px; height: 26px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 11px; font-weight: 600; color: #6b6355">
                                    Cancel
                                </button>
                                <button @click="confirmDeleteTx(tx.id)"
                                    style="padding: 0 8px; height: 26px; border: none; background: #c0512c; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 11px; font-weight: 600; color: #fff">
                                    Delete?
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- CRUD modal -->
        <Dialog v-model:open="modalOpen">
            <DialogContent style="max-width: 440px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        {{ editingGoal ? 'Edit goal' : 'New goal' }}
                    </DialogTitle>
                </DialogHeader>

                <form @submit.prevent="submitForm" style="display: flex; flex-direction: column; gap: 16px; margin-top: 4px">

                    <!-- Name -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Name</label>
                        <input v-model="form.name" type="text" placeholder="Japan trip" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="form.errors.name" style="font-size: 12px; color: #c0512c">{{ form.errors.name }}</div>
                    </div>

                    <!-- Color pair + preview -->
                    <div style="display: flex; gap: 16px; align-items: flex-start">
                        <div style="display: flex; flex-direction: column; gap: 6px; flex: 1">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Color</label>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap">
                                <button v-for="pair in COLOR_PAIRS" :key="pair.color" type="button"
                                    @click="selectColorPair(pair)"
                                    style="width: 26px; height: 26px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; flex: none; transition: outline .1s"
                                    :style="{
                                        background: pair.color,
                                        outline: form.color === pair.color ? '2px solid ' + pair.color : 'none',
                                        outlineOffset: '2px',
                                    }">
                                </button>
                            </div>
                        </div>
                        <!-- Live preview -->
                        <div style="display: flex; flex-direction: column; gap: 6px; align-items: center">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Preview</label>
                            <span style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center"
                                :style="{ background: form.bg }">
                                <span class="msf" style="font-size: 21px" :style="{ color: form.color }">{{ form.icon }}</span>
                            </span>
                        </div>
                    </div>

                    <!-- Icon picker -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Icon</label>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap; background: #f7f3ec; border-radius: 12px; padding: 10px">
                            <button v-for="ic in ICONS" :key="ic" type="button"
                                @click="form.icon = ic"
                                style="width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; transition: background .12s"
                                :style="{ background: form.icon === ic ? form.bg : 'transparent' }">
                                <span class="material-symbols-rounded" style="font-size: 19px"
                                    :style="{ color: form.icon === ic ? form.color : '#6b6355' }">{{ ic }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Target + Saved -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Target (₱)</label>
                            <input v-model="form.target" type="text" inputmode="decimal" required
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="targetHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ targetEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.target" style="font-size: 12px; color: #c0512c">{{ form.errors.target }}</div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Saved so far (₱)</label>
                            <input v-model="form.saved" type="text" inputmode="decimal"
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="savedHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ savedEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.saved" style="font-size: 12px; color: #c0512c">{{ form.errors.saved }}</div>
                        </div>
                    </div>

                    <!-- Monthly + Target date -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Monthly (₱/mo)</label>
                            <input v-model="form.per_month" type="text" inputmode="decimal"
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="perMonthHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ perMonthEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.per_month" style="font-size: 12px; color: #c0512c">{{ form.errors.per_month }}</div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Target date</label>
                            <input v-model="form.by_date" type="date"
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="form.errors.by_date" style="font-size: 12px; color: #c0512c">{{ form.errors.by_date }}</div>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px">
                        <button type="button" @click="modalOpen = false"
                            style="height: 40px; padding: 0 18px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #4a4436">
                            Cancel
                        </button>
                        <button type="submit" :disabled="form.processing"
                            style="height: 40px; padding: 0 18px; background: #211d17; color: #fdfbf5; border: none; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                            {{ editingGoal ? 'Save changes' : 'Create goal' }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Top-up modal -->
        <Dialog v-model:open="topUpModalOpen">
            <DialogContent style="max-width: 360px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        Top up
                    </DialogTitle>
                </DialogHeader>

                <div v-if="toppingUpGoal" style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px">
                    <span style="width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: toppingUpGoal.bg }">
                        <span class="msf" style="font-size: 18px" :style="{ color: toppingUpGoal.color }">{{ toppingUpGoal.icon }}</span>
                    </span>
                    <div>
                        <div style="font-weight: 600; font-size: 14px">{{ toppingUpGoal.name }}</div>
                        <div style="font-size: 12px; color: #8a8172">{{ formatMoney(toppingUpGoal.saved) }} saved of {{ formatMoney(toppingUpGoal.target) }}</div>
                    </div>
                </div>

                <form @submit.prevent="submitTopUp" style="display: flex; flex-direction: column; gap: 14px; margin-top: 4px">

                    <!-- Amount -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Amount to add (₱)</label>
                        <input v-model="topUpForm.amount" type="text" inputmode="decimal" required autofocus
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="topUpHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ topUpEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                        <div v-if="topUpForm.errors.amount" style="font-size: 12px; color: #c0512c">{{ topUpForm.errors.amount }}</div>
                    </div>

                    <!-- Account -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">From account</label>
                        <select v-model.number="topUpForm.account_id" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none; appearance: none">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <div v-if="topUpForm.errors.account_id" style="font-size: 12px; color: #c0512c">{{ topUpForm.errors.account_id }}</div>
                    </div>

                    <!-- Date -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Date</label>
                        <input v-model="topUpForm.transacted_at" type="date" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="topUpForm.errors.transacted_at" style="font-size: 12px; color: #c0512c">{{ topUpForm.errors.transacted_at }}</div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px">
                        <button type="button" @click="topUpModalOpen = false"
                            style="height: 40px; padding: 0 18px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #4a4436">
                            Cancel
                        </button>
                        <button type="submit" :disabled="topUpForm.processing"
                            style="height: 40px; padding: 0 18px; background: #211d17; color: #fdfbf5; border: none; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                            Add to goal
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Edit transaction modal -->
        <Dialog v-model:open="txModalOpen">
            <DialogContent style="max-width: 400px">
                <DialogHeader>
                    <DialogTitle>Edit top-up</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitEditTx" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div>
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Amount *</label>
                            <div style="position: relative">
                                <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #6b6355; font-weight: 600">₱</span>
                                <input v-model="txForm.amount" type="text" inputmode="decimal" required autofocus
                                    style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17; box-sizing: border-box" />
                                <div v-if="txAmountHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= ₱{{ txAmountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            </div>
                        </div>
                        <div>
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Date *</label>
                            <input v-model="txForm.transacted_at" type="date" required
                                style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; box-sizing: border-box" />
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Account *</label>
                        <select v-model.number="txForm.account_id" required
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                        <button type="button" @click="txModalOpen = false"
                            style="height: 40px; padding: 0 18px; border: 1px solid #e0dacd; border-radius: 10px; background: #fffdf8; font-family: 'Instrument Sans'; font-size: 13.5px; font-weight: 600; color: #4a4436; cursor: pointer">
                            Cancel
                        </button>
                        <button type="submit"
                            style="height: 40px; padding: 0 18px; border: none; border-radius: 10px; background: #211d17; font-family: 'Instrument Sans'; font-size: 13.5px; font-weight: 600; color: #fdfbf5; cursor: pointer">
                            Save
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

    </div>
</template>
