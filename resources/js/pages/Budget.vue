<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    confirmExtraPayment,
    confirmSweep,
    declineSweep,
    destroyBill,
    destroyEnvelope,
    markBillPaid,
    storeBill,
    storeEnvelope,
    updateBill,
    updateEnvelope,
} from '@/actions/App/Http/Controllers/BudgetController';

type DebtMinimum = { id: number; name: string; minimum_payment: number };
type BillRow = {
    id: number;
    bill_id: number;
    name: string;
    expected_amount: number;
    actual_amount: number | null;
    due_day: number | null;
    paid_at: string | null;
};
type EnvelopeRow = { id: number; envelope_id: number; name: string; icon: string | null; target: number; spent: number };
type SweepCandidate = { id: number; envelope_name: string; freed_amount: number };
type PriorityDebt = { id: number; name: string } | null;
type AssetAccount = { id: number; name: string };
type BillTemplate = { id: number; name: string; expected_amount: number; due_day: number | null; active: boolean };
type EnvelopeTemplate = { id: number; name: string; icon: string | null; default_target: number; active: boolean };

const props = defineProps<{
    year: number;
    month: number;
    monthLabel: string;
    debtMinimums: DebtMinimum[];
    bills: BillRow[];
    envelopes: EnvelopeRow[];
    sweepCandidates: SweepCandidate[];
    leftover: number;
    priorityDebt: PriorityDebt;
    assetAccounts: AssetAccount[];
    billTemplates: BillTemplate[];
    envelopeTemplates: EnvelopeTemplate[];
}>();

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

// ── Month navigation ─────────────────────────────────────────────────────

function goToMonth(year: number, month: number): void {
    router.get('/budget', { year, month }, { preserveScroll: true });
}

function previousMonth(): void {
    const month = props.month === 1 ? 12 : props.month - 1;
    const year = props.month === 1 ? props.year - 1 : props.year;
    goToMonth(year, month);
}

function nextMonth(): void {
    const month = props.month === 12 ? 1 : props.month + 1;
    const year = props.month === 12 ? props.year + 1 : props.year;
    goToMonth(year, month);
}

// ── Manage bill templates ────────────────────────────────────────────────

const billsManagerOpen = ref(false);
const editingBillId = ref<number | 'new' | null>(null);
const billForm = useForm({
    name: '',
    expected_amount: 0,
    due_day: null as number | null,
    active: true,
});

function openAddBill(): void {
    editingBillId.value = 'new';
    billForm.clearErrors();
    billForm.name = '';
    billForm.expected_amount = 0;
    billForm.due_day = null;
    billForm.active = true;
}

function openEditBill(bill: BillTemplate): void {
    editingBillId.value = bill.id;
    billForm.clearErrors();
    billForm.name = bill.name;
    billForm.expected_amount = bill.expected_amount;
    billForm.due_day = bill.due_day;
    billForm.active = bill.active;
}

function cancelBillForm(): void {
    editingBillId.value = null;
    billForm.clearErrors();
}

function submitBillForm(): void {
    if (editingBillId.value === 'new') {
        billForm.post(storeBill().url, { preserveScroll: true, onSuccess: () => (editingBillId.value = null) });
    } else if (editingBillId.value !== null) {
        billForm.patch(updateBill(editingBillId.value).url, { preserveScroll: true, onSuccess: () => (editingBillId.value = null) });
    }
}

function removeBill(bill: BillTemplate): void {
    if (!window.confirm(`Delete ${bill.name}? This month's entry and its history will be removed too.`)) {
        return;
    }

    router.delete(destroyBill(bill.id).url, { preserveScroll: true });
}

// ── Manage envelope templates ────────────────────────────────────────────

const envelopesManagerOpen = ref(false);
const editingEnvelopeId = ref<number | 'new' | null>(null);
const envelopeForm = useForm({
    name: '',
    icon: null as string | null,
    default_target: 0,
    active: true,
});

function openAddEnvelope(): void {
    editingEnvelopeId.value = 'new';
    envelopeForm.clearErrors();
    envelopeForm.name = '';
    envelopeForm.icon = null;
    envelopeForm.default_target = 0;
    envelopeForm.active = true;
}

function openEditEnvelope(envelope: EnvelopeTemplate): void {
    editingEnvelopeId.value = envelope.id;
    envelopeForm.clearErrors();
    envelopeForm.name = envelope.name;
    envelopeForm.icon = envelope.icon;
    envelopeForm.default_target = envelope.default_target;
    envelopeForm.active = envelope.active;
}

function cancelEnvelopeForm(): void {
    editingEnvelopeId.value = null;
    envelopeForm.clearErrors();
}

function submitEnvelopeForm(): void {
    if (editingEnvelopeId.value === 'new') {
        envelopeForm.post(storeEnvelope().url, { preserveScroll: true, onSuccess: () => (editingEnvelopeId.value = null) });
    } else if (editingEnvelopeId.value !== null) {
        envelopeForm.patch(updateEnvelope(editingEnvelopeId.value).url, { preserveScroll: true, onSuccess: () => (editingEnvelopeId.value = null) });
    }
}

function removeEnvelope(envelope: EnvelopeTemplate): void {
    if (!window.confirm(`Delete ${envelope.name}? Past transactions tagged with it will keep their history but lose the tag.`)) {
        return;
    }

    router.delete(destroyEnvelope(envelope.id).url, { preserveScroll: true });
}

// ── Mark a bill paid ─────────────────────────────────────────────────────

const payingBillId = ref<number | null>(null);
const payForm = useForm({ actual_amount: 0 });

function openPayBill(bill: BillRow): void {
    payingBillId.value = bill.id;
    payForm.clearErrors();
    payForm.actual_amount = bill.expected_amount;
}

function cancelPayBill(): void {
    payingBillId.value = null;
    payForm.clearErrors();
}

function submitPayBill(billId: number): void {
    payForm.post(markBillPaid(billId).url, { preserveScroll: true, onSuccess: () => (payingBillId.value = null) });
}

// ── Sweep nudges and the extra-payment suggestion ────────────────────────

const sweepAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);
const extraPaymentAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);

function confirmSweepFor(candidate: SweepCandidate): void {
    if (sweepAccountId.value === null) {
        return;
    }

    router.post(confirmSweep(candidate.id).url, { from_account_id: sweepAccountId.value }, { preserveScroll: true });
}

function declineSweepFor(candidate: SweepCandidate): void {
    router.post(declineSweep(candidate.id).url, {}, { preserveScroll: true });
}

function confirmExtraPaymentNow(): void {
    if (extraPaymentAccountId.value === null) {
        return;
    }

    router.post(
        confirmExtraPayment().url,
        { from_account_id: extraPaymentAccountId.value, year: props.year, month: props.month },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Budget" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                    Budget
                </h1>
                <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">Every dollar assigned, debt first</p>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-faint)" @click="previousMonth">
                    chevron_left
                </button>
                <span class="min-w-[110px] text-center text-[14px] font-medium" style="color: var(--hab-ink)">{{ props.monthLabel }}</span>
                <button type="button" class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-faint)" @click="nextMonth">
                    chevron_right
                </button>
            </div>
        </div>

        <!-- Sweep nudges -->
        <template v-if="props.priorityDebt">
            <div v-for="candidate in props.sweepCandidates" :key="candidate.id" class="mb-4 rounded-[22px] p-5"
                style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
                <p class="text-[14px]" style="color: var(--hab-ink)">
                    You freed {{ formatMoney(candidate.freed_amount) }} in {{ candidate.envelope_name }} — send it to
                    <span class="font-semibold">{{ props.priorityDebt?.name }}</span>?
                </p>
                <div class="mt-2 flex items-center gap-2">
                    <select v-model.number="sweepAccountId" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                        style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                        <option v-for="account in props.assetAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                        @click="confirmSweepFor(candidate)">
                        Send it
                    </button>
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                        @click="declineSweepFor(candidate)">
                        Keep in envelope
                    </button>
                </div>
            </div>
        </template>

        <!-- Debt minimums -->
        <div class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <h2 class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Debt minimums</h2>
            <p v-if="props.debtMinimums.length === 0" class="text-[14px]" style="color: var(--hab-muted)">
                No active debts. Add one on <a href="/payoff-plan" class="underline">Payoff Plan</a>.
            </p>
            <div v-for="(debt, i) in props.debtMinimums" :key="debt.id">
                <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                <div class="flex items-center justify-between py-1 text-[14px]">
                    <a href="/payoff-plan" style="color: var(--hab-ink)">{{ debt.name }}</a>
                    <span style="color: var(--hab-muted)">{{ formatMoney(debt.minimum_payment) }}</span>
                </div>
            </div>
        </div>

        <!-- Bills -->
        <div class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Bills</h2>
                <button type="button" class="text-[13px] font-medium" style="color: var(--hab-accent)" @click="billsManagerOpen = !billsManagerOpen">
                    Manage bills
                </button>
            </div>

            <p v-if="props.bills.length === 0" class="text-[14px]" style="color: var(--hab-muted)">No bills set up this month.</p>
            <div v-for="(bill, i) in props.bills" :key="bill.id">
                <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                <div class="flex items-center justify-between py-1">
                    <div>
                        <div class="text-[14px]" style="color: var(--hab-ink)">{{ bill.name }}</div>
                        <div class="text-[12px]" style="color: var(--hab-muted)">
                            {{ formatMoney(bill.actual_amount ?? bill.expected_amount) }}
                            <span v-if="bill.paid_at">· paid {{ bill.paid_at }}</span>
                            <span v-else-if="bill.due_day">· due day {{ bill.due_day }}</span>
                        </div>
                    </div>
                    <button v-if="!bill.paid_at && payingBillId !== bill.id" type="button" class="text-[12.5px] font-medium"
                        style="color: var(--hab-accent)" @click="openPayBill(bill)">
                        Mark paid
                    </button>
                </div>
                <div v-if="payingBillId === bill.id" class="mt-1 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="flex items-center gap-2">
                        <input v-model.number="payForm.actual_amount" type="number" min="0.01" step="0.01"
                            class="w-32 rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                            :disabled="payForm.processing" @click="submitPayBill(bill.id)">
                            Confirm
                        </button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                            :disabled="payForm.processing" @click="cancelPayBill">
                            Cancel
                        </button>
                    </div>
                    <div v-if="payForm.errors.actual_amount" class="mt-2 text-[12px]" style="color: #c0512c">{{ payForm.errors.actual_amount }}</div>
                </div>
            </div>

            <div v-if="billsManagerOpen" class="mt-4 border-t pt-4" style="border-color: var(--hab-hairline)">
                <div v-for="(template, i) in props.billTemplates" :key="template.id">
                    <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                    <div v-if="editingBillId !== template.id" class="flex items-center justify-between py-1 text-[13px]">
                        <span :style="{ color: template.active ? 'var(--hab-ink)' : 'var(--hab-muted)' }">
                            {{ template.name }} — {{ formatMoney(template.expected_amount) }}
                            <span v-if="!template.active">(inactive)</span>
                        </span>
                        <div class="flex items-center gap-3">
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="openEditBill(template)">edit</button>
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="removeBill(template)">delete</button>
                        </div>
                    </div>
                </div>

                <div v-if="editingBillId !== null" class="mt-2 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <input v-model="billForm.name" type="text" placeholder="Name" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model.number="billForm.expected_amount" type="number" min="0.01" step="0.01" placeholder="Amount"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model.number="billForm.due_day" type="number" min="1" max="31" placeholder="Due day"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <label class="flex items-center gap-2 text-[12px]" style="color: var(--hab-muted)">
                            <input v-model="billForm.active" type="checkbox" class="h-4 w-4" /> Active
                        </label>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                            :disabled="billForm.processing" @click="submitBillForm">Confirm</button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                            :disabled="billForm.processing" @click="cancelBillForm">Cancel</button>
                    </div>
                    <div v-if="billForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ billForm.errors.name }}</div>
                    <div v-if="billForm.errors.expected_amount" class="mt-2 text-[12px]" style="color: #c0512c">{{ billForm.errors.expected_amount }}</div>
                </div>
                <button v-else type="button" class="mt-2 flex items-center gap-2 text-[13px] font-medium" style="color: var(--hab-accent)"
                    @click="openAddBill">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add a bill
                </button>
            </div>
        </div>

        <!-- Envelopes -->
        <div class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Envelopes</h2>
                <button type="button" class="text-[13px] font-medium" style="color: var(--hab-accent)"
                    @click="envelopesManagerOpen = !envelopesManagerOpen">
                    Manage envelopes
                </button>
            </div>

            <p v-if="props.envelopes.length === 0" class="text-[14px]" style="color: var(--hab-muted)">No envelopes set up this month.</p>
            <div v-for="(envelope, i) in props.envelopes" :key="envelope.id">
                <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                <div class="py-1">
                    <div class="flex items-center justify-between text-[14px]">
                        <span style="color: var(--hab-ink)">{{ envelope.name }}</span>
                        <span style="color: var(--hab-muted)">{{ formatMoney(envelope.spent) }} / {{ formatMoney(envelope.target) }}</span>
                    </div>
                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full" style="background: var(--hab-chip-fill)">
                        <div class="h-full rounded-full" style="background: var(--hab-accent)"
                            :style="{ width: Math.min(100, envelope.target > 0 ? (envelope.spent / envelope.target) * 100 : 0) + '%' }"></div>
                    </div>
                </div>
            </div>

            <div v-if="envelopesManagerOpen" class="mt-4 border-t pt-4" style="border-color: var(--hab-hairline)">
                <div v-for="(template, i) in props.envelopeTemplates" :key="template.id">
                    <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                    <div v-if="editingEnvelopeId !== template.id" class="flex items-center justify-between py-1 text-[13px]">
                        <span :style="{ color: template.active ? 'var(--hab-ink)' : 'var(--hab-muted)' }">
                            {{ template.name }} — {{ formatMoney(template.default_target) }}
                            <span v-if="!template.active">(inactive)</span>
                        </span>
                        <div class="flex items-center gap-3">
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="openEditEnvelope(template)">edit</button>
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="removeEnvelope(template)">delete</button>
                        </div>
                    </div>
                </div>

                <div v-if="editingEnvelopeId !== null" class="mt-2 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <input v-model="envelopeForm.name" type="text" placeholder="Name" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model.number="envelopeForm.default_target" type="number" min="0.01" step="0.01" placeholder="Target"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model="envelopeForm.icon" type="text" placeholder="Icon (optional)"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <label class="flex items-center gap-2 text-[12px]" style="color: var(--hab-muted)">
                            <input v-model="envelopeForm.active" type="checkbox" class="h-4 w-4" /> Active
                        </label>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                            :disabled="envelopeForm.processing" @click="submitEnvelopeForm">Confirm</button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                            :disabled="envelopeForm.processing" @click="cancelEnvelopeForm">Cancel</button>
                    </div>
                    <div v-if="envelopeForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ envelopeForm.errors.name }}</div>
                    <div v-if="envelopeForm.errors.default_target" class="mt-2 text-[12px]" style="color: #c0512c">{{ envelopeForm.errors.default_target }}</div>
                </div>
                <button v-else type="button" class="mt-2 flex items-center gap-2 text-[13px] font-medium" style="color: var(--hab-accent)"
                    @click="openAddEnvelope">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add an envelope
                </button>
            </div>
        </div>

        <!-- Leftover suggestion -->
        <div v-if="props.priorityDebt && props.leftover > 0" class="rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <p class="text-[14px]" style="color: var(--hab-ink)">
                You have {{ formatMoney(props.leftover) }} left — send it to
                <span class="font-semibold">{{ props.priorityDebt.name }}</span>?
            </p>
            <div class="mt-2 flex items-center gap-2">
                <select v-model.number="extraPaymentAccountId" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                    style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                    <option v-for="account in props.assetAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                </select>
                <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                    @click="confirmExtraPaymentNow">
                    Send it
                </button>
            </div>
        </div>
    </div>
</template>
