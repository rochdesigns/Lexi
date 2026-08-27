<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    destroyDebt,
    storeDebt,
    updateDebt,
    updateSettings,
} from '@/actions/App/Http/Controllers/PayoffPlanController';

type DebtType = 'revolving' | 'installment';

type PayoffDebt = {
    id: number;
    name: string;
    balance: number;
    monthly_rate: number | null;
    minimum_payment: number;
    debt_type: DebtType;
    due_day: number | null;
    payoff_month: number | null;
    total_interest: number | null;
};

type PayoffSettings = { strategy: 'avalanche' | 'snowball'; extra_monthly: number };

const props = defineProps<{
    debts: PayoffDebt[];
    settings: PayoffSettings;
    monthsRemaining: number | null;
    onTrack: boolean;
    debtFreeDate: string | null;
}>();

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

// ── Strategy & extra payment ────────────────────────────────────────────

function setStrategy(strategy: 'avalanche' | 'snowball'): void {
    if (strategy === props.settings.strategy) {
        return;
    }

    router.patch(updateSettings().url, { strategy, extra_monthly: props.settings.extra_monthly }, { preserveScroll: true });
}

const extraMonthlyInput = ref(String(props.settings.extra_monthly));

function submitExtraMonthly(): void {
    const value = Number(extraMonthlyInput.value);
    const normalized = Number.isFinite(value) && value >= 0 ? value : props.settings.extra_monthly;
    extraMonthlyInput.value = String(normalized);

    if (normalized === props.settings.extra_monthly) {
        return;
    }

    router.patch(updateSettings().url, { strategy: props.settings.strategy, extra_monthly: normalized }, { preserveScroll: true });
}

// ── Add / edit debt (inline) ────────────────────────────────────────────

const editingId = ref<number | 'new' | null>(null);
const debtForm = useForm({
    name: '',
    balance: 0,
    monthly_rate: null as number | null,
    minimum_payment: 0,
    debt_type: 'installment' as DebtType,
    due_day: null as number | null,
});

function openAddRow(): void {
    editingId.value = 'new';
    debtForm.clearErrors();
    debtForm.name = '';
    debtForm.balance = 0;
    debtForm.monthly_rate = null;
    debtForm.minimum_payment = 0;
    debtForm.debt_type = 'installment';
    debtForm.due_day = null;
}

function openEditRow(debt: PayoffDebt): void {
    editingId.value = debt.id;
    debtForm.clearErrors();
    debtForm.name = debt.name;
    debtForm.balance = debt.balance;
    debtForm.monthly_rate = debt.monthly_rate;
    debtForm.minimum_payment = debt.minimum_payment;
    debtForm.debt_type = debt.debt_type;
    debtForm.due_day = debt.due_day;
}

function cancelRow(): void {
    editingId.value = null;
    debtForm.clearErrors();
}

function submitRow(): void {
    if (editingId.value === 'new') {
        debtForm.post(storeDebt().url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
    } else if (editingId.value !== null) {
        debtForm.patch(updateDebt(editingId.value).url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
    }
}

function removeDebt(debt: PayoffDebt): void {
    if (!window.confirm(`Delete ${debt.name}?`)) {
        return;
    }

    router.delete(destroyDebt(debt.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Payoff Plan" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5">
            <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                Payoff Plan
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">Track when you'll be debt-free</p>
        </div>

        <!-- Headline -->
        <div
            class="mb-5 rounded-[22px] px-6 py-7 sm:px-8"
            style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)"
        >
            <template v-if="debts.length === 0">
                <p class="text-[15px] font-medium text-white/85">Add a debt to see your payoff plan.</p>
            </template>
            <template v-else-if="onTrack && debtFreeDate">
                <p class="text-[13.5px] font-medium text-white/72">Debt-free by</p>
                <p class="mt-2 font-['Bricolage_Grotesque'] text-[40px] font-extrabold leading-none text-white">
                    {{ debtFreeDate }}
                </p>
                <p v-if="monthsRemaining !== null" class="mt-2 text-[13px] text-white/72">{{ monthsRemaining }} months remaining</p>
            </template>
            <template v-else>
                <p class="text-[15px] font-semibold" style="color: #ffd27a">
                    Not on track — current payments don't cover accruing interest.
                </p>
            </template>
        </div>

        <!-- Strategy & extra payment -->
        <div
            v-if="debts.length > 0"
            class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Strategy</h2>

            <div class="grid grid-cols-2 gap-3">
                <button
                    type="button"
                    class="rounded-[14px] p-4 text-left transition-colors"
                    :style="settings.strategy === 'avalanche'
                        ? { background: 'var(--hab-brand)', color: 'var(--hab-brand-ink)' }
                        : { background: 'var(--hab-chip-fill)', color: 'var(--hab-ink)' }"
                    @click="setStrategy('avalanche')"
                >
                    <p class="text-[14px] font-semibold">Avalanche</p>
                    <p class="mt-0.5 text-[12px] opacity-80">Highest interest rate first</p>
                </button>
                <button
                    type="button"
                    class="rounded-[14px] p-4 text-left transition-colors"
                    :style="settings.strategy === 'snowball'
                        ? { background: 'var(--hab-brand)', color: 'var(--hab-brand-ink)' }
                        : { background: 'var(--hab-chip-fill)', color: 'var(--hab-ink)' }"
                    @click="setStrategy('snowball')"
                >
                    <p class="text-[14px] font-semibold">Snowball</p>
                    <p class="mt-0.5 text-[12px] opacity-80">Lowest balance first</p>
                </button>
            </div>

            <div class="mt-4">
                <label class="text-[13px]" style="color: var(--hab-muted)">Extra monthly payment</label>
                <input
                    v-model="extraMonthlyInput"
                    type="number"
                    min="0"
                    step="0.01"
                    class="mt-1.5 block w-full rounded-[10px] border px-3 py-2 text-[14px] sm:w-48"
                    style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)"
                    @blur="submitExtraMonthly"
                    @keyup.enter="submitExtraMonthly"
                />
            </div>
        </div>

        <!-- Debt list -->
        <div
            class="rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Debts</h2>

            <div v-for="(debt, i) in debts" :key="debt.id">
                <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

                <div v-if="editingId !== debt.id" class="flex items-center gap-3 py-1">
                    <button type="button" class="min-w-0 flex-1 text-left" @click="openEditRow(debt)">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-[14px] font-medium" style="color: var(--hab-ink)">{{ debt.name }}</span>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                            >
                                {{ debt.debt_type === 'revolving' ? 'Revolving' : 'Installment' }}
                            </span>
                        </div>
                        <div class="text-[12px]" style="color: var(--hab-muted)">
                            {{ formatMoney(debt.balance) }} balance
                            <span v-if="debt.monthly_rate !== null">· {{ debt.monthly_rate }}% monthly</span>
                            · {{ formatMoney(debt.minimum_payment) }}/mo min
                            <span v-if="debt.due_day !== null">· due day {{ debt.due_day }}</span>
                        </div>
                    </button>
                    <div class="shrink-0 text-right">
                        <div v-if="debt.payoff_month !== null" class="text-[13px] font-semibold" style="color: var(--hab-ink)">
                            Payoff: month {{ debt.payoff_month }}
                        </div>
                        <div v-else class="text-[13px] font-semibold" style="color: #c0512c">Not on track</div>
                        <div v-if="debt.total_interest !== null" class="text-[12px]" style="color: var(--hab-muted)">
                            {{ formatMoney(debt.total_interest) }} interest
                        </div>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded shrink-0"
                        style="font-size: 18px; color: var(--hab-faint)"
                        @click="removeDebt(debt)"
                    >
                        delete
                    </button>
                </div>

                <!-- Inline edit form -->
                <div v-else class="rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div class="sm:col-span-1">
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Name</label>
                            <input v-model="debtForm.name" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Balance</label>
                            <input v-model.number="debtForm.balance" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Minimum payment</label>
                            <input v-model.number="debtForm.minimum_payment" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Monthly rate (optional)</label>
                            <input v-model.number="debtForm.monthly_rate" type="number" min="0" max="100" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Debt type</label>
                            <select v-model="debtForm.debt_type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                                <option value="installment">Installment</option>
                                <option value="revolving">Revolving</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Due day (optional)</label>
                            <input v-model.number="debtForm.due_day" type="number" min="1" max="31" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="debtForm.processing" @click="submitRow">
                            Confirm
                        </button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="debtForm.processing" @click="cancelRow">
                            Cancel
                        </button>
                    </div>
                    <div v-if="debtForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.name }}</div>
                    <div v-if="debtForm.errors.balance" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.balance }}</div>
                    <div v-if="debtForm.errors.minimum_payment" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.minimum_payment }}</div>
                    <div v-if="debtForm.errors.monthly_rate" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.monthly_rate }}</div>
                    <div v-if="debtForm.errors.due_day" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.due_day }}</div>
                </div>
            </div>

            <div v-if="debts.length > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

            <!-- Add row -->
            <button v-if="editingId !== 'new'" type="button" class="flex w-full items-center gap-2 py-2 text-[13.5px] font-medium" style="color: var(--hab-accent)" @click="openAddRow">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span>
                Add a debt
            </button>
            <div v-else class="rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <div class="sm:col-span-1">
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Name</label>
                        <input v-model="debtForm.name" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Balance</label>
                        <input v-model.number="debtForm.balance" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Minimum payment</label>
                        <input v-model.number="debtForm.minimum_payment" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Monthly rate (optional)</label>
                        <input v-model.number="debtForm.monthly_rate" type="number" min="0" max="100" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Debt type</label>
                        <select v-model="debtForm.debt_type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                            <option value="installment">Installment</option>
                            <option value="revolving">Revolving</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Due day (optional)</label>
                        <input v-model.number="debtForm.due_day" type="number" min="1" max="31" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="debtForm.processing" @click="submitRow">
                        Confirm
                    </button>
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="debtForm.processing" @click="cancelRow">
                        Cancel
                    </button>
                </div>
                <div v-if="debtForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.name }}</div>
                <div v-if="debtForm.errors.balance" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.balance }}</div>
                <div v-if="debtForm.errors.minimum_payment" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.minimum_payment }}</div>
                <div v-if="debtForm.errors.monthly_rate" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.monthly_rate }}</div>
                <div v-if="debtForm.errors.due_day" class="mt-2 text-[12px]" style="color: #c0512c">{{ debtForm.errors.due_day }}</div>
            </div>
        </div>
    </div>
</template>
