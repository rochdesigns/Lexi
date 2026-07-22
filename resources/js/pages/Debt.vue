<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';
import { computeSnowball } from '@/utils/snowball';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy, makePayment } from '@/actions/App/Http/Controllers/DebtController';

type Account = {
    id: number;
    name: string;
    type: string;
};

type Debt = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    balance: number;
    original: number;
    per_month: number;
    interest_rate: number | null;
    interest_rate_period: string;
    due_day: number | null;
    due_label: string | null;
    months_left: number | null;
    position: number;
};

const props = defineProps<{
    debts: Debt[];
    debtTotal: number;
    debtOriginal: number;
    debtMonthly: number;
    debtCount: number;
    debtPaidPct: number;
    debtFreeDate: string | null;
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
    'credit_card', 'directions_car', 'smartphone', 'chair', 'home',
    'laptop_mac', 'school', 'medical_services', 'shopping_bag', 'flight',
    'subscriptions', 'account_balance', 'local_shipping', 'build', 'payments',
    'wallet', 'savings', 'attach_money', 'receipt_long', 'inventory_2',
    'health_and_safety', 'pets', 'restaurant', 'sports', 'beach_access',
];

const formatMoney = (n: number) => '₱' + Math.round(n).toLocaleString('en-PH');

function debtPct(debt: Debt): number {
    return debt.original > 0
        ? Math.min(100, Math.round(((debt.original - debt.balance) / debt.original) * 100))
        : 0;
}

function debtStatus(debt: Debt): string {
    const pct = debtPct(debt);
    if (debt.balance <= 0) return 'Paid off';
    return pct >= 80 ? 'Almost there' : `${pct}% paid`;
}

function debtStatusStyle(debt: Debt): object {
    const pct = debtPct(debt);
    const isPaidOff = debt.balance <= 0;
    const isAlmost = pct >= 80 || isPaidOff;
    return {
        color: isAlmost ? '#2f7d5b' : '#8a8172',
        background: isAlmost ? '#dff3e6' : '#f1ede3',
    };
}

// ── CRUD modal ─────────────────────────────────────────────────────────────────

const modalOpen = ref(false);
const editingDebt = ref<Debt | null>(null);

const form = useForm({
    name: '',
    icon: 'credit_card',
    color: '#3f7fb0',
    bg: '#dcebf5',
    balance: '' as number | string,
    original: '' as number | string,
    per_month: '' as number | string,
    due_day: '' as number | string,
    interest_rate: '' as number | string,
    interest_rate_period: 'annual' as string,
});

function openCreate() {
    editingDebt.value = null;
    form.reset();
    form.icon = 'credit_card';
    form.color = '#3f7fb0';
    form.bg = '#dcebf5';
    modalOpen.value = true;
}

function openEdit(debt: Debt) {
    editingDebt.value = debt;
    form.name = debt.name;
    form.icon = debt.icon;
    form.color = debt.color;
    form.bg = debt.bg;
    form.balance = String(debt.balance);
    form.original = String(debt.original);
    form.per_month = String(debt.per_month);
    form.due_day = debt.due_day ? String(debt.due_day) : '';
    form.interest_rate = debt.interest_rate ? String(debt.interest_rate) : '';
    form.interest_rate_period = debt.interest_rate_period || 'annual';
    form.clearErrors();
    modalOpen.value = true;
}

function submitForm() {
    form.transform((data) => ({
        ...data,
        balance: (evaluateArithmetic(String(data.balance)) ?? parseFloat(String(data.balance))) || 0,
        original: (evaluateArithmetic(String(data.original)) ?? parseFloat(String(data.original))) || 0,
        per_month: (evaluateArithmetic(String(data.per_month)) ?? parseFloat(String(data.per_month))) || 0,
        due_day: data.due_day ? parseInt(String(data.due_day), 10) : null,
        interest_rate: data.interest_rate ? parseFloat(String(data.interest_rate)) : null,
    }));
    if (editingDebt.value) {
        form.patch(update(editingDebt.value.id).url, {
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

function startDelete(debt: Debt) {
    if (deleteId.value === debt.id) {
        clearTimeout(deleteTimer!);
        deleteId.value = null;
        router.delete(destroy(debt.id).url);
    } else {
        if (deleteTimer) clearTimeout(deleteTimer);
        deleteId.value = debt.id;
        deleteTimer = setTimeout(() => { deleteId.value = null; }, 3000);
    }
}

function cancelDelete() {
    if (deleteTimer) clearTimeout(deleteTimer);
    deleteId.value = null;
}

// ── Payment modal ──────────────────────────────────────────────────────────────

const payModalOpen = ref(false);
const payingDebt = ref<Debt | null>(null);
const payForm = useForm({
    amount: '' as string,
    account_id: 0 as number,
    merchant: '',
    transacted_at: '',
});

function openPayment(debt: Debt) {
    payingDebt.value = debt;
    payForm.amount = String(debt.per_month || '');
    payForm.account_id = props.accounts[0]?.id ?? 0;
    payForm.merchant = debt.name;
    payForm.transacted_at = new Date().toISOString().split('T')[0];
    payForm.clearErrors();
    payModalOpen.value = true;
}

function submitPayment() {
    if (!payingDebt.value) return;
    payForm.transform((data) => ({
        ...data,
        amount: evaluateArithmetic(data.amount) ?? parseFloat(data.amount),
    }));
    payForm.post(makePayment(payingDebt.value.id).url, {
        onSuccess: () => { payModalOpen.value = false; },
    });
}

// ── Arithmetic hints ───────────────────────────────────────────────────────────

const balanceEvaluated = computed(() => evaluateArithmetic(String(form.balance)));
const balanceHasExpr = computed(() => hasArithmetic(String(form.balance)) && balanceEvaluated.value !== null);
const originalEvaluated = computed(() => evaluateArithmetic(String(form.original)));
const originalHasExpr = computed(() => hasArithmetic(String(form.original)) && originalEvaluated.value !== null);
const perMonthEvaluated = computed(() => evaluateArithmetic(String(form.per_month)));
const perMonthHasExpr = computed(() => hasArithmetic(String(form.per_month)) && perMonthEvaluated.value !== null);
const payAmountEvaluated = computed(() => evaluateArithmetic(payForm.amount));
const payAmountHasExpr = computed(() => hasArithmetic(payForm.amount) && payAmountEvaluated.value !== null);

// ── Snowball plan ──────────────────────────────────────────────────────────────

const monthlyBudget = ref(
    props.debts.filter(d => d.balance > 0).reduce((s, d) => s + d.per_month, 0)
);

const snowballPlan = computed(() =>
    computeSnowball(props.debts, monthlyBudget.value)
);

const minimumsTotal = computed(() =>
    props.debts.filter(d => d.balance > 0).reduce((s, d) => s + d.per_month, 0)
);

// ── Debt calculator ────────────────────────────────────────────────────────────

const calcModalOpen = ref(false);
const calcDebt = ref<Debt | null>(null);
const calcMode = ref<'months' | 'payment'>('months');
const calcInput = ref('');

function openCalc(debt: Debt) {
    calcDebt.value = debt;
    calcMode.value = 'months';
    calcInput.value = '';
    calcModalOpen.value = true;
}

function monthlyRate(debt: Debt): number {
    if (!debt.interest_rate) return 0;
    return debt.interest_rate_period === 'monthly'
        ? debt.interest_rate / 100
        : debt.interest_rate / 100 / 12;
}

type CalcResult = {
    months: number;
    payment: number;
    totalInterest: number;
    totalPaid: number;
    valid: boolean;
    error?: string;
};

const calcResult = computed((): CalcResult | null => {
    const debt = calcDebt.value;
    if (!debt) return null;

    const P = debt.balance;
    const r = monthlyRate(debt);
    const raw = parseFloat(calcInput.value);
    if (!calcInput.value || isNaN(raw) || raw <= 0) return null;

    if (calcMode.value === 'months') {
        const M = raw;
        if (r > 0 && M <= P * r) {
            return { months: 0, payment: M, totalInterest: 0, totalPaid: 0, valid: false, error: 'Payment is too low to cover monthly interest.' };
        }
        const n = r === 0 ? Math.ceil(P / M) : Math.ceil(-Math.log(1 - (P * r) / M) / Math.log(1 + r));
        const totalPaid = M * n;
        return { months: n, payment: M, totalInterest: totalPaid - P, totalPaid, valid: true };
    } else {
        const n = Math.round(raw);
        if (n < 1) return null;
        const M = r === 0 ? P / n : (P * r) / (1 - Math.pow(1 + r, -n));
        const totalPaid = M * n;
        return { months: n, payment: M, totalInterest: totalPaid - P, totalPaid, valid: true };
    }
});
</script>

<template>
    <Head title="Debt" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">

        <!-- Page header -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Debt</h1>
                <div style="color: #8a8172; font-size: 14px; margin-top: 4px">Your installment plans and payoff progress</div>
            </div>
            <button @click="openCreate" style="margin-left: auto; display: flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 42px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add a debt
            </button>
        </div>

        <!-- Hero -->
        <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
            <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
            <div style="position: relative">
                <div style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,.72)">Total you owe</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em; margin-top: 6px">{{ formatMoney(debtTotal) }}</div>
                <template v-if="debtCount > 0">
                    <div style="margin-top: 18px; height: 14px; border-radius: 20px; background: rgba(255,255,255,.18); overflow: hidden">
                        <div style="height: 100%; border-radius: 20px; background: linear-gradient(90deg,#ffd27a,#ffe9a8); transition: width .5s cubic-bezier(.2,.8,.2,1)"
                            :style="{ width: debtPaidPct + '%' }"></div>
                    </div>
                    <div style="margin-top: 10px; font-size: 13px; color: rgba(255,255,255,.78)">{{ debtPaidPct }}% paid off overall — keep going</div>
                    <div style="margin-top: 18px; display: flex; gap: 9px; flex-wrap: wrap">
                        <div style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                            <span class="msf" style="font-size: 16px; color: #fff">event</span>
                            <span>{{ formatMoney(debtMonthly) }}/mo across {{ debtCount }} {{ debtCount === 1 ? 'plan' : 'plans' }}</span>
                        </div>
                        <div v-if="debtFreeDate" style="display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 8px 13px; border-radius: 14px; font-size: 13px">
                            <span class="msf" style="font-size: 16px; color: #fff">celebration</span>
                            <span>Debt-free by {{ debtFreeDate }}</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="debts.length === 0" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 48px 30px; text-align: center; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">credit_card_off</span>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 600; font-size: 16px; margin-top: 12px; color: #211d17">No debts tracked</div>
            <div style="font-size: 13.5px; color: #8a8172; margin-top: 6px">Add an installment plan to track your payoff progress.</div>
            <button @click="openCreate" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 18px; height: 40px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 17px">add</span> Add a debt
            </button>
        </div>

        <!-- Debt cards grid -->
        <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px">
            <div v-for="debt in debts" :key="debt.id" class="group/debt" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 20px; padding: 20px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">

                <!-- Header row -->
                <div style="display: flex; align-items: center; gap: 11px; margin-bottom: 16px">
                    <!-- Icon tile -->
                    <span style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: debt.bg }">
                        <span class="msf" style="font-size: 21px" :style="{ color: debt.color }">{{ debt.icon }}</span>
                    </span>

                    <!-- Name -->
                    <span style="font-weight: 600; font-size: 14.5px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ debt.name }}</span>

                    <!-- Actions / badge -->
                    <div style="display: flex; align-items: center; gap: 6px; flex: none">
                        <template v-if="deleteId === debt.id">
                            <button @click="cancelDelete" style="font-size: 11.5px; font-weight: 600; color: #6b6355; background: #f1ede3; border: none; border-radius: 20px; padding: 4px 10px; cursor: pointer">Cancel</button>
                            <button @click="startDelete(debt)" style="font-size: 11.5px; font-weight: 600; color: #c0512c; background: #fbe2d6; border: none; border-radius: 20px; padding: 4px 10px; cursor: pointer">Delete?</button>
                        </template>
                        <template v-else>
                            <div class="hidden group-hover/debt:flex" style="align-items: center">
                                <button @click="openEdit(debt)" title="Edit"
                                    style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: none; border: none; border-radius: 8px; cursor: pointer; color: #8a8172">
                                    <span class="material-symbols-rounded" style="font-size: 17px">edit</span>
                                </button>
                                <button @click="startDelete(debt)" title="Delete"
                                    style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: none; border: none; border-radius: 8px; cursor: pointer; color: #c0512c">
                                    <span class="material-symbols-rounded" style="font-size: 17px">delete</span>
                                </button>
                            </div>
                            <!-- Status badge -->
                            <span style="font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap"
                                :style="debtStatusStyle(debt)">{{ debtStatus(debt) }}</span>
                        </template>
                    </div>
                </div>

                <!-- Balance / original -->
                <div style="display: flex; align-items: flex-end; gap: 7px">
                    <span style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 26px; letter-spacing: -.02em">{{ formatMoney(debt.balance) }}</span>
                    <span style="font-size: 13px; color: #8a8172; padding-bottom: 3px">of {{ formatMoney(debt.original) }} left</span>
                </div>

                <!-- Progress bar -->
                <div style="height: 10px; border-radius: 20px; background: #efe9dc; overflow: hidden; margin-top: 12px">
                    <div style="height: 100%; border-radius: 20px; transition: width .5s cubic-bezier(.2,.8,.2,1)"
                        :style="{ width: debtPct(debt) + '%', background: debt.color }"></div>
                </div>

                <!-- Footer: monthly + payments left / due -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 12px; font-size: 12.5px">
                    <span style="color: #4a4436; font-weight: 600">{{ formatMoney(debt.per_month) }}/mo</span>
                    <span style="color: #8a8172">
                        <template v-if="debt.months_left !== null">
                            {{ debt.months_left }} {{ debt.months_left === 1 ? 'payment' : 'payments' }} left
                        </template>
                        <template v-if="debt.months_left !== null && debt.due_label"> · </template>
                        {{ debt.due_label }}
                    </span>
                </div>

                <!-- Card actions -->
                <div style="margin-top: 14px; display: flex; gap: 8px">
                    <button @click="openPayment(debt)"
                        style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border: 1.5px dashed #c8c0b0; border-radius: 10px; background: none; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; color: #6b6355; cursor: pointer">
                        <span class="material-symbols-rounded" style="font-size: 15px">payments</span> Record payment
                    </button>
                    <button v-if="debt.interest_rate" @click="openCalc(debt)" title="Calculate payoff"
                        style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 1.5px solid #e0dacd; border-radius: 10px; background: none; cursor: pointer; color: #6b6355; flex: none">
                        <span class="material-symbols-rounded" style="font-size: 17px">calculate</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Payoff Plan -->
        <div v-if="debts.length > 0" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 28px 30px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">

            <!-- Header + budget input -->
            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px">
                <div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 18px; letter-spacing: -.01em">Snowball Payoff Plan</div>
                    <div style="font-size: 13px; color: #8a8172; margin-top: 2px">Pay off the smallest balance first, roll it into the next</div>
                </div>
                <div style="margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; white-space: nowrap">Monthly budget (₱)</label>
                    <input v-model.number="monthlyBudget" type="number" min="0" step="100"
                        style="width: 130px; height: 36px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 10px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none" />
                </div>
            </div>

            <!-- Budget too low warning -->
            <div v-if="snowballPlan === null"
                style="display: flex; align-items: center; gap: 8px; background: #fdf3ed; border: 1px solid #f5c9a8; border-radius: 12px; padding: 12px 16px; font-size: 13.5px; color: #8a3800">
                <span class="material-symbols-rounded" style="font-size: 18px">warning</span>
                Increase your budget to at least ₱{{ Math.ceil(minimumsTotal).toLocaleString('en-PH') }} to cover all minimum payments.
            </div>

            <!-- All debts paid -->
            <div v-else-if="snowballPlan.length === 0"
                style="text-align: center; padding: 24px 0">
                <span class="msf" style="font-size: 36px; display: block; margin-bottom: 8px; color: #2f7d5b">celebration</span>
                <div style="font-weight: 600; font-size: 15px; color: #2f7d5b">All debts paid off!</div>
            </div>

            <!-- Snowball ranked list -->
            <div v-else style="display: flex; flex-direction: column; gap: 10px">
                <div v-for="(item, index) in snowballPlan" :key="item.id"
                    style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid #e0dacd; border-radius: 14px; background: #fdfbf5">

                    <!-- Rank pill -->
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: #f1ede3; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #4a4436; flex: none">
                        {{ index + 1 }}
                    </div>

                    <!-- Icon tile -->
                    <span style="width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: item.bg }">
                        <span class="msf" style="font-size: 19px" :style="{ color: item.color }">{{ item.icon }}</span>
                    </span>

                    <!-- Name + meta -->
                    <div style="flex: 1; min-width: 0">
                        <div style="display: flex; align-items: center; gap: 7px; flex-wrap: wrap">
                            <span style="font-weight: 600; font-size: 14px">{{ item.name }}</span>
                            <span v-if="index === 0"
                                style="font-size: 11px; font-weight: 600; background: #dff3e6; color: #2f7d5b; padding: 2px 8px; border-radius: 20px">
                                Snowball target
                            </span>
                        </div>
                        <div style="font-size: 12.5px; color: #8a8172; margin-top: 2px">
                            {{ formatMoney(item.startBalance) }} remaining
                            <template v-if="item.totalInterest !== null">
                                · ₱{{ Math.round(item.totalInterest).toLocaleString('en-PH') }} in interest
                            </template>
                        </div>
                        <div style="margin-top: 8px; height: 5px; border-radius: 20px; background: #e0dacd; overflow: hidden">
                            <div style="height: 100%; border-radius: 20px; transition: width .4s"
                                :style="{ width: item.paidPct + '%', background: item.color }"></div>
                        </div>
                    </div>

                    <!-- Payoff date -->
                    <div style="text-align: right; flex: none">
                        <div style="font-weight: 700; font-size: 14px; color: #211d17">{{ item.payoffDate }}</div>
                        <div style="font-size: 12px; color: #8a8172">{{ item.payoffMonth }} mo</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CRUD modal -->
        <Dialog v-model:open="modalOpen">
            <DialogContent style="max-width: 440px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        {{ editingDebt ? 'Edit debt' : 'Add a debt' }}
                    </DialogTitle>
                </DialogHeader>

                <form @submit.prevent="submitForm" style="display: flex; flex-direction: column; gap: 16px; margin-top: 4px">

                    <!-- Name -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Name</label>
                        <input v-model="form.name" type="text" placeholder="Car loan" required
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

                    <!-- Original + Balance -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Original amount (₱)</label>
                            <input v-model="form.original" type="text" inputmode="decimal" required
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="originalHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ originalEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.original" style="font-size: 12px; color: #c0512c">{{ form.errors.original }}</div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Remaining balance (₱)</label>
                            <input v-model="form.balance" type="text" inputmode="decimal" required
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="balanceHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ balanceEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.balance" style="font-size: 12px; color: #c0512c">{{ form.errors.balance }}</div>
                        </div>
                    </div>

                    <!-- Monthly payment + Due day -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Monthly payment (₱)</label>
                            <input v-model="form.per_month" type="text" inputmode="decimal" required
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="perMonthHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ perMonthEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                            <div v-if="form.errors.per_month" style="font-size: 12px; color: #c0512c">{{ form.errors.per_month }}</div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px">
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436">Payment due day</label>
                            <input v-model="form.due_day" type="number" min="1" max="31" placeholder="e.g. 5"
                                style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                            <div v-if="form.errors.due_day" style="font-size: 12px; color: #c0512c">{{ form.errors.due_day }}</div>
                        </div>
                    </div>

                    <!-- Interest rate (optional) -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">
                            Interest rate %
                            <span style="font-weight: 400; color: #8a8172">(optional)</span>
                        </label>
                        <div style="display: flex; gap: 8px">
                            <input v-model="form.interest_rate" type="number" inputmode="decimal" min="0" max="100" step="0.01"
                                placeholder="e.g. 2"
                                style="flex: 1; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; box-sizing: border-box; outline: none" />
                            <div style="display: flex; border: 1px solid #e0dacd; border-radius: 10px; overflow: hidden; flex: none">
                                <button type="button" @click="form.interest_rate_period = 'monthly'"
                                    style="padding: 0 12px; height: 40px; border: none; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: background .15s"
                                    :style="form.interest_rate_period === 'monthly' ? 'background:#211d17;color:#fdfbf5' : 'background:#fffdf8;color:#6b6355'">
                                    Monthly
                                </button>
                                <button type="button" @click="form.interest_rate_period = 'annual'"
                                    style="padding: 0 12px; height: 40px; border: none; border-left: 1px solid #e0dacd; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: background .15s"
                                    :style="form.interest_rate_period === 'annual' ? 'background:#211d17;color:#fdfbf5' : 'background:#fffdf8;color:#6b6355'">
                                    Annual
                                </button>
                            </div>
                        </div>
                        <div v-if="form.errors.interest_rate" style="font-size: 12px; color: #c0512c">{{ form.errors.interest_rate }}</div>
                    </div>

                    <!-- Buttons -->
                    <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px">
                        <button type="button" @click="modalOpen = false"
                            style="height: 40px; padding: 0 18px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #4a4436">
                            Cancel
                        </button>
                        <button type="submit" :disabled="form.processing"
                            style="height: 40px; padding: 0 18px; background: #211d17; color: #fdfbf5; border: none; border-radius: 10px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                            {{ editingDebt ? 'Save changes' : 'Add debt' }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Payment modal -->
        <Dialog v-model:open="payModalOpen">
            <DialogContent style="max-width: 360px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        Record payment
                    </DialogTitle>
                </DialogHeader>

                <div v-if="payingDebt" style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px">
                    <span style="width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                        :style="{ background: payingDebt.bg }">
                        <span class="msf" style="font-size: 18px" :style="{ color: payingDebt.color }">{{ payingDebt.icon }}</span>
                    </span>
                    <div>
                        <div style="font-weight: 600; font-size: 14px">{{ payingDebt.name }}</div>
                        <div style="font-size: 12px; color: #8a8172">{{ formatMoney(payingDebt.balance) }} remaining</div>
                    </div>
                </div>

                <form @submit.prevent="submitPayment" style="display: flex; flex-direction: column; gap: 14px; margin-top: 4px">

                    <!-- Amount -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Payment amount (₱)</label>
                        <input v-model="payForm.amount" type="text" inputmode="decimal" required autofocus
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="payAmountHasExpr" style="font-size: 12px; color: #8a8172">= ₱{{ payAmountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                        <div v-if="payForm.errors.amount" style="font-size: 12px; color: #c0512c">{{ payForm.errors.amount }}</div>
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

                    <!-- Merchant / description -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Description</label>
                        <input v-model="payForm.merchant" type="text" placeholder="e.g. Car loan payment"
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                    </div>

                    <!-- Date -->
                    <div style="display: flex; flex-direction: column; gap: 6px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">Date</label>
                        <input v-model="payForm.transacted_at" type="date" required
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                        <div v-if="payForm.errors.transacted_at" style="font-size: 12px; color: #c0512c">{{ payForm.errors.transacted_at }}</div>
                    </div>

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

        <!-- Calculator modal -->
        <Dialog v-model:open="calcModalOpen">
            <DialogContent style="max-width: 380px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        Payoff Calculator
                    </DialogTitle>
                </DialogHeader>

                <div v-if="calcDebt" style="margin-top: 2px">
                    <!-- Debt identity -->
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px">
                        <span style="width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                            :style="{ background: calcDebt.bg }">
                            <span class="msf" style="font-size: 18px" :style="{ color: calcDebt.color }">{{ calcDebt.icon }}</span>
                        </span>
                        <div>
                            <div style="font-weight: 600; font-size: 14px">{{ calcDebt.name }}</div>
                            <div style="font-size: 12px; color: #8a8172">
                                {{ formatMoney(calcDebt.balance) }} · {{ calcDebt.interest_rate }}% {{ calcDebt.interest_rate_period }}
                            </div>
                        </div>
                    </div>

                    <!-- Mode toggle -->
                    <div style="display: flex; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; margin-bottom: 16px">
                        <button type="button" @click="calcMode = 'months'; calcInput = ''"
                            style="flex: 1; height: 38px; border: none; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s"
                            :style="calcMode === 'months' ? 'background:#211d17;color:#fdfbf5' : 'background:#fffdf8;color:#6b6355'">
                            How long?
                        </button>
                        <button type="button" @click="calcMode = 'payment'; calcInput = ''"
                            style="flex: 1; height: 38px; border: none; border-left: 1px solid #e0dacd; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s"
                            :style="calcMode === 'payment' ? 'background:#211d17;color:#fdfbf5' : 'background:#fffdf8;color:#6b6355'">
                            How much?
                        </button>
                    </div>

                    <!-- Input -->
                    <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px">
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436">
                            <template v-if="calcMode === 'months'">Monthly payment (₱)</template>
                            <template v-else>Months to pay off</template>
                        </label>
                        <input v-model="calcInput" type="number" inputmode="decimal" min="0" step="any" autofocus
                            :placeholder="calcMode === 'months' ? 'e.g. 5000' : 'e.g. 24'"
                            style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
                    </div>

                    <!-- Result -->
                    <template v-if="calcResult">
                        <div v-if="!calcResult.valid" style="background: #fdf3ed; border: 1px solid #f5c9a8; border-radius: 12px; padding: 12px 16px; font-size: 13px; color: #8a3800">
                            {{ calcResult.error }}
                        </div>
                        <div v-else style="background: #f5f2ea; border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 12px">
                            <div style="display: flex; justify-content: space-between; align-items: baseline">
                                <span style="font-size: 13px; color: #6b6355">
                                    <template v-if="calcMode === 'months'">Months to pay off</template>
                                    <template v-else>Monthly payment</template>
                                </span>
                                <span style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 22px; color: #211d17">
                                    <template v-if="calcMode === 'months'">{{ calcResult.months }} mo</template>
                                    <template v-else>{{ formatMoney(calcResult.payment) }}</template>
                                </span>
                            </div>
                            <div style="height: 1px; background: #e0dacd"></div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px">
                                <span style="color: #6b6355">Total interest</span>
                                <span style="font-weight: 600; color: #c0512c">{{ formatMoney(calcResult.totalInterest) }}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px">
                                <span style="color: #6b6355">Total paid</span>
                                <span style="font-weight: 600; color: #211d17">{{ formatMoney(calcResult.totalPaid) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
