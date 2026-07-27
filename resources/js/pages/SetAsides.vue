<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy, topUp as topUpAction } from '@/actions/App/Http/Controllers/SinkingFundController';
import { store as storeTransaction } from '@/actions/App/Http/Controllers/TransactionController';

type Payment = {
    id: number;
    amount: number;
    merchant: string | null;
    transacted_at: string;
};

type Fund = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    saved: number;
    target: number;
    per_month: number;
    due_date: string | null;
    months_left: number | null;
    position: number;
    payments: Payment[];
};

type Account = {
    id: number;
    name: string;
    type: string;
};

const props = defineProps<{
    funds: Fund[];
    saTotal: number;
    saTarget: number;
    saPerMonth: number;
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
    'directions_car', 'shield', 'subscriptions', 'confirmation_number', 'home',
    'health_and_safety', 'flight', 'laptop_mac', 'school', 'gift',
    'beach_access', 'celebration', 'medical_services', 'pets', 'restaurant',
    'shopping_bag', 'sports', 'music_note', 'volunteer_activism', 'savings',
    'account_balance', 'inventory_2', 'local_shipping', 'build', 'smartphone',
];

const formatMoney = (n: number) => '₱' + Math.round(n).toLocaleString('en-PH');

const saRemaining = computed(() => Math.max(0, props.saTarget - props.saTotal));

// ── CRUD modal ─────────────────────────────────────────────────────────────────

const modalOpen = ref(false);
const editingFund = ref<Fund | null>(null);

const form = useForm({
    name: '',
    icon: 'savings',
    color: '#3f7fb0',
    bg: '#dcebf5',
    saved: '' as number | string,
    target: '' as number | string,
    per_month: '' as number | string,
    due_date: '',
});

watch([() => form.target, () => form.saved, () => form.due_date], ([target, saved, dueDate]) => {
    const t = evaluateArithmetic(String(target));
    const s = evaluateArithmetic(String(saved)) ?? 0;
    if (!dueDate || !t) return;
    const months = Math.max(1, Math.ceil(
        (new Date(dueDate + 'T00:00:00').getTime() - Date.now()) / (1000 * 60 * 60 * 24 * 30.44)
    ));
    const needed = Math.ceil(Math.max(0, t - s) / months);
    if (needed > 0) form.per_month = needed;
});

function openCreate() {
    editingFund.value = null;
    form.reset();
    modalOpen.value = true;
}

function openEdit(fund: Fund) {
    editingFund.value = fund;
    form.name = fund.name;
    form.icon = fund.icon;
    form.color = fund.color;
    form.bg = fund.bg;
    form.saved = String(fund.saved);
    form.target = String(fund.target);
    form.per_month = String(fund.per_month);
    form.due_date = fund.due_date ?? '';
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
    if (editingFund.value) {
        form.patch(update(editingFund.value.id).url, {
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

function startDelete(fund: Fund) {
    if (deleteId.value === fund.id) {
        clearTimeout(deleteTimer!);
        deleteId.value = null;
        router.delete(destroy(fund.id).url);
    } else {
        if (deleteTimer) clearTimeout(deleteTimer);
        deleteId.value = fund.id;
        deleteTimer = setTimeout(() => { deleteId.value = null; }, 3000);
    }
}

function cancelDelete() {
    if (deleteTimer) clearTimeout(deleteTimer);
    deleteId.value = null;
}

// ── Per-fund helpers ───────────────────────────────────────────────────────────

function fundPct(fund: Fund) {
    return Math.min(100, Math.round((fund.saved / fund.target) * 100));
}

function fundBarColor(fund: Fund) {
    return fund.saved >= fund.target ? '#2f7d5b' : fund.color;
}

function fundStatus(fund: Fund) {
    if (fund.saved >= fund.target) return 'Funded';
    return formatMoney(fund.target - fund.saved) + ' to go';
}

function formatDueDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
}

function dueSoonWarning(fund: Fund): boolean {
    return fund.months_left !== null && fund.months_left <= 2 && fund.saved < fund.target;
}

// ── Top-up modal ───────────────────────────────────────────────────────────────

const topUpModalOpen = ref(false);
const toppingUpFund = ref<Fund | null>(null);
const topUpForm = useForm({ amount: '' as string });

function openTopUp(fund: Fund) {
    toppingUpFund.value = fund;
    topUpForm.amount = String(fund.per_month || '');
    topUpForm.clearErrors();
    topUpModalOpen.value = true;
}

function submitTopUp() {
    if (!toppingUpFund.value) return;
    topUpForm.transform((data) => ({
        ...data,
        amount: evaluateArithmetic(data.amount) ?? parseFloat(data.amount),
    }));
    topUpForm.post(topUpAction(toppingUpFund.value.id).url, {
        onSuccess: () => { topUpModalOpen.value = false; },
    });
}

// ── Payment modal ──────────────────────────────────────────────────────────────

const payModalOpen = ref(false);
const payingFund = ref<Fund | null>(null);
const payForm = useForm({
    type: 'expense' as const,
    sinking_fund_id: 0 as number,
    amount: '' as string,
    merchant: '',
    account_id: 0 as number,
    transacted_at: '',
});

function openPayment(fund: Fund) {
    payingFund.value = fund;
    payForm.sinking_fund_id = fund.id;
    payForm.amount = String(fund.target);
    payForm.merchant = fund.name;
    payForm.account_id = props.accounts[0]?.id ?? 0;
    payForm.transacted_at = new Date().toISOString().split('T')[0];
    payForm.clearErrors();
    payModalOpen.value = true;
}

function submitPayment() {
    payForm.transform((data) => ({
        ...data,
        amount: evaluateArithmetic(data.amount) ?? parseFloat(data.amount),
    }));
    payForm.post(storeTransaction().url, {
        onSuccess: () => { payModalOpen.value = false; },
    });
}

const targetEvaluated = computed(() => evaluateArithmetic(String(form.target)));
const targetHasExpr = computed(() => hasArithmetic(String(form.target)) && targetEvaluated.value !== null);
const savedEvaluated = computed(() => evaluateArithmetic(String(form.saved)));
const savedHasExpr = computed(() => hasArithmetic(String(form.saved)) && savedEvaluated.value !== null);
const perMonthEvaluated = computed(() => evaluateArithmetic(String(form.per_month)));
const perMonthHasExpr = computed(() => hasArithmetic(String(form.per_month)) && perMonthEvaluated.value !== null);
const topUpEvaluated = computed(() => evaluateArithmetic(topUpForm.amount));
const topUpHasExpr = computed(() => hasArithmetic(topUpForm.amount) && topUpEvaluated.value !== null);
const payAmountEvaluated = computed(() => evaluateArithmetic(payForm.amount));
const payAmountHasExpr = computed(() => hasArithmetic(payForm.amount) && payAmountEvaluated.value !== null);

function formatDate(iso: string) {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<template>
    <Head title="Set-asides" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">

        <!-- Page header -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Set-asides</h1>
                <div style="color: #8a8172; font-size: 14px; margin-top: 4px">Quietly funding the big, irregular bills before they hit</div>
            </div>
            <button @click="openCreate" style="margin-left: auto; display: flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 42px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> New fund
            </button>
        </div>

        <!-- Hero -->
        <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
            <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
            <div style="position: relative">
                <div style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,.72)">Total set aside</div>
                <div style="display: flex; align-items: flex-end; gap: 14px; margin-top: 6px; flex-wrap: wrap">
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em">{{ formatMoney(saTotal) }}</div>
                    <div style="padding-bottom: 9px; font-size: 14px; color: rgba(255,255,255,.7)">of {{ formatMoney(saTarget) }} target</div>
                </div>
                <div style="margin-top: 18px; display: flex; gap: 9px; flex-wrap: wrap">
                    <div style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                        <span class="msf" style="font-size: 16px; color: #fff">autorenew</span>
                        <span>{{ formatMoney(saPerMonth) }}/mo automatic</span>
                    </div>
                    <div v-if="funds.length > 0 && saRemaining > 0" style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                        <span class="msf" style="font-size: 16px; color: #fff">flag</span>
                        <span>{{ formatMoney(saRemaining) }} to fully funded</span>
                    </div>
                    <div v-else-if="funds.length > 0" style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                        <span class="msf" style="font-size: 16px; color: #fff">check_circle</span>
                        <span>All funds fully funded</span>
                    </div>
                </div>
                <div style="margin-top: 14px; display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: rgba(255,255,255,.72)">
                    <span class="material-symbols-rounded" style="font-size: 16px">lock</span>
                    <span>Held separately — never counted in your safe-to-spend</span>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="funds.length === 0" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 48px 30px; text-align: center; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">savings</span>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 600; font-size: 16px; margin-top: 12px; color: #211d17">No set-asides yet</div>
            <div style="font-size: 13.5px; color: #8a8172; margin-top: 6px">Add a fund for irregular bills you want to save for in advance.</div>
            <button @click="openCreate" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 18px; height: 40px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 17px">add</span> New fund
            </button>
        </div>

        <!-- Fund cards grid -->
        <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px">
            <div v-for="fund in funds" :key="fund.id" class="group/fund" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 20px; padding: 20px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">

                <!-- Header row -->
                <div style="display: flex; align-items: center; gap: 11px; margin-bottom: 16px">
                    <!-- Icon tile -->
                    <span style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: fund.bg }">
                        <span class="msf" style="font-size: 21px" :style="{ color: fund.color }">{{ fund.icon }}</span>
                    </span>

                    <!-- Name -->
                    <span style="font-weight: 600; font-size: 14.5px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ fund.name }}</span>

                    <!-- Actions / badge -->
                    <div style="display: flex; align-items: center; gap: 6px; flex: none">
                        <!-- Delete confirm state -->
                        <template v-if="deleteId === fund.id">
                            <button @click="cancelDelete" style="font-size: 11.5px; font-weight: 600; color: #6b6355; background: #f1ede3; border: none; border-radius: 20px; padding: 4px 10px; cursor: pointer">Cancel</button>
                            <button @click="startDelete(fund)" style="font-size: 11.5px; font-weight: 600; color: #c0512c; background: #fbe2d6; border: none; border-radius: 20px; padding: 4px 10px; cursor: pointer">Delete?</button>
                        </template>
                        <template v-else>
                            <!-- Hover-reveal edit + delete -->
                            <div class="hidden group-hover/fund:flex" style="align-items: center">
                                <button @click="openEdit(fund)" title="Edit"
                                    style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: none; border: none; border-radius: 8px; cursor: pointer; color: #8a8172">
                                    <span class="material-symbols-rounded" style="font-size: 17px">edit</span>
                                </button>
                                <button @click="startDelete(fund)" title="Delete"
                                    style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: none; border: none; border-radius: 8px; cursor: pointer; color: #c0512c">
                                    <span class="material-symbols-rounded" style="font-size: 17px">delete</span>
                                </button>
                            </div>
                            <!-- Status badge -->
                            <span style="font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap"
                                :style="{
                                    color: fund.saved >= fund.target ? '#2f7d5b' : '#8a8172',
                                    background: fund.saved >= fund.target ? '#dff3e6' : '#f1ede3',
                                }">{{ fundStatus(fund) }}</span>
                        </template>
                    </div>
                </div>

                <!-- Saved / target -->
                <div style="display: flex; align-items: flex-end; gap: 7px">
                    <span style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 26px; letter-spacing: -.02em">{{ formatMoney(fund.saved) }}</span>
                    <span style="font-size: 13px; color: #8a8172; padding-bottom: 3px">of {{ formatMoney(fund.target) }}</span>
                </div>

                <!-- Progress bar -->
                <div style="height: 10px; border-radius: 20px; background: #efe9dc; overflow: hidden; margin-top: 12px">
                    <div style="height: 100%; border-radius: 20px; transition: width .5s cubic-bezier(.2,.8,.2,1)"
                        :style="{ width: fundPct(fund) + '%', background: fundBarColor(fund) }"></div>
                </div>

                <!-- Footer: monthly + due -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 12px; font-size: 12.5px">
                    <span style="color: #4a4436; font-weight: 600">{{ formatMoney(fund.per_month) }}/mo</span>
                    <span v-if="fund.due_date" style="display: flex; align-items: center; gap: 5px">
                        <span v-if="dueSoonWarning(fund)" style="display: inline-flex; align-items: center; gap: 4px; background: #fbe2d6; color: #c0512c; font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 20px">
                            <span class="material-symbols-rounded" style="font-size: 12px">warning</span>
                            {{ fund.months_left }}mo left
                        </span>
                        <span style="color: #8a8172">Due {{ formatDueDate(fund.due_date) }}</span>
                    </span>
                </div>


                <!-- Recent payments -->
                <div v-if="fund.payments.length > 0" style="margin-top: 14px; border-top: 1px solid #ede8de; padding-top: 12px; display: flex; flex-direction: column; gap: 6px">
                    <div v-for="p in fund.payments" :key="p.id" style="display: flex; align-items: center; justify-content: space-between; font-size: 12px">
                        <span style="color: #6b6355">{{ p.merchant || 'Payment' }}</span>
                        <span style="display: flex; align-items: center; gap: 8px">
                            <span style="color: #8a8172">{{ formatDate(p.transacted_at) }}</span>
                            <span style="color: #c0512c; font-weight: 600">-{{ formatMoney(p.amount) }}</span>
                        </span>
                    </div>
                </div>

                <!-- Action buttons -->
                <div style="margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px">
                    <button @click="openTopUp(fund)"
                        style="display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border: 1.5px dashed #c8c0b0; border-radius: 10px; background: none; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; color: #6b6355; cursor: pointer">
                        <span class="material-symbols-rounded" style="font-size: 15px">add_circle</span> Top up
                    </button>
                    <button v-if="accounts.length > 0" @click="openPayment(fund)"
                        style="display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border: 1.5px dashed #c8c0b0; border-radius: 10px; background: none; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; color: #6b6355; cursor: pointer">
                        <span class="material-symbols-rounded" style="font-size: 15px">payments</span> Record payment
                    </button>
                </div>
            </div>
        </div>

        <!-- CRUD modal -->
        <Dialog v-model:open="modalOpen">
            <DialogContent style="max-width: 440px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        {{ editingFund ? 'Edit fund' : 'New fund' }}
                    </DialogTitle>
                </DialogHeader>

                <form @submit.prevent="submitForm" style="display: flex; flex-direction: column; gap: 16px; margin-top: 4px">

                    <!-- Name -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Name</label>
                        <input v-model="form.name" type="text" placeholder="Car insurance" required
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

                    <!-- Monthly + Due text -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Monthly (₱/mo)</label>
                            <input v-model="form.per_month" type="text" inputmode="decimal"
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="perMonthHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ perMonthEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.per_month" style="font-size: 12px; color: #c0512c">{{ form.errors.per_month }}</div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Due date</label>
                            <input v-model="form.due_date" type="date"
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="form.errors.due_date" style="font-size: 12px; color: #c0512c">{{ form.errors.due_date }}</div>
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
                            {{ editingFund ? 'Save changes' : 'Create fund' }}
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

                <div v-if="toppingUpFund" style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px">
                    <span style="width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: toppingUpFund.bg }">
                        <span class="msf" style="font-size: 18px" :style="{ color: toppingUpFund.color }">{{ toppingUpFund.icon }}</span>
                    </span>
                    <div>
                        <div style="font-weight: 600; font-size: 14px">{{ toppingUpFund.name }}</div>
                        <div style="font-size: 12px; color: #8a8172">{{ formatMoney(toppingUpFund.saved) }} saved of {{ formatMoney(toppingUpFund.target) }}</div>
                    </div>
                </div>

                <form @submit.prevent="submitTopUp" style="display: flex; flex-direction: column; gap: 14px; margin-top: 4px">
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Amount to add (₱)</label>
                        <input v-model="topUpForm.amount" type="text" inputmode="decimal" required autofocus
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="topUpHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ topUpEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                        <div v-if="topUpForm.errors.amount" style="font-size: 12px; color: #c0512c">{{ topUpForm.errors.amount }}</div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px">
                        <button type="button" @click="topUpModalOpen = false"
                            style="height: 40px; padding: 0 18px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #4a4436">
                            Cancel
                        </button>
                        <button type="submit" :disabled="topUpForm.processing"
                            style="height: 40px; padding: 0 18px; background: #211d17; color: #fdfbf5; border: none; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                            Add to fund
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Payment modal -->
        <Dialog v-model:open="payModalOpen">
            <DialogContent style="max-width: 400px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        Record payment
                    </DialogTitle>
                </DialogHeader>

                <div v-if="payingFund" style="margin-bottom: 4px; display: flex; align-items: center; gap: 10px">
                    <span style="width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: payingFund.bg }">
                        <span class="msf" style="font-size: 18px" :style="{ color: payingFund.color }">{{ payingFund.icon }}</span>
                    </span>
                    <span style="font-weight: 600; font-size: 14px">{{ payingFund.name }}</span>
                </div>

                <form @submit.prevent="submitPayment" style="display: flex; flex-direction: column; gap: 14px; margin-top: 4px">

                    <!-- Amount -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Amount (₱)</label>
                        <input v-model="payForm.amount" type="text" inputmode="decimal" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="payAmountHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ payAmountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                        <div v-if="payForm.errors.amount" style="font-size: 12px; color: #c0512c">{{ payForm.errors.amount }}</div>
                    </div>

                    <!-- Merchant -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Description</label>
                        <input v-model="payForm.merchant" type="text" placeholder="e.g. Paid to insurer"
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                    </div>

                    <!-- Account -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Paid from account</label>
                        <select v-model.number="payForm.account_id" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none; appearance: none">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <div v-if="payForm.errors.account_id" style="font-size: 12px; color: #c0512c">{{ payForm.errors.account_id }}</div>
                    </div>

                    <!-- Date -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Date</label>
                        <input v-model="payForm.transacted_at" type="date" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="payForm.errors.transacted_at" style="font-size: 12px; color: #c0512c">{{ payForm.errors.transacted_at }}</div>
                    </div>

                    <!-- Buttons -->
                    <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px">
                        <button type="button" @click="payModalOpen = false"
                            style="height: 40px; padding: 0 18px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #4a4436">
                            Cancel
                        </button>
                        <button type="submit" :disabled="payForm.processing"
                            style="height: 40px; padding: 0 18px; background: #211d17; color: #fdfbf5; border: none; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                            Record payment
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
