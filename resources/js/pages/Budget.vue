<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    confirmExtraPayment,
    confirmSweep,
    coverOverspend,
    dealAcrossEnvelopes,
    declineSweep,
    destroyBill,
    destroyEnvelope,
    markBillsPaid,
    storeBill,
    storeEnvelope,
    unmarkBillPaid,
    updateBill,
    updateEnvelope,
} from '@/actions/App/Http/Controllers/BudgetController';
import MoneyInput from '@/components/MoneyInput.vue';

type EnvelopeStatus = 'over' | 'tight' | 'spare' | 'on_pace';

type DebtMinimum = {
    id: number;
    name: string;
    minimum_payment: number;
    due_day: number | null;
    paid_this_period: number;
};
type BillRow = {
    id: number;
    bill_id: number;
    name: string;
    expected_amount: number;
    actual_amount: number | null;
    due_day: number | null;
    paid_from_account_id: number | null;
    paid_at: string | null;
};
type EnvelopeRow = {
    id: number;
    envelope_id: number;
    name: string;
    icon: string | null;
    target: number;
    spent: number;
    remaining: number;
    status: EnvelopeStatus;
};
type SweepCandidate = {
    id: number;
    envelope_name: string;
    freed_amount: number;
};
type PriorityDebt = { id: number; name: string } | null;
type AssetAccount = { id: number; name: string; balance: number };
type PaymentAccount = AssetAccount & { is_liability: boolean };
type BillFrequency = 'monthly' | 'quarterly' | 'yearly';
type BillTemplate = {
    id: number;
    name: string;
    expected_amount: number;
    frequency: BillFrequency;
    anchor_month: number | null;
    amount_varies: boolean;
    paid_from_account_id: number | null;
    due_day: number | null;
    active: boolean;
};
type EnvelopeTemplate = {
    id: number;
    name: string;
    icon: string | null;
    default_target: number;
    active: boolean;
};
type BudgetSummary = {
    income: number;
    debtMinimums: number;
    bills: number;
    envelopes: number;
    leftover: number;
    placed: number;
};
type SafeToSpend = {
    unspent: number;
    perDay: number;
    daysRemaining: number;
    planned: number;
    cashAvailable: number | null;
    cashKnown: boolean;
    limitedByCash: boolean;
};
type PlannedDebtPayment = {
    id: number;
    name: string;
    amount: number;
    minimum: number;
    due_day: number | null;
    already_paid: number;
} | null;
type MoveSuggestion = {
    from_id: number;
    from_name: string;
    spare: number;
    to_id: number;
    to_name: string;
} | null;

type NotificationType =
    | 'bill_overdue'
    | 'bill_due_soon'
    | 'envelope_over_budget'
    | 'debt_minimum_overdue'
    | 'debt_minimum_due_soon';
type BudgetNotification = {
    type: NotificationType;
    severity: 'critical' | 'warning';
    subject: string;
    days?: number;
    amount?: number;
};

const props = defineProps<{
    year: number;
    month: number;
    monthLabel: string;
    monthName: string;
    debtMinimums: DebtMinimum[];
    bills: BillRow[];
    envelopes: EnvelopeRow[];
    sweepCandidates: SweepCandidate[];
    leftover: number;
    safeToSpend: SafeToSpend;
    sealedTotal: number;
    plannedDebtPayment: PlannedDebtPayment;
    moveSuggestion: MoveSuggestion;
    summary: BudgetSummary;
    notifications: BudgetNotification[];
    priorityDebt: PriorityDebt;
    assetAccounts: AssetAccount[];
    billPaymentAccounts: PaymentAccount[];
    billTemplates: BillTemplate[];
    envelopeTemplates: EnvelopeTemplate[];
}>();

// ── Formatting ───────────────────────────────────────────────────────────

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted =
        '₱' +
        abs.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

    return n < 0 ? `-${formatted}` : formatted;
}

/**
 * A card's balance is what is owed on it, not money on hand. Without saying so,
 * "HSBC CC — ₱31,436.06" reads like funds available to spend.
 */
function paymentAccountLabel(account: PaymentAccount): string {
    return account.is_liability
        ? `${account.name} — ${formatMoney(account.balance)} owed`
        : `${account.name} — ${formatMoney(account.balance)}`;
}

function notificationMessage(notification: BudgetNotification): string {
    switch (notification.type) {
        case 'bill_overdue':
            return `${notification.subject} is overdue — was due ${notification.days} day${notification.days === 1 ? '' : 's'} ago.`;
        case 'bill_due_soon':
            return notification.days === 0
                ? `${notification.subject} is due today.`
                : `${notification.subject} is due in ${notification.days} day${notification.days === 1 ? '' : 's'}.`;
        case 'envelope_over_budget':
            return `${notification.subject} is over budget by ${formatMoney(notification.amount ?? 0)}.`;
        case 'debt_minimum_overdue':
            return `${notification.subject} minimum payment is overdue — was due ${notification.days} day${notification.days === 1 ? '' : 's'} ago.`;
        case 'debt_minimum_due_soon':
            return notification.days === 0
                ? `${notification.subject} minimum payment is due today.`
                : `${notification.subject} minimum payment is due in ${notification.days} day${notification.days === 1 ? '' : 's'}.`;
    }
}

// ── Envelope reads ───────────────────────────────────────────────────────

const overspentEnvelopes = computed(() =>
    props.envelopes.filter((envelope) => envelope.status === 'over'),
);
const sealedEnvelopes = computed(() =>
    props.envelopes.filter((envelope) => envelope.status !== 'over'),
);
const tightCount = computed(
    () =>
        props.envelopes.filter((envelope) => envelope.status === 'tight')
            .length,
);

/** A month with nothing set up reads differently from one that's been spent down. */
const isEmptyMonth = computed(
    () => props.envelopes.length === 0 && props.bills.length === 0,
);

const unpaidBills = computed(() =>
    props.bills.filter((bill) => bill.paid_at === null),
);
const unpaidBillsTotal = computed(() =>
    unpaidBills.value.reduce((sum, bill) => sum + bill.expected_amount, 0),
);

/** The Bills card in the envelope grid: one tile standing for every sealed bill. */
const billsEnvelopeTotal = computed(() => unpaidBillsTotal.value);

/** Envelopes fill from the bottom with what survives, so the fill is what's left. */
function fillHeight(envelope: EnvelopeRow): string {
    if (envelope.target <= 0) {
        return '0%';
    }

    return `${Math.min(100, Math.max(0, (envelope.remaining / envelope.target) * 100))}%`;
}

const STATUS_LABEL: Record<EnvelopeStatus, string> = {
    over: 'torn open',
    tight: 'tight',
    spare: 'spare',
    on_pace: '',
};

function statusColor(status: EnvelopeStatus): string {
    if (status === 'tight') {
        return 'var(--hab-apricot-ink)';
    }

    return status === 'spare' ? 'var(--hab-accent)' : 'var(--hab-muted)';
}

/** Everything already promised to a date this month, bills and minimums together. */
type PromisedRow = {
    key: string;
    day: number | null;
    name: string;
    amount: number;
    kind: 'bill' | 'debt';
    note: string | null;
};

const promised = computed<PromisedRow[]>(() => {
    const rows: PromisedRow[] = unpaidBills.value.map((bill) => ({
        key: `bill-${bill.id}`,
        day: bill.due_day,
        name: bill.name,
        amount: bill.expected_amount,
        kind: 'bill',
        note: null,
    }));

    for (const debt of props.debtMinimums) {
        const outstanding = Math.max(
            0,
            debt.minimum_payment - debt.paid_this_period,
        );

        if (outstanding <= 0) {
            continue;
        }

        const isTarget = props.plannedDebtPayment?.id === debt.id;

        rows.push({
            key: `debt-${debt.id}`,
            day: debt.due_day,
            name: debt.name,
            amount: isTarget
                ? (props.plannedDebtPayment?.amount ?? outstanding)
                : outstanding,
            kind: 'debt',
            note:
                isTarget &&
                (props.plannedDebtPayment?.amount ?? 0) > debt.minimum_payment
                    ? 'planned payment, not the minimum'
                    : null,
        });
    }

    return rows.sort((a, b) => (a.day ?? 99) - (b.day ?? 99));
});

/**
 * Whether anything recurring exists for this month at all. Distinguishes a
 * month with nothing set up from one where everything is already covered —
 * both leave "Already promised" empty, but they mean opposite things.
 */
const hasAnythingPromised = computed(
    () => props.bills.length > 0 || props.debtMinimums.length > 0,
);

/** Bills paid and minimums covered — what emptied the promised list. */
type SettledRow = {
    key: string;
    name: string;
    amount: number;
    billPeriodId: number | null;
};

const settledThisMonth = computed<SettledRow[]>(() => {
    const rows: SettledRow[] = props.bills
        .filter((bill) => bill.paid_at !== null)
        .map((bill) => ({
            key: `bill-${bill.id}`,
            name: bill.name,
            amount: bill.actual_amount ?? bill.expected_amount,
            billPeriodId: bill.id,
        }));

    for (const debt of props.debtMinimums) {
        if (
            debt.paid_this_period >= debt.minimum_payment &&
            debt.minimum_payment > 0
        ) {
            rows.push({
                key: `debt-${debt.id}`,
                name: `${debt.name} minimum`,
                amount: debt.paid_this_period,
                // Debt payments are ledger entries, undone from the Ledger.
                billPeriodId: null,
            });
        }
    }

    return rows;
});

/** Puts a bill back to unpaid, returning the money it took with it. */
function undoBillPayment(row: SettledRow): void {
    if (row.billPeriodId === null) {
        return;
    }

    if (
        !window.confirm(
            `Put ${row.name} back to unpaid? The ${formatMoney(row.amount)} it took comes back to the account it left.`,
        )
    ) {
        return;
    }

    router.delete(unmarkBillPaid(row.billPeriodId).url, {
        preserveScroll: true,
    });
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

// ── 13a · Deal across envelopes ──────────────────────────────────────────

const dealSheetOpen = ref(false);
const dealAllocations = ref<Record<number, number>>({});
const dealError = ref<string | null>(null);

const dealPlaced = computed(() =>
    Object.values(dealAllocations.value).reduce(
        (sum, amount) => sum + (Number(amount) || 0),
        0,
    ),
);
const dealRemaining = computed(
    () => Math.round((props.leftover - dealPlaced.value) * 100) / 100,
);

function openDealSheet(): void {
    dealSheetOpen.value = true;
    dealError.value = null;
    dealAllocations.value = Object.fromEntries(
        props.envelopes.map((envelope) => [envelope.id, 0]),
    );
}

/** Fills the overspent and tight envelopes first, in that order, until the hand runs out. */
function fillTightOnes(): void {
    let left = props.leftover;
    const next: Record<number, number> = Object.fromEntries(
        props.envelopes.map((envelope) => [envelope.id, 0]),
    );

    const needy = [...props.envelopes]
        .filter(
            (envelope) =>
                envelope.status === 'over' || envelope.status === 'tight',
        )
        .sort((a, b) => a.remaining - b.remaining);

    for (const envelope of needy) {
        if (left <= 0) {
            break;
        }

        const shortfall =
            envelope.status === 'over'
                ? -envelope.remaining
                : Math.max(0, envelope.target - envelope.remaining);
        const give = Math.round(Math.min(left, shortfall) * 100) / 100;

        next[envelope.id] = give;
        left = Math.round((left - give) * 100) / 100;
    }

    dealAllocations.value = next;
}

function adjustAllocation(envelopeId: number, delta: number): void {
    const current = Number(dealAllocations.value[envelopeId]) || 0;
    dealAllocations.value[envelopeId] = Math.max(
        0,
        Math.round((current + delta) * 100) / 100,
    );
}

function submitDeal(): void {
    const allocations = Object.entries(dealAllocations.value)
        .map(([id, amount]) => ({
            envelope_period_id: Number(id),
            amount: Number(amount) || 0,
        }))
        .filter((allocation) => allocation.amount > 0);

    if (allocations.length === 0) {
        dealError.value = 'Place something first.';

        return;
    }

    router.post(
        dealAcrossEnvelopes().url,
        { year: props.year, month: props.month, allocations },
        {
            preserveScroll: true,
            onSuccess: () => (dealSheetOpen.value = false),
            onError: (errors) =>
                (dealError.value =
                    Object.values(errors)[0] ?? 'That could not be placed.'),
        },
    );
}

// ── 13b · Send it all to the debt ────────────────────────────────────────

const sendSheetOpen = ref(false);
const sendAmount = ref(0);
const sendAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);
const sendCushion = ref(false);
const sendError = ref<string | null>(null);

const CUSHION = 5000;

const sendAccount = computed(
    () =>
        props.assetAccounts.find(
            (account) => account.id === sendAccountId.value,
        ) ?? null,
);

function openSendSheet(): void {
    sendSheetOpen.value = true;
    sendError.value = null;
    sendCushion.value = false;
    sendAmount.value = props.leftover;
    sendAccountId.value = props.assetAccounts[0]?.id ?? null;
}

watch(sendCushion, (keepCushion) => {
    sendAmount.value = keepCushion
        ? Math.max(0, Math.round((props.leftover - CUSHION) * 100) / 100)
        : props.leftover;
});

function submitSend(): void {
    if (sendAccountId.value === null) {
        sendError.value = 'Pick an account to pay from.';

        return;
    }

    if (sendAmount.value <= 0) {
        sendError.value = 'Enter an amount above zero.';

        return;
    }

    router.post(
        confirmExtraPayment().url,
        {
            from_account_id: sendAccountId.value,
            year: props.year,
            month: props.month,
            amount: sendAmount.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (sendSheetOpen.value = false),
            onError: (errors) =>
                (sendError.value =
                    Object.values(errors)[0] ??
                    'That payment could not be sent.'),
        },
    );
}

// ── 13c · Cover the overspend ────────────────────────────────────────────

type CoverSource = 'hand' | 'envelope' | 'debt';

const coverEnvelope = ref<EnvelopeRow | null>(null);
const coverSource = ref<CoverSource>('hand');
const coverFromId = ref<number | null>(null);
const coverError = ref<string | null>(null);

const coverAmount = computed(() =>
    coverEnvelope.value
        ? Math.round(-coverEnvelope.value.remaining * 100) / 100
        : 0,
);

const coverCandidates = computed(() =>
    props.envelopes.filter(
        (envelope) =>
            envelope.id !== coverEnvelope.value?.id && envelope.remaining > 0,
    ),
);

const coverFrom = computed(
    () =>
        coverCandidates.value.find(
            (envelope) => envelope.id === coverFromId.value,
        ) ?? null,
);

function openCoverSheet(envelope: EnvelopeRow): void {
    coverEnvelope.value = envelope;
    coverError.value = null;
    coverSource.value = 'hand';
    coverFromId.value = coverCandidates.value[0]?.id ?? null;
}

function submitCover(): void {
    const envelope = coverEnvelope.value;

    if (!envelope) {
        return;
    }

    if (coverSource.value === 'envelope' && coverFromId.value === null) {
        coverError.value = 'Pick an envelope to take it from.';

        return;
    }

    const payload = {
        source: coverSource.value,
        amount: coverAmount.value,
        from_envelope_period_id:
            coverSource.value === 'envelope' ? coverFromId.value : null,
    };

    router.post(coverOverspend(envelope.id).url, payload, {
        preserveScroll: true,
        onSuccess: () => (coverEnvelope.value = null),
        onError: (errors) =>
            (coverError.value =
                Object.values(errors)[0] ?? 'That could not be covered.'),
    });
}

/** The suggestion card's one-tap move: take the spare and give it to the tight one. */
function acceptMoveSuggestion(): void {
    const suggestion = props.moveSuggestion;
    const target = props.envelopes.find(
        (envelope) => envelope.id === suggestion?.to_id,
    );

    if (!suggestion || !target) {
        return;
    }

    const amount =
        target.remaining < 0
            ? -target.remaining
            : Math.min(suggestion.spare, target.target - target.remaining);

    router.post(
        coverOverspend(target.id).url,
        {
            source: 'envelope',
            amount: Math.round(amount * 100) / 100,
            from_envelope_period_id: suggestion.from_id,
        },
        { preserveScroll: true },
    );
}

// ── 13e · Mark bills paid ────────────────────────────────────────────────

/** Cash first, but a card rather than nothing when cash accounts do not exist. */
const defaultBillAccountId = computed<number | null>(
    () =>
        props.assetAccounts[0]?.id ?? props.billPaymentAccounts[0]?.id ?? null,
);

const billsSheetOpen = ref(false);
const billTicks = ref<Record<number, boolean>>({});
const billAmounts = ref<Record<number, number>>({});
const billAccounts = ref<Record<number, number | null>>({});
const billsAccountId = ref<number | null>(defaultBillAccountId.value);
const billsError = ref<string | null>(null);

const tickedBills = computed(() =>
    unpaidBills.value.filter((bill) => billTicks.value[bill.id]),
);
/** Ticked bills that still need somewhere to come out of. */
const tickedBillsWithoutAccount = computed(() =>
    tickedBills.value.filter((bill) => !billAccounts.value[bill.id]),
);

const tickedTotal = computed(() =>
    tickedBills.value.reduce(
        (sum, bill) => sum + (Number(billAmounts.value[bill.id]) || 0),
        0,
    ),
);

function openBillsSheet(): void {
    billsSheetOpen.value = true;
    billsError.value = null;
    billTicks.value = {};
    billAmounts.value = Object.fromEntries(
        unpaidBills.value.map((bill) => [bill.id, bill.expected_amount]),
    );
    // Each bill starts on the account it says it is paid from, so the common
    // case needs no picking at all.
    billAccounts.value = Object.fromEntries(
        unpaidBills.value.map((bill) => [
            bill.id,
            bill.paid_from_account_id ?? defaultBillAccountId.value,
        ]),
    );
    billsAccountId.value = defaultBillAccountId.value;
}

function submitBills(): void {
    if (tickedBills.value.length === 0) {
        billsError.value = 'Tick at least one bill.';

        return;
    }

    // Bills that name their own account need no fallback; only the ones that
    // don't have to land somewhere.
    const fallbackAccountId =
        billsAccountId.value ??
        tickedBills.value
            .map((bill) => billAccounts.value[bill.id])
            .find((id) => id != null) ??
        null;

    if (fallbackAccountId === null) {
        billsError.value = 'Pick an account to pay from.';

        return;
    }

    const payments = tickedBills.value.map((bill) => ({
        bill_period_id: bill.id,
        actual_amount:
            Number(billAmounts.value[bill.id]) || bill.expected_amount,
        from_account_id: billAccounts.value[bill.id] ?? fallbackAccountId,
    }));

    router.post(
        markBillsPaid().url,
        { from_account_id: fallbackAccountId, payments },
        {
            preserveScroll: true,
            onSuccess: () => (billsSheetOpen.value = false),
            onError: (errors) =>
                (billsError.value =
                    Object.values(errors)[0] ??
                    'Those bills could not be marked paid.'),
        },
    );
}

// ── 13d · New envelope, and managing templates ───────────────────────────

const ICON_CHOICES = [
    'shopping_cart',
    'restaurant',
    'directions_bus',
    'home',
    'pets',
    'medication',
    'school',
    'sports_esports',
];

const envelopeSheetOpen = ref(false);
const envelopeManagerOpen = ref(false);
const editingEnvelopeId = ref<number | null>(null);
const envelopeForm = useForm({
    name: '',
    icon: null as string | null,
    default_target: 0,
    active: true,
});

function openNewEnvelope(): void {
    envelopeSheetOpen.value = true;
    editingEnvelopeId.value = null;
    envelopeForm.clearErrors();
    envelopeForm.name = '';
    envelopeForm.icon = ICON_CHOICES[0];
    envelopeForm.default_target = 0;
    envelopeForm.active = true;
}

function openEditEnvelope(template: EnvelopeTemplate): void {
    envelopeSheetOpen.value = true;
    editingEnvelopeId.value = template.id;
    envelopeForm.clearErrors();
    envelopeForm.name = template.name;
    envelopeForm.icon = template.icon;
    envelopeForm.default_target = template.default_target;
    envelopeForm.active = template.active;
}

function submitEnvelopeForm(): void {
    const onSuccess = () => {
        envelopeSheetOpen.value = false;
        editingEnvelopeId.value = null;
    };

    if (editingEnvelopeId.value === null) {
        envelopeForm.post(storeEnvelope().url, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        envelopeForm.patch(updateEnvelope(editingEnvelopeId.value).url, {
            preserveScroll: true,
            onSuccess,
        });
    }
}

function removeEnvelope(template: EnvelopeTemplate): void {
    if (
        !window.confirm(
            `Delete the ${template.name} envelope? Its history stays in the ledger.`,
        )
    ) {
        return;
    }

    router.delete(destroyEnvelope(template.id).url, { preserveScroll: true });
}

// ── Bill templates ───────────────────────────────────────────────────────

const billManagerOpen = ref(false);
const editingBillId = ref<number | 'new' | null>(null);
const billForm = useForm({
    name: '',
    expected_amount: 0,
    frequency: 'monthly' as BillFrequency,
    anchor_month: null as number | null,
    amount_varies: false,
    paid_from_account_id: null as number | null,
    due_day: null as number | null,
    active: true,
});

function openAddBill(): void {
    editingBillId.value = 'new';
    billForm.clearErrors();
    billForm.name = '';
    billForm.expected_amount = 0;
    billForm.frequency = 'monthly';
    billForm.anchor_month = props.month;
    billForm.amount_varies = false;
    billForm.paid_from_account_id = props.assetAccounts[0]?.id ?? null;
    billForm.due_day = null;
    billForm.active = true;
}

function openEditBill(template: BillTemplate): void {
    editingBillId.value = template.id;
    billForm.clearErrors();
    billForm.name = template.name;
    billForm.expected_amount = template.expected_amount;
    billForm.frequency = template.frequency;
    billForm.anchor_month = template.anchor_month ?? props.month;
    billForm.amount_varies = template.amount_varies;
    billForm.paid_from_account_id =
        template.paid_from_account_id ?? props.assetAccounts[0]?.id ?? null;
    billForm.due_day = template.due_day;
    billForm.active = template.active;
}

function cancelBillForm(): void {
    editingBillId.value = null;
    billForm.clearErrors();
}

function submitBillForm(): void {
    if (editingBillId.value === 'new') {
        billForm.post(storeBill().url, {
            preserveScroll: true,
            onSuccess: () => (editingBillId.value = null),
        });
    } else if (editingBillId.value !== null) {
        billForm.patch(updateBill(editingBillId.value).url, {
            preserveScroll: true,
            onSuccess: () => (editingBillId.value = null),
        });
    }
}

// ── 13f · What adding this bill does to the month ────────────────────────

const BILL_FREQUENCIES: { key: BillFrequency; label: string }[] = [
    { key: 'monthly', label: 'Monthly' },
    { key: 'quarterly', label: 'Quarterly' },
    { key: 'yearly', label: 'Yearly' },
];

/** A guessed icon, so the field has a face before anything is saved. */
const BILL_ICON_HINTS: { match: RegExp; icon: string }[] = [
    { match: /gas|gasul|petron|lpg/i, icon: 'local_fire_department' },
    { match: /electric|meralco|power/i, icon: 'bolt' },
    { match: /water|maynilad|manila water/i, icon: 'water_drop' },
    { match: /internet|fiber|converge|pldt|globe|wifi/i, icon: 'wifi' },
    { match: /rent|landlord|lease/i, icon: 'home' },
    { match: /phone|mobile|load|postpaid/i, icon: 'smartphone' },
    { match: /insur|health|hmo/i, icon: 'health_and_safety' },
    { match: /school|tuition/i, icon: 'school' },
    { match: /netflix|spotify|subscript/i, icon: 'subscriptions' },
];

const billIcon = computed(() => {
    const hint = BILL_ICON_HINTS.find((entry) =>
        entry.match.test(billForm.name),
    );

    return hint?.icon ?? 'receipt_long';
});

/** Whether the bill being edited actually lands in the month on screen. */
const billLandsThisMonth = computed(() => {
    if (billForm.frequency === 'monthly') {
        return true;
    }

    const cadence = billForm.frequency === 'quarterly' ? 3 : 12;
    const anchor = billForm.anchor_month ?? props.month;

    return (props.month - anchor) % cadence === 0;
});

const billAmountAdded = computed(() =>
    billLandsThisMonth.value ? billForm.expected_amount || 0 : 0,
);

const billsEnvelopeAfter = computed(
    () => unpaidBillsTotal.value + billAmountAdded.value,
);

/** Share of the new envelope that was already there, for the two-tone bar. */
const billsExistingShare = computed(() => {
    if (billsEnvelopeAfter.value <= 0) {
        return 100;
    }

    return (unpaidBillsTotal.value / billsEnvelopeAfter.value) * 100;
});

/** The month promises more than it has taken in. */
const isOverCommitted = computed(() => props.leftover < 0);

const handAfterBill = computed(() =>
    Math.max(0, props.leftover - billAmountAdded.value),
);

/**
 * Every day of the month with money already promised to it, so the strip can
 * show where the new bill lands among what is already there.
 */
type MonthDay = {
    day: number;
    amount: number;
    label: string | null;
    tone: 'bill' | 'debt' | 'new' | 'empty';
};

const daysInMonth = computed(() =>
    new Date(props.year, props.month, 0).getDate(),
);

const monthShape = computed<MonthDay[]>(() => {
    const days: MonthDay[] = Array.from(
        { length: daysInMonth.value },
        (_, i) => ({
            day: i + 1,
            amount: 0,
            label: null,
            tone: 'empty',
        }),
    );

    const place = (
        day: number | null,
        amount: number,
        label: string,
        tone: MonthDay['tone'],
    ) => {
        if (day === null || day < 1 || day > days.length) {
            return;
        }

        const slot = days[day - 1];
        slot.amount += amount;

        // The bill being added always wins the day it lands on, name included,
        // so the strip names what you are actually placing.
        if (tone === 'new' || slot.tone === 'empty') {
            slot.tone = tone;
            slot.label = tone === 'new' ? label : (slot.label ?? label);
        }
    };

    for (const bill of unpaidBills.value) {
        place(bill.due_day, bill.expected_amount, bill.name, 'bill');
    }

    for (const debt of props.debtMinimums) {
        const outstanding = Math.max(
            0,
            debt.minimum_payment - debt.paid_this_period,
        );

        if (outstanding > 0) {
            place(debt.due_day, outstanding, debt.name, 'debt');
        }
    }

    if (billAmountAdded.value > 0) {
        place(
            billForm.due_day,
            billAmountAdded.value,
            billForm.name || 'New bill',
            'new',
        );
    }

    return days;
});

const monthShapeMax = computed(() =>
    Math.max(1, ...monthShape.value.map((day) => day.amount)),
);

function monthDayHeight(day: MonthDay): string {
    return day.amount <= 0
        ? '14%'
        : `${Math.max(20, (day.amount / monthShapeMax.value) * 100)}%`;
}

const MONTH_DAY_TONES: Record<MonthDay['tone'], string> = {
    empty: '#dfe6d6',
    bill: 'var(--hab-accent)',
    debt: 'var(--hab-apricot)',
    new: '#5c8a5f',
};

/** The few labelled days worth naming under the strip. */
const monthShapeLegend = computed(() =>
    monthShape.value
        .filter((day) => day.amount > 0 && day.label !== null)
        .slice(0, 4)
        .map((day) => `${day.day} — ${day.label}`),
);

function removeBill(template: BillTemplate): void {
    if (!window.confirm(`Delete the ${template.name} bill?`)) {
        return;
    }

    router.delete(destroyBill(template.id).url, { preserveScroll: true });
}

// ── Sweep nudges ─────────────────────────────────────────────────────────

const sweepAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);

function confirmSweepFor(candidate: SweepCandidate): void {
    if (sweepAccountId.value === null) {
        return;
    }

    router.post(
        confirmSweep(candidate.id).url,
        { from_account_id: sweepAccountId.value },
        { preserveScroll: true },
    );
}

function declineSweepFor(candidate: SweepCandidate): void {
    router.post(declineSweep(candidate.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Budget" />

    <div
        class="hab-garden mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
        style="
            background-image: radial-gradient(
                var(--hab-paper-dot) 1px,
                transparent 1px
            );
            background-size: 4px 4px;
        "
    >
        <!-- Header -->
        <div class="mb-[18px] flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="m-0 font-['Bricolage_Grotesque'] text-[30px] font-bold tracking-[-.02em]"
                    style="color: var(--hab-ink)"
                >
                    {{ props.monthName }}
                </h1>
                <p
                    class="m-0 mt-1 text-[13.5px]"
                    style="color: var(--hab-muted)"
                >
                    {{ formatMoney(props.summary.placed) }} of
                    {{ formatMoney(props.summary.income) }} placed ·
                    {{ props.safeToSpend.daysRemaining }} day{{
                        props.safeToSpend.daysRemaining === 1 ? '' : 's'
                    }}
                    to go
                </p>
            </div>
            <div
                class="flex items-center gap-[6px] rounded-[12px] p-1"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <button
                    type="button"
                    class="material-symbols-rounded p-1"
                    style="font-size: 18px; color: var(--hab-faint)"
                    @click="previousMonth"
                >
                    chevron_left
                </button>
                <span
                    class="px-[6px] text-[13px] font-semibold"
                    style="color: var(--hab-ink)"
                    >{{ props.monthLabel }}</span
                >
                <button
                    type="button"
                    class="material-symbols-rounded p-1"
                    style="font-size: 18px; color: var(--hab-faint)"
                    @click="nextMonth"
                >
                    chevron_right
                </button>
            </div>
        </div>

        <!-- Notifications -->
        <div
            v-if="props.notifications.length > 0"
            class="hab-anim-row-in mb-4 rounded-[18px] p-4"
            style="
                background: var(--hab-clay-tint);
                border: 1px solid var(--hab-clay-border);
            "
        >
            <div
                v-for="(notification, i) in props.notifications"
                :key="i"
                class="flex items-start gap-2 py-[3px] text-[13px]"
            >
                <span
                    class="msf shrink-0"
                    style="font-size: 17px"
                    :style="{
                        color:
                            notification.severity === 'critical'
                                ? 'var(--hab-clay)'
                                : 'var(--hab-apricot-ink)',
                    }"
                >
                    {{
                        notification.severity === 'critical'
                            ? 'error'
                            : 'schedule'
                    }}
                </span>
                <span
                    :style="{
                        color:
                            notification.severity === 'critical'
                                ? 'var(--hab-clay)'
                                : 'var(--hab-ink-secondary)',
                    }"
                >
                    {{ notificationMessage(notification) }}
                </span>
            </div>
        </div>

        <!-- Sweep nudges -->
        <template v-if="props.priorityDebt">
            <div
                v-for="candidate in props.sweepCandidates"
                :key="candidate.id"
                class="hab-anim-row-in mb-4 rounded-[18px] p-4"
                style="
                    background: var(--hab-apricot-tint);
                    border: 1px solid var(--hab-apricot-border);
                "
            >
                <p
                    class="m-0 text-[13.5px]"
                    style="color: var(--hab-apricot-ink)"
                >
                    You freed {{ formatMoney(candidate.freed_amount) }} in
                    {{ candidate.envelope_name }} last month — send it to
                    <span class="font-semibold">{{
                        props.priorityDebt?.name
                    }}</span
                    >?
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <select
                        v-model.number="sweepAccountId"
                        class="rounded-[8px] border px-2 py-[6px] text-[12.5px]"
                        style="
                            border-color: var(--hab-apricot-border);
                            background: var(--hab-card-bg);
                            color: var(--hab-ink);
                        "
                    >
                        <option
                            v-for="account in props.assetAccounts"
                            :key="account.id"
                            :value="account.id"
                        >
                            {{ account.name }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="rounded-full px-3 py-[6px] text-[12px] font-semibold"
                        style="background: var(--hab-apricot); color: #fff7ef"
                        @click="confirmSweepFor(candidate)"
                    >
                        Send it
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-[6px] text-[12px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="declineSweepFor(candidate)"
                    >
                        Keep in envelope
                    </button>
                </div>
            </div>
        </template>

        <!-- ── Hero: safe to spend, backed by the stack ───────────────── -->
        <div
            class="grid grid-cols-1 overflow-hidden rounded-[28px] lg:grid-cols-[1fr_372px]"
            style="
                border: 1px solid var(--hab-card-border);
                box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
            "
        >
            <div class="px-8 py-[30px]" style="background: var(--hab-card-bg)">
                <p
                    class="m-0 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                    style="color: var(--hab-faint)"
                >
                    Safe to spend today
                </p>
                <p
                    class="hab-anim-pop m-0 mt-2 text-[52px] leading-none font-extrabold tracking-[-.04em] tabular-nums sm:text-[72px]"
                    style="
                        font-family: var(--hab-mono);
                        color: var(--hab-accent);
                    "
                >
                    {{
                        props.safeToSpend.cashKnown
                            ? formatMoney(props.safeToSpend.perDay)
                            : '—'
                    }}
                </p>
                <p
                    class="m-0 mt-3 max-w-[460px] text-[15px] leading-[1.5]"
                    style="color: var(--hab-ink-secondary)"
                >
                    <template v-if="!props.safeToSpend.cashKnown">
                        No account on file, so there's no telling what you
                        actually have. Add one with its balance and this becomes
                        a number you can spend against.
                    </template>
                    <template v-else-if="props.safeToSpend.limitedByCash">
                        Your envelopes plan for
                        {{ formatMoney(props.safeToSpend.planned) }}, but only
                        {{ formatMoney(props.safeToSpend.cashAvailable ?? 0) }}
                        is actually in your accounts once this month's bills and
                        minimums are set aside. This is the honest figure.
                    </template>
                    <template v-else-if="props.safeToSpend.unspent > 0">
                        Every bill, minimum and planned debt payment for the
                        rest of the month is already accounted for. Spend this
                        and nothing breaks.
                    </template>
                    <template v-else-if="isEmptyMonth">
                        Nothing set up yet. Add an envelope for what you spend
                        on, a bill for what's due, and this number will tell you
                        what's safe to spend each day.
                    </template>
                    <template v-else>
                        Nothing left in your envelopes for the rest of the
                        month. Anything you spend now comes out of something
                        else.
                    </template>
                </p>

                <div class="mt-5 flex flex-wrap gap-[9px]">
                    <span
                        v-if="props.sealedTotal > 0"
                        class="hab-anim-row-in flex items-center gap-[7px] rounded-full px-[14px] py-2 text-[12.5px] font-semibold"
                        style="
                            --hab-delay: 0.95s;
                            background: var(--hab-chip-fill);
                            border: 1px solid var(--hab-leaf-soft);
                            color: var(--hab-ink-secondary);
                        "
                    >
                        <span
                            class="msf"
                            style="font-size: 15px; color: var(--hab-accent)"
                            >shield</span
                        >
                        {{ formatMoney(props.sealedTotal) }} sealed for bills
                        and minimums
                    </span>
                    <span
                        v-if="
                            props.plannedDebtPayment &&
                            props.plannedDebtPayment.amount > 0
                        "
                        class="hab-anim-row-in flex items-center gap-[7px] rounded-full px-[14px] py-2 text-[12.5px] font-semibold"
                        style="
                            --hab-delay: 1.05s;
                            background: var(--hab-apricot-tint);
                            border: 1px solid var(--hab-apricot-border);
                            color: var(--hab-apricot-ink);
                        "
                    >
                        <span class="msf" style="font-size: 15px">bolt</span>
                        {{ formatMoney(props.plannedDebtPayment.amount) }} to
                        {{ props.plannedDebtPayment.name }}
                        <template v-if="props.plannedDebtPayment.due_day"
                            >on the
                            {{ props.plannedDebtPayment.due_day }}th</template
                        >
                    </span>
                </div>

                <div
                    v-if="props.safeToSpend.unspent > 0"
                    class="mt-[22px] flex items-center gap-3 border-t pt-[18px]"
                    style="border-color: var(--hab-hairline)"
                >
                    <span
                        class="msf hab-anim-sway"
                        style="font-size: 19px; color: var(--hab-accent)"
                        >savings</span
                    >
                    <p
                        class="m-0 flex-1 text-[12.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        {{ formatMoney(props.safeToSpend.unspent) }}
                        <template v-if="props.safeToSpend.limitedByCash"
                            >of real money behind your envelopes</template
                        >
                        <template v-else>left across your envelopes</template>,
                        spread over {{ props.safeToSpend.daysRemaining }} day{{
                            props.safeToSpend.daysRemaining === 1 ? '' : 's'
                        }}.
                    </p>
                </div>
            </div>

            <!-- Still in your hand -->
            <div
                class="p-[26px]"
                style="
                    background: var(--hab-btn-dark-bg);
                    color: var(--hab-btn-dark-ink);
                "
            >
                <p
                    class="m-0 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                    style="color: #a9bb9d"
                >
                    Still in your hand
                </p>
                <p
                    class="hab-anim-row-in m-0 mt-2 text-[40px] leading-none font-extrabold tracking-[-.03em] tabular-nums"
                    :style="{
                        '--hab-delay': '0.1s',
                        fontFamily: 'var(--hab-mono)',
                        color: isOverCommitted ? '#f0a58c' : undefined,
                    }"
                >
                    {{ formatMoney(props.leftover) }}
                </p>

                <div
                    v-if="props.leftover > 0"
                    class="relative mt-4"
                    style="height: 74px"
                >
                    <div
                        class="hab-anim-band-in absolute top-0 right-[14px] left-0 rounded-[8px]"
                        style="
                            --hab-delay: 0.15s;
                            height: 38px;
                            background: #4a5c47;
                            border: 1px solid #5d7057;
                        "
                    ></div>
                    <div
                        class="hab-anim-band-in absolute top-2 right-2 left-[6px] rounded-[8px]"
                        style="
                            --hab-delay: 0.28s;
                            height: 38px;
                            background: #557050;
                            border: 1px solid #6a835f;
                        "
                    ></div>
                    <div
                        class="hab-anim-band-in absolute top-4 right-[2px] left-3 flex items-center gap-[9px] rounded-[8px] px-3"
                        style="
                            --hab-delay: 0.41s;
                            height: 38px;
                            background: #3f5c3c;
                            border: 1px solid #557050;
                        "
                    >
                        <span
                            class="text-[12px] font-bold"
                            style="font-family: var(--hab-mono)"
                            >{{ formatMoney(props.leftover) }} unplaced</span
                        >
                        <span
                            class="hab-anim-coin-drop ml-auto h-[13px] w-[13px] rounded-full"
                            style="
                                background: #e0b884;
                                border: 1px solid #c9a06a;
                            "
                        ></span>
                    </div>
                </div>

                <p
                    class="m-0 mt-4 text-[12.5px] leading-[1.55]"
                    style="color: #d3e0cc"
                >
                    <template v-if="props.leftover > 0">
                        Not part of Safe to spend — it hasn't been given a job
                        yet. The month isn't done until this reads ₱0.
                    </template>
                    <template v-else-if="props.summary.income <= 0">
                        Nothing has come in yet this month. Log some income and
                        it lands here, waiting to be placed.
                    </template>
                    <template v-else-if="isOverCommitted">
                        This month is promised
                        {{ formatMoney(props.leftover) }} more than came in.
                        Something has to give — trim an envelope, move a bill,
                        or log the income that is still missing.
                    </template>
                    <template v-else>
                        Every peso has a job. The month is placed.
                    </template>
                </p>

                <template v-if="props.leftover > 0">
                    <button
                        v-if="props.priorityDebt"
                        type="button"
                        class="hab-anim-breathe mt-[14px] w-full rounded-[12px] py-3 text-[13px] font-semibold"
                        style="background: var(--hab-apricot); color: #fff7ef"
                        @click="openSendSheet"
                    >
                        Send it all to {{ props.priorityDebt.name }}
                    </button>
                    <div class="mt-2">
                        <button
                            type="button"
                            class="w-full rounded-[12px] border py-[11px] text-[12.5px] font-semibold"
                            style="border-color: #4a5c47; color: #c3d2bb"
                            @click="openDealSheet"
                        >
                            Deal across envelopes
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <div
            class="mt-5 grid grid-cols-1 items-start gap-5 lg:grid-cols-[1fr_340px]"
        >
            <div class="flex flex-col gap-4">
                <!-- Needs a decision -->
                <div v-if="overspentEnvelopes.length > 0">
                    <p
                        class="m-0 mb-[10px] text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-clay)"
                    >
                        Needs a decision
                    </p>
                    <div class="grid grid-cols-1 gap-[14px] sm:grid-cols-2">
                        <div
                            v-for="envelope in overspentEnvelopes"
                            :key="envelope.id"
                            class="relative overflow-hidden rounded-[16px] p-[18px]"
                            style="
                                background: var(--hab-clay-tint);
                                border: 1px solid var(--hab-clay-border);
                            "
                        >
                            <div class="flex items-center gap-[9px]">
                                <span
                                    class="msf"
                                    style="
                                        font-size: 19px;
                                        color: var(--hab-clay);
                                    "
                                    >{{ envelope.icon ?? 'inventory_2' }}</span
                                >
                                <span
                                    class="text-[14px] font-bold"
                                    style="color: var(--hab-clay)"
                                    >{{ envelope.name }}</span
                                >
                                <span
                                    class="ml-auto rounded-full px-[9px] py-[3px] text-[10.5px] font-bold"
                                    style="
                                        background: var(--hab-clay);
                                        color: var(--hab-apricot-tint);
                                    "
                                >
                                    torn open
                                </span>
                            </div>
                            <p
                                class="m-0 mt-3 text-[24px] font-extrabold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-clay);
                                "
                            >
                                {{ formatMoney(envelope.remaining) }}
                            </p>
                            <p
                                class="m-0 mt-1 text-[12px]"
                                style="color: var(--hab-clay-ink)"
                            >
                                {{ formatMoney(envelope.spent) }} spent from a
                                {{ formatMoney(envelope.target) }} envelope —
                                the money is already gone
                            </p>
                            <button
                                type="button"
                                class="mt-[14px] rounded-[9px] px-[13px] py-2 text-[12px] font-semibold"
                                style="
                                    background: var(--hab-clay);
                                    color: var(--hab-apricot-tint);
                                "
                                @click="openCoverSheet(envelope)"
                            >
                                Cover {{ formatMoney(envelope.remaining) }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sealed and holding -->
                <div>
                    <div class="mb-[10px] flex items-baseline justify-between">
                        <p
                            class="m-0 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Sealed and holding
                        </p>
                        <span
                            v-if="tightCount > 0"
                            class="flex items-center gap-[7px] text-[11.5px]"
                            style="color: var(--hab-muted)"
                        >
                            <span
                                class="relative h-[6px] w-[6px] rounded-full"
                                style="background: var(--hab-apricot)"
                            >
                                <span
                                    class="hab-anim-pulse-dot absolute inset-0 rounded-full"
                                    style="background: var(--hab-apricot)"
                                ></span>
                            </span>
                            {{ tightCount }} running tight
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-[14px] sm:grid-cols-3">
                        <div
                            v-for="(envelope, i) in sealedEnvelopes"
                            :key="envelope.id"
                            class="hab-anim-row-in relative overflow-hidden rounded-[16px] transition-transform duration-150 hover:-translate-y-[3px]"
                            style="
                                height: 132px;
                                background: var(--hab-card-bg);
                            "
                            :style="{
                                border: `1px solid ${envelope.status === 'tight' ? 'var(--hab-apricot-border)' : 'var(--hab-card-border)'}`,
                                '--hab-delay': `${0.5 + i * 0.07}s`,
                            }"
                        >
                            <div
                                class="hab-anim-spring-bar absolute right-0 bottom-0 left-0"
                                :style="{
                                    height: fillHeight(envelope),
                                    background:
                                        envelope.status === 'tight'
                                            ? 'var(--hab-apricot-tint)'
                                            : 'var(--hab-chip-fill)',
                                    borderTop: `1px solid ${envelope.status === 'tight' ? 'var(--hab-apricot-border)' : 'var(--hab-card-border)'}`,
                                    '--hab-delay': `${i * 0.05}s`,
                                }"
                            ></div>
                            <div class="relative p-4">
                                <div class="flex items-center gap-[9px]">
                                    <span
                                        class="msf"
                                        style="
                                            font-size: 18px;
                                            color: var(--hab-accent);
                                        "
                                        >{{
                                            envelope.icon ?? 'inventory_2'
                                        }}</span
                                    >
                                    <span
                                        class="truncate text-[13.5px] font-bold"
                                        style="color: var(--hab-ink)"
                                        >{{ envelope.name }}</span
                                    >
                                    <span
                                        v-if="STATUS_LABEL[envelope.status]"
                                        class="ml-auto text-[11px] font-bold"
                                        :style="{
                                            color: statusColor(envelope.status),
                                        }"
                                    >
                                        {{ STATUS_LABEL[envelope.status] }}
                                    </span>
                                </div>
                                <p
                                    class="m-0 mt-[14px] text-[22px] font-extrabold tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-ink);
                                    "
                                >
                                    {{ formatMoney(envelope.remaining) }}
                                </p>
                                <p
                                    class="m-0 mt-[3px] text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                >
                                    of {{ formatMoney(envelope.target) }} ·
                                    {{ props.safeToSpend.daysRemaining }} day{{
                                        props.safeToSpend.daysRemaining === 1
                                            ? ''
                                            : 's'
                                    }}
                                    to go
                                </p>
                            </div>
                        </div>

                        <!-- Bills, as one sealed tile -->
                        <div
                            v-if="billsEnvelopeTotal > 0"
                            class="hab-anim-row-in relative overflow-hidden rounded-[16px]"
                            style="
                                height: 132px;
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-card-border);
                                --hab-delay: 0.9s;
                            "
                        >
                            <div
                                class="hab-anim-spring-bar absolute right-0 bottom-0 left-0"
                                style="
                                    height: 100%;
                                    background: var(--hab-chip-fill);
                                    --hab-delay: 0.35s;
                                "
                            ></div>
                            <div class="relative p-4">
                                <div class="flex items-center gap-[9px]">
                                    <span
                                        class="msf"
                                        style="
                                            font-size: 18px;
                                            color: var(--hab-accent);
                                        "
                                        >receipt_long</span
                                    >
                                    <span
                                        class="text-[13.5px] font-bold"
                                        style="color: var(--hab-ink)"
                                        >Bills</span
                                    >
                                </div>
                                <p
                                    class="m-0 mt-[14px] text-[22px] font-extrabold tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-ink);
                                    "
                                >
                                    {{ formatMoney(billsEnvelopeTotal) }}
                                </p>
                                <p
                                    class="m-0 mt-[3px] text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                >
                                    sealed until each due date
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="flex flex-col items-center justify-center gap-2 rounded-[16px] text-[12.5px] font-semibold"
                            style="
                                height: 132px;
                                border: 1px dashed var(--hab-leaf-soft);
                                color: var(--hab-muted);
                            "
                            @click="openNewEnvelope"
                        >
                            <span
                                class="material-symbols-rounded"
                                style="font-size: 22px"
                                >add</span
                            >
                            New envelope
                        </button>
                    </div>

                    <button
                        type="button"
                        class="mt-3 text-[12.5px] font-semibold"
                        style="color: var(--hab-accent)"
                        @click="envelopeManagerOpen = !envelopeManagerOpen"
                    >
                        {{
                            envelopeManagerOpen
                                ? 'Done managing envelopes'
                                : 'Manage envelopes'
                        }}
                    </button>

                    <div
                        v-if="envelopeManagerOpen"
                        class="mt-3 rounded-[16px] p-4"
                        style="
                            background: var(--hab-card-bg);
                            border: 1px solid var(--hab-card-border);
                        "
                    >
                        <div
                            v-for="(template, i) in props.envelopeTemplates"
                            :key="template.id"
                        >
                            <div
                                v-if="i > 0"
                                class="my-2 h-px"
                                style="background: var(--hab-hairline)"
                            ></div>
                            <div
                                class="flex items-center justify-between py-1 text-[13px]"
                            >
                                <span
                                    :style="{
                                        color: template.active
                                            ? 'var(--hab-ink)'
                                            : 'var(--hab-muted)',
                                    }"
                                >
                                    {{ template.name }} —
                                    {{ formatMoney(template.default_target) }}
                                    <span v-if="!template.active"
                                        >(inactive)</span
                                    >
                                </span>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        class="material-symbols-rounded"
                                        style="
                                            font-size: 16px;
                                            color: var(--hab-faint);
                                        "
                                        @click="openEditEnvelope(template)"
                                    >
                                        edit
                                    </button>
                                    <button
                                        type="button"
                                        class="material-symbols-rounded"
                                        style="
                                            font-size: 16px;
                                            color: var(--hab-faint);
                                        "
                                        @click="removeEnvelope(template)"
                                    >
                                        delete
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="props.envelopeTemplates.length === 0"
                            class="m-0 text-[13px]"
                            style="color: var(--hab-muted)"
                        >
                            No envelopes yet.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Already promised ───────────────────────────────────── -->
            <div
                class="rounded-[24px] p-[22px]"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <h2
                    class="m-0 mb-1 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Already promised
                </h2>
                <p
                    class="m-0 mb-4 text-[12.5px]"
                    style="color: var(--hab-muted)"
                >
                    <template v-if="promised.length > 0">
                        {{ formatMoney(props.sealedTotal) }} waiting for a date
                        this month.
                    </template>
                    <template v-else-if="settledThisMonth.length > 0">
                        Everything due in {{ props.monthName }} is paid.
                    </template>
                    <template v-else-if="hasAnythingPromised">
                        Nothing falls due in {{ props.monthName }}.
                    </template>
                    <template v-else>
                        Bills and debt minimums wait here until their date.
                    </template>
                </p>

                <p
                    v-if="promised.length === 0 && !hasAnythingPromised"
                    class="m-0 text-[13px]"
                    style="color: var(--hab-muted)"
                >
                    Nothing set up yet — add a bill below and it'll be waiting
                    here every month.
                </p>

                <!-- What emptied the list -->
                <div
                    v-else-if="
                        promised.length === 0 && settledThisMonth.length > 0
                    "
                    class="flex flex-col gap-[10px]"
                >
                    <div
                        v-for="row in settledThisMonth"
                        :key="row.key"
                        class="flex items-center gap-3"
                    >
                        <span
                            class="msf w-8 flex-none text-center"
                            style="font-size: 17px; color: var(--hab-accent)"
                            >check_circle</span
                        >
                        <p
                            class="m-0 min-w-0 flex-1 truncate text-[13.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ row.name }}
                        </p>
                        <button
                            v-if="row.billPeriodId !== null"
                            type="button"
                            class="text-[11.5px] font-semibold"
                            style="color: var(--hab-accent)"
                            @click="undoBillPayment(row)"
                        >
                            Undo
                        </button>
                        <span
                            class="text-[13px] font-semibold tabular-nums line-through"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-faint);
                            "
                        >
                            {{ formatMoney(row.amount) }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-col gap-[10px]">
                    <div
                        v-for="row in promised"
                        :key="row.key"
                        class="flex items-center gap-3"
                        :class="row.note ? 'rounded-[12px] px-[10px] py-2' : ''"
                        :style="
                            row.note
                                ? {
                                      background: 'var(--hab-apricot-tint)',
                                      margin: '-8px -10px',
                                  }
                                : {}
                        "
                    >
                        <span
                            class="w-8 flex-none rounded-[8px] py-1 text-center text-[11px] font-bold"
                            :style="{
                                background: row.note
                                    ? '#f2e2d5'
                                    : 'var(--hab-chip-fill)',
                                color: row.note
                                    ? 'var(--hab-apricot-ink)'
                                    : 'var(--hab-muted)',
                            }"
                        >
                            {{
                                row.day !== null
                                    ? String(row.day).padStart(2, '0')
                                    : '—'
                            }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="m-0 truncate text-[13.5px]"
                                :style="{
                                    color: row.note
                                        ? 'var(--hab-apricot-ink)'
                                        : 'var(--hab-ink)',
                                    fontWeight: row.note ? 600 : 400,
                                }"
                            >
                                {{ row.name }}
                            </p>
                            <p
                                v-if="row.note"
                                class="m-0 mt-[1px] text-[11px]"
                                style="color: #8c6a4a"
                            >
                                {{ row.note }}
                            </p>
                        </div>
                        <span
                            class="text-[13px] font-semibold tabular-nums"
                            style="font-family: var(--hab-mono)"
                            :style="{
                                color: row.note
                                    ? 'var(--hab-apricot-ink)'
                                    : 'var(--hab-ink)',
                            }"
                        >
                            {{ formatMoney(row.amount) }}
                        </span>
                    </div>
                </div>

                <button
                    v-if="unpaidBills.length > 0"
                    type="button"
                    class="mt-4 w-full rounded-[12px] py-[10px] text-[12.5px] font-semibold"
                    style="
                        background: var(--hab-chip-fill);
                        border: 1px solid var(--hab-card-border);
                        color: var(--hab-ink-secondary);
                    "
                    @click="openBillsSheet"
                >
                    Mark a bill paid
                </button>
                <p
                    v-else-if="settledThisMonth.length > 0"
                    class="m-0 mt-4 text-[11.5px]"
                    style="color: var(--hab-faint)"
                >
                    Every bill this month is marked paid. Undo one to put it
                    back.
                </p>

                <button
                    type="button"
                    class="mt-2 w-full text-[12.5px] font-semibold"
                    style="color: var(--hab-accent)"
                    @click="billManagerOpen = !billManagerOpen"
                >
                    {{
                        billManagerOpen ? 'Done managing bills' : 'Manage bills'
                    }}
                </button>

                <div
                    v-if="billManagerOpen"
                    class="mt-3 border-t pt-3"
                    style="border-color: var(--hab-hairline)"
                >
                    <div
                        v-for="(template, i) in props.billTemplates"
                        :key="template.id"
                    >
                        <div
                            v-if="i > 0"
                            class="my-2 h-px"
                            style="background: var(--hab-hairline)"
                        ></div>
                        <div
                            v-if="editingBillId !== template.id"
                            class="flex items-center justify-between py-1 text-[13px]"
                        >
                            <span
                                :style="{
                                    color: template.active
                                        ? 'var(--hab-ink)'
                                        : 'var(--hab-muted)',
                                }"
                            >
                                {{ template.name }} —
                                {{ formatMoney(template.expected_amount) }}
                                <span v-if="!template.active">(inactive)</span>
                            </span>
                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="material-symbols-rounded"
                                    style="
                                        font-size: 16px;
                                        color: var(--hab-faint);
                                    "
                                    @click="openEditBill(template)"
                                >
                                    edit
                                </button>
                                <button
                                    type="button"
                                    class="material-symbols-rounded"
                                    style="
                                        font-size: 16px;
                                        color: var(--hab-faint);
                                    "
                                    @click="removeBill(template)"
                                >
                                    delete
                                </button>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="mt-2 flex items-center gap-2 text-[12.5px] font-semibold"
                        style="color: var(--hab-accent)"
                        @click="openAddBill"
                    >
                        <span
                            class="material-symbols-rounded"
                            style="font-size: 16px"
                            >add</span
                        >
                        Add a bill
                    </button>
                </div>

                <!-- Move-the-spare suggestion -->
                <div
                    v-if="props.moveSuggestion"
                    class="mt-[18px] border-t pt-4"
                    style="border-color: var(--hab-hairline)"
                >
                    <p
                        class="m-0 text-[12.5px] leading-[1.55]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        {{ props.moveSuggestion.from_name }} has
                        <span class="font-semibold">{{
                            formatMoney(props.moveSuggestion.spare)
                        }}</span>
                        spare and {{ props.moveSuggestion.to_name }} is tight.
                        Move it?
                    </p>
                    <div class="mt-[10px] flex gap-2">
                        <button
                            type="button"
                            class="rounded-[10px] px-[14px] py-[9px] text-[12.5px] font-semibold"
                            style="
                                background: var(--hab-accent);
                                color: var(--hab-btn-dark-ink);
                            "
                            @click="acceptMoveSuggestion"
                        >
                            Move it
                        </button>
                        <button
                            type="button"
                            class="px-1 py-[9px] text-[12.5px] font-semibold"
                            style="color: var(--hab-ink-secondary)"
                        >
                            Leave it
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 13a · Deal across envelopes ────────────────────────────── -->
        <div
            v-if="dealSheetOpen"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="dealSheetOpen = false"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] overflow-hidden rounded-[22px]"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 24px 48px -28px var(--hab-card-shadow);
                "
            >
                <div
                    class="px-[22px] py-5"
                    style="
                        background: var(--hab-btn-dark-bg);
                        color: var(--hab-btn-dark-ink);
                    "
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: #a9bb9d"
                            >
                                Left in your hand
                            </p>
                            <p
                                class="m-0 mt-[6px] text-[34px] leading-none font-extrabold tabular-nums"
                                style="font-family: var(--hab-mono)"
                            >
                                {{ formatMoney(dealRemaining) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 20px; color: #a9bb9d"
                            @click="dealSheetOpen = false"
                        >
                            close
                        </button>
                    </div>
                    <div
                        class="mt-3 h-[6px] overflow-hidden rounded-full"
                        style="background: #4a5c47"
                    >
                        <div
                            class="hab-progress-fill h-full rounded-full"
                            style="background: #e0b884"
                            :style="{
                                width: `${props.leftover > 0 ? Math.min(100, (dealPlaced / props.leftover) * 100) : 0}%`,
                            }"
                        ></div>
                    </div>
                    <p class="m-0 mt-2 text-[12px]" style="color: #d3e0cc">
                        {{ formatMoney(dealPlaced) }} of
                        {{ formatMoney(props.leftover) }} placed — keep going
                        until this reads ₱0.
                    </p>
                </div>

                <div class="p-[22px]">
                    <div class="flex items-center justify-between">
                        <p
                            class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Where it goes
                        </p>
                        <button
                            type="button"
                            class="text-[12.5px] font-semibold"
                            style="color: var(--hab-accent)"
                            @click="fillTightOnes"
                        >
                            Fill the tight ones for me
                        </button>
                    </div>

                    <p
                        v-if="props.envelopes.length === 0"
                        class="m-0 mt-4 text-[13px]"
                        style="color: var(--hab-muted)"
                    >
                        No envelopes to place into yet.
                    </p>

                    <div class="mt-3 flex flex-col">
                        <div
                            v-for="(envelope, i) in props.envelopes"
                            :key="envelope.id"
                            class="flex items-center gap-3 py-3"
                            :style="{
                                borderTop:
                                    i > 0
                                        ? '1px solid var(--hab-hairline)'
                                        : 'none',
                            }"
                        >
                            <span
                                class="msf"
                                style="
                                    font-size: 18px;
                                    color: var(--hab-accent);
                                "
                                >{{ envelope.icon ?? 'inventory_2' }}</span
                            >
                            <div class="min-w-0 flex-1">
                                <p
                                    class="m-0 truncate text-[13.5px] font-semibold"
                                    style="color: var(--hab-ink)"
                                >
                                    {{ envelope.name }}
                                </p>
                                <p
                                    class="m-0 mt-[1px] text-[11.5px]"
                                    :style="{
                                        color:
                                            envelope.remaining < 0
                                                ? 'var(--hab-clay)'
                                                : 'var(--hab-muted)',
                                    }"
                                >
                                    <template v-if="envelope.remaining < 0">
                                        {{ formatMoney(envelope.remaining) }}
                                        over — a refill clears it
                                    </template>
                                    <template v-else
                                        >{{ formatMoney(envelope.remaining) }}
                                        left ·
                                        {{
                                            STATUS_LABEL[envelope.status] ||
                                            'on pace'
                                        }}</template
                                    >
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="material-symbols-rounded rounded-full p-1"
                                    style="
                                        font-size: 16px;
                                        color: var(--hab-muted);
                                        background: var(--hab-chip-fill);
                                    "
                                    @click="adjustAllocation(envelope.id, -100)"
                                >
                                    remove
                                </button>
                                <MoneyInput
                                    v-model="dealAllocations[envelope.id]"
                                    class="w-[92px] rounded-[8px] border px-2 py-[6px] text-right text-[12.5px] font-semibold tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        border-color: var(--hab-card-border);
                                        background: var(--hab-card-bg);
                                        color: var(--hab-ink);
                                    "
                                />
                                <button
                                    type="button"
                                    class="material-symbols-rounded rounded-full p-1"
                                    style="
                                        font-size: 16px;
                                        color: var(--hab-muted);
                                        background: var(--hab-chip-fill);
                                    "
                                    @click="adjustAllocation(envelope.id, 100)"
                                >
                                    add
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="props.priorityDebt && dealRemaining > 0"
                        class="mt-4 flex flex-wrap items-center gap-3 rounded-[14px] p-4"
                        style="
                            background: var(--hab-apricot-tint);
                            border: 1px solid var(--hab-apricot-border);
                        "
                    >
                        <span
                            class="msf"
                            style="
                                font-size: 18px;
                                color: var(--hab-apricot-ink);
                            "
                            >bolt</span
                        >
                        <p
                            class="m-0 flex-1 text-[12.5px]"
                            style="color: var(--hab-apricot-ink)"
                        >
                            Put the last
                            <span class="font-semibold">{{
                                formatMoney(dealRemaining)
                            }}</span>
                            on {{ props.priorityDebt.name }} instead.
                        </p>
                        <button
                            type="button"
                            class="rounded-[9px] px-3 py-2 text-[12px] font-semibold"
                            style="
                                background: var(--hab-apricot);
                                color: #fff7ef;
                            "
                            @click="
                                dealSheetOpen = false;
                                openSendSheet();
                                sendAmount = dealRemaining;
                            "
                        >
                            Do that
                        </button>
                    </div>

                    <p
                        v-if="dealError"
                        class="m-0 mt-3 text-[12.5px]"
                        style="color: var(--hab-clay)"
                    >
                        {{ dealError }}
                    </p>

                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                            style="color: var(--hab-muted)"
                            @click="dealSheetOpen = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold disabled:opacity-50"
                            style="
                                background: var(--hab-accent);
                                color: var(--hab-btn-dark-ink);
                            "
                            :disabled="dealPlaced <= 0 || dealRemaining < 0"
                            @click="submitDeal"
                        >
                            <template v-if="dealRemaining > 0"
                                >Place {{ formatMoney(dealPlaced) }},
                                {{ formatMoney(dealRemaining) }} still in
                                hand</template
                            >
                            <template v-else-if="dealRemaining < 0"
                                >That's more than you have</template
                            >
                            <template v-else>Place it all</template>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 13b · Send it all to the debt ──────────────────────────── -->
        <div
            v-if="sendSheetOpen && props.priorityDebt"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="sendSheetOpen = false"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 24px 48px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3
                            class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                            style="color: var(--hab-ink)"
                        >
                            Send {{ formatMoney(sendAmount) }} to
                            {{ props.priorityDebt.name }}
                        </h3>
                        <p
                            v-if="props.plannedDebtPayment"
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            On top of the
                            {{ formatMoney(props.plannedDebtPayment.amount) }}
                            already planned<span
                                v-if="props.plannedDebtPayment.due_day"
                            >
                                for the
                                {{ props.plannedDebtPayment.due_day }}th</span
                            >.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="sendSheetOpen = false"
                    >
                        close
                    </button>
                </div>

                <div
                    class="mt-[18px] rounded-[18px] p-[18px]"
                    style="
                        background: var(--hab-chip-fill);
                        border: 1px solid var(--hab-card-border);
                    "
                >
                    <label
                        class="mb-[6px] block text-[11px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        Amount to send
                    </label>
                    <div class="flex items-center gap-2">
                        <span
                            class="text-[26px] font-bold"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-muted);
                            "
                            >₱</span
                        >
                        <MoneyInput
                            v-model="sendAmount"
                            class="h-[46px] w-full min-w-0 flex-1 border-0 border-b-2 bg-transparent p-0 text-[30px] font-extrabold tabular-nums outline-none"
                            style="
                                font-family: var(--hab-mono);
                                border-color: var(--hab-apricot);
                                color: var(--hab-ink);
                            "
                        />
                    </div>
                    <p
                        class="m-0 mt-2 text-[12px]"
                        style="color: var(--hab-muted)"
                    >
                        {{ formatMoney(props.leftover) }} is unplaced right now
                        — that's the most you can send.
                    </p>
                </div>

                <div class="mt-4 flex flex-col gap-2">
                    <div
                        class="flex items-center gap-3 rounded-[14px] p-4"
                        style="background: var(--hab-chip-fill)"
                    >
                        <span
                            class="msf"
                            style="font-size: 19px; color: var(--hab-accent)"
                            >account_balance</span
                        >
                        <div class="flex-1">
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Paid from
                            </p>
                            <select
                                v-model.number="sendAccountId"
                                class="mt-1 w-full rounded-[8px] border px-2 py-[6px] text-[13px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            >
                                <option
                                    v-for="account in props.assetAccounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                        </div>
                        <div v-if="sendAccount" class="text-right">
                            <p
                                class="m-0 text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                {{ formatMoney(sendAccount.balance) }}
                            </p>
                            <p
                                class="m-0 text-[13px] font-semibold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                →
                                {{
                                    formatMoney(
                                        sendAccount.balance - sendAmount,
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="
                            sendAccount &&
                            sendAccount.balance - sendAmount < CUSHION
                        "
                        class="flex items-start gap-3 rounded-[14px] p-4"
                        style="
                            background: var(--hab-clay-tint);
                            border: 1px solid var(--hab-clay-border);
                        "
                    >
                        <span
                            class="msf"
                            style="font-size: 18px; color: var(--hab-clay)"
                            >warning</span
                        >
                        <p
                            class="m-0 text-[12.5px]"
                            style="color: var(--hab-clay)"
                        >
                            {{ sendAccount.name }} drops to
                            {{ formatMoney(sendAccount.balance - sendAmount) }}.
                            That leaves no cushion before your next payday.
                        </p>
                    </div>
                </div>

                <label
                    class="mt-4 flex items-center gap-[10px] text-[12.5px]"
                    style="color: var(--hab-ink-secondary)"
                >
                    <input
                        v-model="sendCushion"
                        type="checkbox"
                        class="h-4 w-4"
                        style="accent-color: var(--hab-accent)"
                    />
                    Keep {{ formatMoney(CUSHION) }} back as a cushion and send
                    {{ formatMoney(Math.max(0, props.leftover - CUSHION)) }}
                    instead
                </label>

                <p
                    v-if="sendError"
                    class="m-0 mt-3 text-[12.5px]"
                    style="color: var(--hab-clay)"
                >
                    {{ sendError }}
                </p>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="sendSheetOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold disabled:opacity-50"
                        style="background: var(--hab-apricot); color: #fff7ef"
                        :disabled="sendAmount <= 0"
                        @click="submitSend"
                    >
                        Send {{ formatMoney(sendAmount) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 13c · Cover the overspend ──────────────────────────────── -->
        <div
            v-if="coverEnvelope"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="coverEnvelope = null"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 24px 48px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3
                            class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                            style="color: var(--hab-ink)"
                        >
                            {{ coverEnvelope.name }} is
                            {{ formatMoney(coverAmount) }} over
                        </h3>
                        <p
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ formatMoney(coverEnvelope.spent) }} spent from a
                            {{ formatMoney(coverEnvelope.target) }} envelope.
                            The money is already gone — this is just where it
                            comes from.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="coverEnvelope = null"
                    >
                        close
                    </button>
                </div>

                <div class="mt-5 flex flex-col gap-[10px]">
                    <button
                        type="button"
                        class="flex items-start gap-3 rounded-[14px] p-4 text-left"
                        :style="{
                            background:
                                coverSource === 'hand'
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${coverSource === 'hand' ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                        @click="coverSource = 'hand'"
                    >
                        <span
                            class="msf mt-[1px]"
                            style="font-size: 18px; color: var(--hab-accent)"
                        >
                            {{
                                coverSource === 'hand'
                                    ? 'radio_button_checked'
                                    : 'radio_button_unchecked'
                            }}
                        </span>
                        <span class="flex-1">
                            <span
                                class="block text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                                >Refill from your hand</span
                            >
                            <span
                                class="mt-1 block text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                {{ formatMoney(props.leftover) }}
                                unplaced →
                                {{
                                    formatMoney(
                                        Math.max(
                                            0,
                                            props.leftover - coverAmount,
                                        ),
                                    )
                                }}. Nothing else changes.
                            </span>
                        </span>
                        <span
                            v-if="props.leftover >= coverAmount"
                            class="rounded-full px-[10px] py-[3px] text-[10.5px] font-bold"
                            style="
                                background: var(--hab-accent);
                                color: var(--hab-btn-dark-ink);
                            "
                        >
                            cheapest
                        </span>
                    </button>

                    <button
                        v-if="coverCandidates.length > 0"
                        type="button"
                        class="flex items-start gap-3 rounded-[14px] p-4 text-left"
                        :style="{
                            background:
                                coverSource === 'envelope'
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${coverSource === 'envelope' ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                        @click="coverSource = 'envelope'"
                    >
                        <span
                            class="msf mt-[1px]"
                            style="font-size: 18px; color: var(--hab-accent)"
                        >
                            {{
                                coverSource === 'envelope'
                                    ? 'radio_button_checked'
                                    : 'radio_button_unchecked'
                            }}
                        </span>
                        <span class="flex-1">
                            <span
                                class="block text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                                >Take it from another envelope</span
                            >
                            <select
                                v-model.number="coverFromId"
                                class="mt-2 w-full rounded-[8px] border px-2 py-[6px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                                @click.stop
                            >
                                <option
                                    v-for="candidate in coverCandidates"
                                    :key="candidate.id"
                                    :value="candidate.id"
                                >
                                    {{ candidate.name }} —
                                    {{ formatMoney(candidate.remaining) }} left
                                </option>
                            </select>
                            <span
                                v-if="coverFrom"
                                class="mt-2 block text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                Leaves {{ coverFrom.name }} with
                                {{
                                    formatMoney(
                                        Math.max(
                                            0,
                                            coverFrom.remaining - coverAmount,
                                        ),
                                    )
                                }}<span
                                    v-if="coverFrom.remaining < coverAmount"
                                >
                                    —
                                    {{
                                        formatMoney(
                                            coverAmount - coverFrom.remaining,
                                        )
                                    }}
                                    short, so it can't cover it all</span
                                >.
                            </span>
                        </span>
                    </button>

                    <button
                        v-if="props.plannedDebtPayment"
                        type="button"
                        class="flex items-start gap-3 rounded-[14px] p-4 text-left"
                        :style="{
                            background:
                                coverSource === 'debt'
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${coverSource === 'debt' ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                        @click="coverSource = 'debt'"
                    >
                        <span
                            class="msf mt-[1px]"
                            style="font-size: 18px; color: var(--hab-accent)"
                        >
                            {{
                                coverSource === 'debt'
                                    ? 'radio_button_checked'
                                    : 'radio_button_unchecked'
                            }}
                        </span>
                        <span class="flex-1">
                            <span
                                class="block text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                                >Take it off the debt payment</span
                            >
                            <span
                                class="mt-1 block text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                {{
                                    formatMoney(props.plannedDebtPayment.amount)
                                }}
                                →
                                {{
                                    formatMoney(
                                        Math.max(
                                            props.plannedDebtPayment.minimum,
                                            props.plannedDebtPayment.amount -
                                                coverAmount,
                                        ),
                                    )
                                }}. The payoff date slips.
                            </span>
                        </span>
                        <span
                            class="rounded-full px-[10px] py-[3px] text-[10.5px] font-bold"
                            style="
                                background: var(--hab-clay-tint);
                                color: var(--hab-clay);
                            "
                        >
                            costs you
                        </span>
                    </button>
                </div>

                <p
                    v-if="coverError"
                    class="m-0 mt-3 text-[12.5px]"
                    style="color: var(--hab-clay)"
                >
                    {{ coverError }}
                </p>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="coverEnvelope = null"
                    >
                        Leave it over
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        @click="submitCover"
                    >
                        Cover {{ formatMoney(coverAmount) }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 13d · New envelope ─────────────────────────────────────── -->
        <div
            v-if="envelopeSheetOpen"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="envelopeSheetOpen = false"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 24px 48px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <h3
                        class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                        style="color: var(--hab-ink)"
                    >
                        {{
                            editingEnvelopeId === null
                                ? 'New envelope'
                                : 'Edit envelope'
                        }}
                    </h3>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="envelopeSheetOpen = false"
                    >
                        close
                    </button>
                </div>

                <div
                    class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-[1fr_180px]"
                >
                    <div class="flex flex-col gap-4">
                        <div>
                            <label
                                class="mb-[6px] block text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                                >Name</label
                            >
                            <input
                                v-model="envelopeForm.name"
                                type="text"
                                placeholder="Medicine"
                                class="w-full rounded-[10px] border px-3 py-[9px] text-[13.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            />
                        </div>

                        <div>
                            <label
                                class="mb-[6px] block text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                                >Icon</label
                            >
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="icon in ICON_CHOICES"
                                    :key="icon"
                                    type="button"
                                    class="flex h-9 w-9 items-center justify-center rounded-[10px]"
                                    :style="{
                                        background:
                                            envelopeForm.icon === icon
                                                ? 'var(--hab-accent)'
                                                : 'var(--hab-chip-fill)',
                                        color:
                                            envelopeForm.icon === icon
                                                ? 'var(--hab-btn-dark-ink)'
                                                : 'var(--hab-ink-secondary)',
                                    }"
                                    @click="envelopeForm.icon = icon"
                                >
                                    <span class="msf" style="font-size: 18px">{{
                                        icon
                                    }}</span>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label
                                class="mb-[6px] block text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Amount each month
                            </label>
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-[20px] font-bold"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-muted);
                                    "
                                    >₱</span
                                >
                                <MoneyInput
                                    v-model="envelopeForm.default_target"
                                    hint
                                    class="w-full rounded-[10px] border px-3 py-[9px] text-[16px] font-semibold tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        border-color: var(--hab-card-border);
                                        background: var(--hab-card-bg);
                                        color: var(--hab-ink);
                                    "
                                />
                            </div>
                        </div>

                        <label
                            class="flex items-center gap-2 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            <input
                                v-model="envelopeForm.active"
                                type="checkbox"
                                class="h-4 w-4"
                                style="accent-color: var(--hab-accent)"
                            />
                            Active
                        </label>
                    </div>

                    <!-- Preview, filling as you type -->
                    <div>
                        <p
                            class="m-0 mb-2 text-[11px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Preview
                        </p>
                        <div
                            class="relative overflow-hidden rounded-[16px]"
                            style="
                                height: 132px;
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-card-border);
                            "
                        >
                            <div
                                class="hab-progress-fill absolute right-0 bottom-0 left-0"
                                style="
                                    height: 100%;
                                    background: var(--hab-chip-fill);
                                    border-top: 1px solid var(--hab-card-border);
                                "
                            ></div>
                            <div class="relative p-4">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="msf"
                                        style="
                                            font-size: 18px;
                                            color: var(--hab-accent);
                                        "
                                        >{{
                                            envelopeForm.icon ?? 'inventory_2'
                                        }}</span
                                    >
                                    <span
                                        class="truncate text-[13.5px] font-bold"
                                        style="color: var(--hab-ink)"
                                    >
                                        {{
                                            envelopeForm.name || 'New envelope'
                                        }}
                                    </span>
                                </div>
                                <p
                                    class="m-0 mt-[14px] text-[22px] font-extrabold tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-ink);
                                    "
                                >
                                    {{
                                        formatMoney(
                                            envelopeForm.default_target || 0,
                                        )
                                    }}
                                </p>
                                <p
                                    class="m-0 mt-[3px] text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                >
                                    of
                                    {{
                                        formatMoney(
                                            envelopeForm.default_target || 0,
                                        )
                                    }}
                                    · nothing spent yet
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-4 flex items-start gap-3 rounded-[14px] p-4"
                    style="background: var(--hab-chip-fill)"
                >
                    <span
                        class="msf"
                        style="font-size: 18px; color: var(--hab-accent)"
                        >info</span
                    >
                    <p
                        class="m-0 text-[12.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        A new envelope claims
                        {{ formatMoney(envelopeForm.default_target || 0) }} of
                        the month, so your hand drops to
                        {{
                            formatMoney(
                                Math.max(
                                    0,
                                    props.leftover -
                                        (envelopeForm.default_target || 0),
                                ),
                            )
                        }}. Safe to spend and the payoff date don't move.
                    </p>
                </div>

                <p
                    v-for="(error, field) in envelopeForm.errors"
                    :key="field"
                    class="m-0 mt-2 text-[12px]"
                    style="color: var(--hab-clay)"
                >
                    {{ error }}
                </p>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="envelopeSheetOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold disabled:opacity-50"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        :disabled="envelopeForm.processing"
                        @click="submitEnvelopeForm"
                    >
                        {{
                            editingEnvelopeId === null
                                ? 'Create envelope'
                                : 'Save envelope'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 13f · Add a bill ───────────────────────────────────────── -->
        <div
            v-if="editingBillId !== null"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="cancelBillForm"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 24px 48px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3
                            class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                            style="color: var(--hab-ink)"
                        >
                            {{
                                editingBillId === 'new'
                                    ? 'Add a bill'
                                    : 'Edit bill'
                            }}
                        </h3>
                        <p
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            It'll be waiting in the Bills envelope every month.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="cancelBillForm"
                    >
                        close
                    </button>
                </div>

                <div class="mt-[18px] flex gap-[10px]">
                    <div class="flex-1">
                        <p
                            class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Bill
                        </p>
                        <div
                            class="flex items-center gap-[9px] rounded-[12px] px-[14px]"
                            style="
                                height: 44px;
                                background: var(--hab-card-bg);
                                border: 1.5px solid var(--hab-accent);
                            "
                        >
                            <span
                                class="material-symbols-rounded"
                                style="
                                    font-size: 18px;
                                    color: var(--hab-accent);
                                "
                                >{{ billIcon }}</span
                            >
                            <input
                                v-model="billForm.name"
                                type="text"
                                placeholder="Petron Gasul"
                                class="w-full min-w-0 flex-1 border-none bg-transparent p-0 text-[14.5px] font-semibold outline-none"
                                style="color: var(--hab-ink)"
                            />
                        </div>
                    </div>
                    <div style="width: 148px">
                        <p
                            class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Typical amount
                        </p>
                        <MoneyInput
                            v-model="billForm.expected_amount"
                            class="w-full rounded-[12px] px-[14px] text-[18px] font-extrabold tabular-nums"
                            style="
                                height: 44px;
                                background: var(--hab-panel);
                                border: 1px solid var(--hab-card-border);
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        />
                    </div>
                </div>

                <div class="mt-4 flex gap-[10px]">
                    <div class="flex-1">
                        <p
                            class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            How often
                        </p>
                        <div class="flex gap-[6px]">
                            <button
                                v-for="option in BILL_FREQUENCIES"
                                :key="option.key"
                                type="button"
                                class="flex-1 rounded-[10px] py-[9px] text-center text-[11.5px]"
                                :class="
                                    billForm.frequency === option.key
                                        ? 'hab-anim-pop'
                                        : ''
                                "
                                :style="
                                    billForm.frequency === option.key
                                        ? {
                                              fontWeight: 700,
                                              background:
                                                  'var(--hab-chip-fill)',
                                              border: '1.5px solid var(--hab-accent)',
                                              color: 'var(--hab-accent)',
                                          }
                                        : {
                                              fontWeight: 600,
                                              background: 'var(--hab-panel)',
                                              border: '1px solid var(--hab-card-border)',
                                              color: 'var(--hab-muted)',
                                          }
                                "
                                @click="billForm.frequency = option.key"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                    <div style="width: 104px">
                        <p
                            class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Due day
                        </p>
                        <input
                            v-model.number="billForm.due_day"
                            type="number"
                            min="1"
                            max="31"
                            placeholder="—"
                            class="w-full rounded-[12px] px-[14px] py-[9px] text-[18px] font-extrabold tabular-nums"
                            style="
                                background: var(--hab-panel);
                                border: 1px solid var(--hab-card-border);
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        />
                    </div>
                </div>

                <div class="mt-4">
                    <p
                        class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        Paid from
                    </p>
                    <div
                        class="flex items-center gap-[9px] rounded-[12px] px-[14px]"
                        style="
                            height: 44px;
                            background: var(--hab-panel);
                            border: 1px solid var(--hab-card-border);
                        "
                    >
                        <span
                            class="msf"
                            style="font-size: 18px; color: var(--hab-accent)"
                            >account_balance</span
                        >
                        <select
                            v-model.number="billForm.paid_from_account_id"
                            class="w-full min-w-0 flex-1 border-none bg-transparent p-0 text-[13.5px] outline-none"
                            style="color: var(--hab-ink)"
                        >
                            <option :value="null">Ask me each time</option>
                            <option
                                v-for="account in props.billPaymentAccounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ paymentAccountLabel(account) }}
                            </option>
                        </select>
                    </div>
                    <p
                        v-if="props.billPaymentAccounts.length === 0"
                        class="m-0 mt-2 text-[11.5px]"
                        style="color: var(--hab-muted)"
                    >
                        No accounts yet — add one in the
                        <a href="/ledger" class="font-semibold underline"
                            >Ledger</a
                        >
                        and this bill can come straight out of it.
                    </p>
                </div>

                <!-- Which month the cycle counts from -->
                <div v-if="billForm.frequency !== 'monthly'" class="mt-4">
                    <p
                        class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        First lands in
                    </p>
                    <select
                        v-model.number="billForm.anchor_month"
                        class="w-full rounded-[12px] px-[14px] py-[10px] text-[13.5px]"
                        style="
                            background: var(--hab-panel);
                            border: 1px solid var(--hab-card-border);
                            color: var(--hab-ink);
                        "
                    >
                        <option v-for="m in 12" :key="m" :value="m">
                            {{
                                new Date(2000, m - 1, 1).toLocaleDateString(
                                    'en-US',
                                    { month: 'long' },
                                )
                            }}
                        </option>
                    </select>
                </div>

                <button
                    type="button"
                    class="mt-4 flex w-full items-center justify-between rounded-[14px] px-[15px] py-[13px] text-left"
                    style="
                        background: var(--hab-panel);
                        border: 1px solid var(--hab-card-border);
                    "
                    @click="billForm.amount_varies = !billForm.amount_varies"
                >
                    <span>
                        <span
                            class="block text-[13px] font-semibold"
                            style="color: var(--hab-ink)"
                            >Amount varies</span
                        >
                        <span
                            class="mt-[2px] block text-[11.5px]"
                            style="color: var(--hab-muted)"
                            >Set the envelope from your last three months
                            instead of a fixed figure.</span
                        >
                    </span>
                    <span
                        class="flex flex-none items-center rounded-full p-[3px]"
                        style="width: 42px; height: 24px"
                        :style="{
                            background: billForm.amount_varies
                                ? 'var(--hab-accent)'
                                : 'var(--hab-leaf-soft)',
                            justifyContent: billForm.amount_varies
                                ? 'flex-end'
                                : 'flex-start',
                        }"
                    >
                        <span
                            class="rounded-full"
                            style="
                                width: 18px;
                                height: 18px;
                                background: var(--hab-card-bg);
                            "
                        ></span>
                    </span>
                </button>

                <!-- What it does to the month -->
                <div
                    class="mt-[18px] rounded-[16px] p-4"
                    style="
                        background: var(--hab-chip-fill);
                        border: 1px solid var(--hab-leaf-soft);
                    "
                >
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.1em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Bills envelope becomes
                            </p>
                            <p
                                class="m-0 mt-[6px] text-[26px] leading-none font-extrabold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                {{ formatMoney(billsEnvelopeAfter) }}
                            </p>
                        </div>
                        <p
                            v-if="billAmountAdded > 0"
                            class="hab-anim-pop m-0 rounded-full px-[10px] py-[5px] text-[11.5px] font-bold whitespace-nowrap"
                            style="
                                background: var(--hab-card-bg);
                                color: var(--hab-accent);
                            "
                        >
                            +{{ formatMoney(billAmountAdded) }}
                        </p>
                        <p
                            v-else
                            class="m-0 rounded-full px-[10px] py-[5px] text-[11.5px] font-semibold whitespace-nowrap"
                            style="
                                background: var(--hab-card-bg);
                                color: var(--hab-muted);
                            "
                        >
                            not this month
                        </p>
                    </div>

                    <div
                        class="mt-3 flex overflow-hidden rounded-full"
                        style="height: 10px; background: #dfe6d6"
                    >
                        <div
                            :style="{
                                width: `${billsExistingShare}%`,
                                background: 'var(--hab-accent)',
                            }"
                        ></div>
                        <div
                            class="hab-anim-grow-x flex-1"
                            style="background: #5c8a5f; --hab-delay: 0.12s"
                        ></div>
                    </div>

                    <p
                        class="m-0 mt-[9px] text-[11.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        <template v-if="billAmountAdded <= 0">
                            This one doesn't land in {{ props.monthName }} — the
                            envelope here is unchanged.
                        </template>
                        <template v-else-if="props.leftover >= billAmountAdded">
                            Still in your hand drops to
                            {{ formatMoney(handAfterBill) }}. It's covered.
                        </template>
                        <template v-else>
                            That's
                            {{ formatMoney(billAmountAdded - props.leftover) }}
                            more than you have unplaced — something else has to
                            give.
                        </template>
                    </p>
                </div>

                <!-- Where it sits in the month -->
                <p
                    class="m-0 mt-4 mb-[9px] text-[11.5px] font-semibold tracking-[.12em] uppercase"
                    style="color: var(--hab-faint)"
                >
                    Where it sits in the month
                </p>
                <div class="flex items-end gap-[3px]" style="height: 52px">
                    <div
                        v-for="day in monthShape"
                        :key="day.day"
                        class="flex-1 rounded-[2px]"
                        :class="day.tone === 'new' ? 'hab-anim-rise' : ''"
                        :title="
                            day.amount > 0
                                ? `${day.day} — ${day.label} · ${formatMoney(day.amount)}`
                                : `${day.day}`
                        "
                        :style="{
                            height: monthDayHeight(day),
                            background: MONTH_DAY_TONES[day.tone],
                            '--hab-delay': '0.2s',
                        }"
                    ></div>
                </div>
                <div
                    class="mt-2 flex justify-between text-[11px]"
                    style="color: var(--hab-faint)"
                >
                    <span>1</span>
                    <span v-for="entry in monthShapeLegend" :key="entry">{{
                        entry
                    }}</span>
                    <span>{{ daysInMonth }}</span>
                </div>

                <p
                    v-for="(error, field) in billForm.errors"
                    :key="field"
                    class="m-0 mt-2 text-[12px]"
                    style="color: var(--hab-clay)"
                >
                    {{ error }}
                </p>

                <div class="mt-5 flex gap-2">
                    <button
                        type="button"
                        class="h-11 rounded-[11px] text-[13px] font-semibold"
                        style="
                            width: 112px;
                            background: var(--hab-card-bg);
                            border: 1px solid var(--hab-card-border);
                            color: var(--hab-ink-secondary);
                        "
                        @click="cancelBillForm"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="hab-anim-breathe h-11 flex-1 rounded-[11px] text-[13.5px] font-bold disabled:opacity-50"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        :disabled="billForm.processing"
                        @click="submitBillForm"
                    >
                        {{
                            editingBillId === 'new'
                                ? 'Add to Bills'
                                : 'Save bill'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 13e · Mark bills paid ──────────────────────────────────── -->
        <div
            v-if="billsSheetOpen"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="billsSheetOpen = false"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 24px 48px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3
                            class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                            style="color: var(--hab-ink)"
                        >
                            Mark bills paid
                        </h3>
                        <p
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ props.monthName }} ·
                            {{ formatMoney(unpaidBillsTotal) }} still sealed
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="billsSheetOpen = false"
                    >
                        close
                    </button>
                </div>

                <div class="mt-4 flex flex-col">
                    <div
                        v-for="(bill, i) in unpaidBills"
                        :key="bill.id"
                        class="flex items-center gap-3 py-3"
                        :style="{
                            borderTop:
                                i > 0
                                    ? '1px solid var(--hab-hairline)'
                                    : 'none',
                        }"
                    >
                        <button
                            type="button"
                            class="flex h-6 w-6 flex-none items-center justify-center rounded-[7px]"
                            :style="{
                                background: billTicks[bill.id]
                                    ? 'var(--hab-accent)'
                                    : 'var(--hab-chip-fill)',
                                border: `1px solid ${billTicks[bill.id] ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                            }"
                            @click="billTicks[bill.id] = !billTicks[bill.id]"
                        >
                            <span
                                v-if="billTicks[bill.id]"
                                class="msf"
                                style="
                                    font-size: 15px;
                                    color: var(--hab-btn-dark-ink);
                                "
                                >check</span
                            >
                        </button>
                        <span
                            class="w-8 flex-none rounded-[8px] py-1 text-center text-[11px] font-bold"
                            style="
                                background: var(--hab-chip-fill);
                                color: var(--hab-muted);
                            "
                        >
                            {{
                                bill.due_day !== null
                                    ? String(bill.due_day).padStart(2, '0')
                                    : '—'
                            }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="m-0 truncate text-[13.5px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                {{ bill.name }}
                            </p>
                            <p
                                v-if="!billTicks[bill.id]"
                                class="m-0 mt-[1px] text-[11.5px]"
                                style="color: var(--hab-muted)"
                            >
                                planned {{ formatMoney(bill.expected_amount) }}
                            </p>
                            <select
                                v-else
                                v-model.number="billAccounts[bill.id]"
                                class="mt-[2px] w-full border-none bg-transparent p-0 text-[11.5px] outline-none"
                                style="color: var(--hab-accent)"
                                :aria-label="`Account ${bill.name} is paid from`"
                            >
                                <option
                                    v-for="account in props.billPaymentAccounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    from {{ account.name
                                    }}{{
                                        account.is_liability ? ' (card)' : ''
                                    }}
                                </option>
                            </select>
                        </div>
                        <MoneyInput
                            v-if="billTicks[bill.id]"
                            v-model="billAmounts[bill.id]"
                            class="w-[110px] rounded-[8px] border px-2 py-[6px] text-right text-[12.5px] font-semibold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                border-color: var(--hab-accent);
                                background: var(--hab-card-bg);
                                color: var(--hab-ink);
                            "
                        />
                        <span
                            v-else
                            class="text-[13px] font-semibold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-muted);
                            "
                        >
                            {{ formatMoney(bill.expected_amount) }}
                        </span>
                    </div>
                </div>

                <div
                    class="mt-4 rounded-[14px] p-4"
                    style="background: var(--hab-chip-fill)"
                >
                    <div
                        class="flex items-center justify-between text-[12.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        <span>Sealed for bills after this</span>
                        <span
                            class="font-semibold tabular-nums"
                            style="font-family: var(--hab-mono)"
                        >
                            {{ formatMoney(unpaidBillsTotal) }} →
                            {{
                                formatMoney(
                                    Math.max(0, unpaidBillsTotal - tickedTotal),
                                )
                            }}
                        </span>
                    </div>
                    <div
                        v-if="tickedBillsWithoutAccount.length > 0"
                        class="mt-3 flex items-center gap-3"
                    >
                        <span
                            class="msf"
                            style="font-size: 19px; color: var(--hab-accent)"
                            >account_balance</span
                        >
                        <select
                            v-model.number="billsAccountId"
                            class="w-full rounded-[8px] border px-2 py-[6px] text-[13px]"
                            style="
                                border-color: var(--hab-card-border);
                                background: var(--hab-card-bg);
                                color: var(--hab-ink);
                            "
                        >
                            <option
                                v-for="account in props.billPaymentAccounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ paymentAccountLabel(account) }}
                            </option>
                        </select>
                    </div>
                </div>

                <p
                    v-if="billsError"
                    class="m-0 mt-3 text-[12.5px]"
                    style="color: var(--hab-clay)"
                >
                    {{ billsError }}
                </p>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="billsSheetOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold disabled:opacity-50"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        :disabled="tickedBills.length === 0"
                        @click="submitBills"
                    >
                        Mark {{ tickedBills.length }} paid
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
