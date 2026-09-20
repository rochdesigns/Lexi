<script setup lang="ts">
import { Head, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    destroyAccount,
    index,
    storeAccount,
    destroyTransaction,
    previewDebtPayment,
    storeTransaction,
    updateTransaction,
    updateAccount,
} from '@/actions/App/Http/Controllers/LedgerController';
import MoneyInput from '@/components/MoneyInput.vue';

type AccountType = 'checking' | 'savings' | 'credit' | 'cash' | 'investment';
type EntryKind = 'income' | 'expense' | 'transfer' | 'debt' | 'bill';
type RangeKey = 'this_month' | 'last_3' | 'custom';

type LedgerAccount = {
    id: number;
    name: string;
    institution: string | null;
    type: AccountType;
    is_liability: boolean;
    balance: number;
    linked_debt_names: string[];
};

type ActivityEntry = {
    id: number;
    title: string;
    kind: EntryKind;
    kind_label: string;
    icon: string;
    type: 'income' | 'expense' | 'transfer';
    description: string | null;
    account_id: number | null;
    from_account_id: number | null;
    to_account_id: number | null;
    debt_id: number | null;
    envelope_id: number | null;
    envelope_name: string | null;
    occurred_at: string;
    occurred_on: string;
    amount: number;
    direction: 'in' | 'out';
};

type FlowSegment = { key: string; label: string; amount: number };

type LedgerFlow = {
    month_label: string;
    in: number;
    out: number;
    stayed: number;
    in_sources: { label: string; amount: number }[];
    out_segments: FlowSegment[];
    debt_share_of_income: number | null;
};

const props = defineProps<{
    accounts: LedgerAccount[];
    selectedAccountId: number | null;
    envelopes: { id: number; name: string; icon: string | null }[];
    debts: LedgerDebt[];
    totals: {
        assets: number;
        liabilities: number;
        netWorth: number;
        changeThisMonth: number | null;
    };
    netWorthHistory: { label: string; month: string; value: number }[];
    flow: LedgerFlow | null;
    totalFlow: LedgerFlow | null;
    activity: { entries: ActivityEntry[]; range_label: string };
    filters: { range: RangeKey; from: string; to: string };
}>();

// ── Formatting ──────────────────────────────────────────────────────────

function formatMoney(n: number): string {
    const formatted =
        '₱' +
        Math.abs(n).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

    return n < 0 ? `-${formatted}` : formatted;
}

function typeLabel(type: AccountType): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

const accountIcons: Record<AccountType, string> = {
    checking: 'account_balance',
    savings: 'savings',
    cash: 'wallet',
    investment: 'trending_up',
    credit: 'credit_card',
};

function accountIcon(account: LedgerAccount): string {
    return account.is_liability ? 'credit_card' : accountIcons[account.type];
}

/**
 * What a balance is worth to you: a liability's balance is money owed, so it
 * shows negative. A cleared card reads ₱0, never −₱0.
 */
function balanceForDisplay(account: LedgerAccount, balance: number): string {
    return formatMoney(account.is_liability ? -balance : balance);
}

// ── Net worth hero ──────────────────────────────────────────────────────

const selectedAccount = computed(
    () => props.accounts.find((a) => a.id === props.selectedAccountId) ?? null,
);
const assetAccounts = computed(() =>
    props.accounts.filter((a) => !a.is_liability),
);
const liabilityAccounts = computed(() =>
    props.accounts.filter((a) => a.is_liability),
);

/** The figure rolls up through its own history — at most four stops, ending at today. */
const rollStops = computed<number[]>(() => {
    const history = props.netWorthHistory;

    if (history.length < 2) {
        return [props.totals.netWorth];
    }

    const wanted = Math.min(4, history.length);
    const stops: number[] = [];

    for (let i = 0; i < wanted - 1; i++) {
        stops.push(
            history[Math.round((i * (history.length - 1)) / wanted)].value,
        );
    }

    stops.push(props.totals.netWorth);

    return stops;
});

const ROLL_LINE_HEIGHT = 45;
const rollDistance = computed(
    () => `-${(rollStops.value.length - 1) * ROLL_LINE_HEIGHT}px`,
);

function rollStopColor(index: number): string {
    const fromEnd = rollStops.value.length - 1 - index;

    return (
        [
            'var(--hab-ink)',
            'var(--hab-olive)',
            'var(--hab-faint)',
            'var(--hab-leaf-mid)',
        ][fromEnd] ?? 'var(--hab-leaf-mid)'
    );
}

/**
 * Zero-based while net worth is above water, as designed. Once it dips below,
 * the floor drops to the lowest point so the climb back stays readable rather
 * than flattening every bar against a zero that is off the top of the chart.
 */
const sparklineScale = computed(() => {
    const values = props.netWorthHistory.map((p) => p.value);

    if (values.length === 0) {
        return { zeroBased: true, floor: 0, range: 1 };
    }

    const lowest = Math.min(...values);
    const highest = Math.max(...values);
    const zeroBased = lowest >= 0;
    const floor = zeroBased ? 0 : lowest;
    const ceiling = zeroBased ? Math.max(highest, 0) : highest;

    return { zeroBased, floor, range: Math.max(ceiling - floor, 1) };
});

function sparkHeight(value: number): string {
    const { zeroBased, floor, range } = sparklineScale.value;
    const ratio = (value - floor) / range;

    // Range-scaled bars keep a plinth so the lowest month still reads as a bar.
    return `${Math.max(4, zeroBased ? ratio * 100 : 15 + ratio * 85)}%`;
}

function sparkColor(index: number): string {
    const fromEnd = props.netWorthHistory.length - 1 - index;

    if (fromEnd < 2) {
        return 'var(--hab-apricot)';
    }

    return fromEnd < 4 ? 'var(--hab-leaf-mid)' : 'var(--hab-leaf-soft)';
}

const sparklineCaption = computed(() => {
    const history = props.netWorthHistory;

    if (history.length === 0) {
        return '';
    }

    const baseline = sparklineScale.value.zeroBased
        ? 'zero-based'
        : 'scaled to your range';

    return `Net worth, ${history[0].label} – ${history[history.length - 1].label} · ${baseline} · hover a bar for the figure`;
});

// ── Flow card ───────────────────────────────────────────────────────────

type FlowScope = 'all' | 'account';

/**
 * The card opens on the whole picture, because "did I keep anything this
 * month" is a question about all your money at once. Picking an account off
 * the rail is a request to see that account, so it narrows the card too.
 */
const flowScope = ref<FlowScope>(props.totalFlow !== null ? 'all' : 'account');

watch(
    () => props.selectedAccountId,
    () => {
        flowScope.value = 'account';
    },
);

const activeFlow = computed<LedgerFlow | null>(() =>
    flowScope.value === 'all' ? props.totalFlow : props.flow,
);

const flowScopeOptions = computed<{ value: FlowScope; label: string }[]>(() => [
    { value: 'all', label: 'All accounts' },
    { value: 'account', label: selectedAccount.value?.name ?? 'This account' },
]);

const flowScopeLabel = computed(
    () =>
        flowScopeOptions.value.find((o) => o.value === flowScope.value)
            ?.label ?? '',
);

const segmentColors: Record<string, { fill: string; ink: string }> = {
    bills: { fill: 'var(--hab-olive)', ink: 'var(--hab-on-accent)' },
    debt: { fill: 'var(--hab-apricot)', ink: 'var(--hab-ink)' },
    living: { fill: 'var(--hab-sage)', ink: 'var(--hab-on-accent)' },
    saved: { fill: 'var(--hab-accent)', ink: 'var(--hab-on-accent)' },
};

/** The out bar is drawn to scale against income, so "what stayed" is the gap. */
const outBarWidth = computed(() => {
    const flow = activeFlow.value;

    if (flow === null || flow.in <= 0) {
        return '100%';
    }

    return `${Math.min(100, (flow.out / flow.in) * 100)}%`;
});

function segmentWidth(segment: FlowSegment): string {
    const flow = activeFlow.value;

    if (flow === null || flow.out <= 0) {
        return '0%';
    }

    return `${(segment.amount / flow.out) * 100}%`;
}

const debtSegment = computed(
    () => activeFlow.value?.out_segments.find((s) => s.key === 'debt') ?? null,
);

// ── Activity feed + filters ─────────────────────────────────────────────

const filterOpen = ref(false);
const showAll = ref(false);

const kindFilters: { key: EntryKind; label: string }[] = [
    { key: 'expense', label: 'Expenses' },
    { key: 'income', label: 'Income' },
    { key: 'transfer', label: 'Transfers' },
    { key: 'bill', label: 'Bills' },
    { key: 'debt', label: 'Debt' },
];

const activeKinds = ref<EntryKind[]>([]);
const activeEnvelopeIds = ref<number[]>([]);
const minAmount = ref(0);

const amountCeiling = computed(() => {
    const highest = Math.max(...props.activity.entries.map((e) => e.amount), 0);

    return Math.max(1000, Math.ceil(highest / 500) * 500);
});

const usedEnvelopes = computed(() => {
    const used = new Set(
        props.activity.entries
            .map((e) => e.envelope_id)
            .filter((id): id is number => id !== null),
    );

    return props.envelopes.filter((envelope) => used.has(envelope.id));
});

const filteredEntries = computed(() =>
    props.activity.entries.filter((entry) => {
        if (
            activeKinds.value.length > 0 &&
            !activeKinds.value.includes(entry.kind)
        ) {
            return false;
        }

        if (
            activeEnvelopeIds.value.length > 0 &&
            (entry.envelope_id === null ||
                !activeEnvelopeIds.value.includes(entry.envelope_id))
        ) {
            return false;
        }

        return entry.amount >= minAmount.value;
    }),
);

const filteredTotal = computed(() =>
    filteredEntries.value.reduce((sum, entry) => sum + entry.amount, 0),
);
const visibleEntries = computed(() =>
    showAll.value ? filteredEntries.value : filteredEntries.value.slice(0, 7),
);
const activeFilterCount = computed(
    () =>
        activeKinds.value.length +
        activeEnvelopeIds.value.length +
        (minAmount.value > 0 ? 1 : 0) +
        (props.filters.range === 'this_month' ? 0 : 1),
);

function toggleKind(kind: EntryKind): void {
    activeKinds.value = activeKinds.value.includes(kind)
        ? activeKinds.value.filter((k) => k !== kind)
        : [...activeKinds.value, kind];
    showAll.value = false;
}

function toggleEnvelope(id: number): void {
    activeEnvelopeIds.value = activeEnvelopeIds.value.includes(id)
        ? activeEnvelopeIds.value.filter((e) => e !== id)
        : [...activeEnvelopeIds.value, id];
    showAll.value = false;
}

function resetFilters(): void {
    activeKinds.value = [];
    activeEnvelopeIds.value = [];
    minAmount.value = 0;
    showAll.value = false;

    if (props.filters.range !== 'this_month') {
        applyRange('this_month');
    }
}

const rangeLabels: Record<RangeKey, string> = {
    this_month: 'This month',
    last_3: 'Last 3 mo',
    custom: 'Custom',
};

const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);

function reload(params: Record<string, string | number>): void {
    router.get(index.url(), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['flow', 'activity', 'filters', 'selectedAccountId'],
    });
}

function applyRange(range: RangeKey): void {
    showAll.value = false;

    if (range === 'custom') {
        reload({
            account: props.selectedAccountId ?? '',
            range,
            from: customFrom.value,
            to: customTo.value,
        });

        return;
    }

    reload({ account: props.selectedAccountId ?? '', range });
}

function selectAccount(account: LedgerAccount): void {
    if (account.id === props.selectedAccountId) {
        return;
    }

    showAll.value = false;
    filterOpen.value = false;

    const params: Record<string, string | number> = {
        account: account.id,
        range: props.filters.range,
    };

    if (props.filters.range === 'custom') {
        params.from = props.filters.from;
        params.to = props.filters.to;
    }

    reload(params);
}

// ── Account sheet ───────────────────────────────────────────────────────

const accountSheet = ref<'closed' | 'new' | number>('closed');
const accountForm = useForm({
    name: '',
    institution: null as string | null,
    type: 'checking' as AccountType,
    is_liability: false,
    balance: 0,
});

const accountTypeOptions: {
    value: AccountType;
    label: string;
    icon: string;
}[] = [
    { value: 'checking', label: 'Checking', icon: 'account_balance' },
    { value: 'savings', label: 'Savings', icon: 'savings' },
    { value: 'cash', label: 'Cash', icon: 'wallet' },
    { value: 'investment', label: 'Investment', icon: 'trending_up' },
    { value: 'credit', label: 'Credit', icon: 'credit_card' },
];

function openAccountSheet(account?: LedgerAccount): void {
    accountSheet.value = account?.id ?? 'new';
    accountForm.clearErrors();
    accountForm.name = account?.name ?? '';
    accountForm.institution = account?.institution ?? null;
    accountForm.type = account?.type ?? 'checking';
    accountForm.is_liability = account?.is_liability ?? false;
    accountForm.balance = account?.balance ?? 0;
}

function pickAccountType(type: AccountType): void {
    accountForm.type = type;
    accountForm.is_liability = type === 'credit';
}

function submitAccount(): void {
    const onSuccess = () => (accountSheet.value = 'closed');

    if (accountSheet.value === 'new') {
        accountForm.post(storeAccount().url, {
            preserveScroll: true,
            onSuccess,
        });
    } else if (accountSheet.value !== 'closed') {
        accountForm.patch(updateAccount(accountSheet.value).url, {
            preserveScroll: true,
            onSuccess,
        });
    }
}

function removeAccount(account: LedgerAccount): void {
    let message = `Delete ${account.name}? Transfers with your other accounts will be kept, showing this account as "(deleted account)".`;

    if (account.linked_debt_names.length > 0) {
        message += ` This will also permanently delete the debt "${account.linked_debt_names.join('", "')}" linked to this account.`;
    }

    if (!window.confirm(message)) {
        return;
    }

    router.delete(destroyAccount(account.id).url, { preserveScroll: true });
}

// ── New entry sheet ─────────────────────────────────────────────────────

type EntryTab = 'expense' | 'income' | 'transfer' | 'debt';

type LedgerDebt = {
    id: number;
    name: string;
    balance: number;
    monthly_rate: number | null;
    minimum_payment: number;
    kind: string | null;
    debt_type: 'revolving' | 'installment';
    due_day: number | null;
    linked_account_id: number | null;
    is_target: boolean;
    planned_payment: number;
};

type DebtPaymentPreview = {
    interest: number;
    principal: number;
    coversInterest: boolean;
    balanceBefore: number;
    balanceAfter: number;
    debtFreeBefore: string | null;
    debtFreeAfter: string | null;
    daysEarlier: number | null;
    interestSaved: number;
};

const entrySheetOpen = ref(false);
const entryForm = useForm({
    type: 'expense' as EntryTab,
    account_id: null as number | null,
    from_account_id: null as number | null,
    to_account_id: null as number | null,
    envelope_id: null as number | null,
    debt_id: null as number | null,
    amount: 0,
    description: null as string | null,
    occurred_at: new Date().toLocaleDateString('en-CA'),
});

const entryTabs: { key: EntryTab; label: string }[] = [
    { key: 'expense', label: 'Expense' },
    { key: 'income', label: 'Income' },
    { key: 'transfer', label: 'Transfer' },
    { key: 'debt', label: 'Debt' },
];

const entryTone = computed(() => {
    if (entryForm.type === 'income') {
        return 'income';
    }

    return entryForm.type === 'debt' ? 'debt' : 'neutral';
});

const amountLabel = computed(
    () =>
        ({
            expense: 'Amount out',
            income: 'Amount in',
            transfer: 'Amount moved',
            debt: 'Payment',
        })[entryForm.type],
);

const entrySubmitLabel = computed(
    () =>
        ({
            expense: 'Save entry',
            income: 'Save entry',
            transfer: 'Move money',
            debt: 'Log payment',
        })[entryForm.type],
);

const quickAmounts = computed(() =>
    entryForm.type === 'transfer' ? [1000, 5000] : [100, 500, 1000],
);

const transferFrom = computed(
    () =>
        props.accounts.find((a) => a.id === entryForm.from_account_id) ?? null,
);
const transferTo = computed(
    () => props.accounts.find((a) => a.id === entryForm.to_account_id) ?? null,
);

function projectedBalance(
    account: LedgerAccount,
    direction: 'in' | 'out',
): number {
    const signed = direction === 'in' ? entryForm.amount : -entryForm.amount;

    return account.balance + (account.is_liability ? -signed : signed);
}

function openEntrySheet(): void {
    entrySheetOpen.value = true;
    entryForm.clearErrors();
    entryForm.type = 'expense';
    entryForm.account_id =
        props.selectedAccountId ?? props.accounts[0]?.id ?? null;
    entryForm.from_account_id =
        props.selectedAccountId ?? props.accounts[0]?.id ?? null;
    entryForm.to_account_id =
        props.accounts.find((a) => a.id !== entryForm.from_account_id)?.id ??
        null;
    entryForm.envelope_id = null;
    entryForm.debt_id = null;
    entryForm.amount = 0;
    entryForm.description = null;
    entryForm.occurred_at = new Date().toLocaleDateString('en-CA');
}

function pickEntryTab(tab: EntryTab): void {
    entryForm.type = tab;
    entryForm.clearErrors();

    if (tab === 'debt') {
        // A debt payment leaves an asset account and lands on the debt, so it
        // borrows the transfer's "from" field and needs no envelope.
        entryForm.account_id = null;
        entryForm.to_account_id = null;
        entryForm.envelope_id = null;
        entryForm.from_account_id ??= assetAccounts.value[0]?.id ?? null;
        entryForm.debt_id ??=
            targetDebt.value?.id ?? props.debts[0]?.id ?? null;
        applyPlannedPayment();

        return;
    }

    entryForm.debt_id = null;
    debtPreview.value = null;

    if (tab === 'transfer') {
        entryForm.account_id = null;
        entryForm.envelope_id = null;
        entryForm.from_account_id ??= props.accounts[0]?.id ?? null;
        entryForm.to_account_id ??=
            props.accounts.find((a) => a.id !== entryForm.from_account_id)
                ?.id ?? null;

        return;
    }

    entryForm.account_id ??= props.accounts[0]?.id ?? null;
    entryForm.from_account_id = null;
    entryForm.to_account_id = null;

    if (tab !== 'expense') {
        entryForm.envelope_id = null;
    }
}

/**
 * The entry sheet takes the colour of what is being logged: leaf for money in,
 * apricot for money going at a debt, ink for everything else.
 */
const amountHeroStyle = computed(() => {
    if (entryTone.value === 'income') {
        return { background: '#eef5e9', border: '1px solid #cfdfc4' };
    }

    if (entryTone.value === 'debt') {
        return {
            background: 'var(--hab-apricot-tint)',
            border: '1px solid var(--hab-apricot-border)',
        };
    }

    return {
        background: 'var(--hab-panel)',
        border: '1px solid var(--hab-card-border)',
    };
});

const amountLabelColor = computed(() => {
    if (entryTone.value === 'income') {
        return '#6f8a6a';
    }

    return entryTone.value === 'debt' ? '#a58a72' : 'var(--hab-faint)';
});

const amountTextColor = computed(() => {
    if (entryTone.value === 'income') {
        return 'var(--hab-accent)';
    }

    return entryTone.value === 'debt'
        ? 'var(--hab-apricot-ink)'
        : 'var(--hab-ink)';
});

const amountRuleColor = computed(() =>
    entryTone.value === 'debt' ? 'var(--hab-apricot)' : 'var(--hab-accent)',
);

function activeTabColor(tab: EntryTab): string {
    if (tab === 'income') {
        return 'var(--hab-accent)';
    }

    return tab === 'debt' ? 'var(--hab-apricot-ink)' : 'var(--hab-ink)';
}

// ── Editing an entry ─────────────────────────────────────────────────────

const editingEntry = ref<ActivityEntry | null>(null);
const editEntryError = ref<string | null>(null);
const editForm = useForm({
    account_id: null as number | null,
    from_account_id: null as number | null,
    to_account_id: null as number | null,
    envelope_id: null as number | null,
    amount: 0,
    description: null as string | null,
    occurred_at: '',
});

function openEditEntry(entry: ActivityEntry): void {
    editingEntry.value = entry;
    editEntryError.value = null;
    editForm.clearErrors();
    editForm.account_id = entry.account_id;
    editForm.from_account_id = entry.from_account_id;
    editForm.to_account_id = entry.to_account_id;
    editForm.envelope_id = entry.envelope_id;
    editForm.amount = entry.amount;
    editForm.description = entry.description;
    editForm.occurred_at = entry.occurred_on;
}

function closeEditEntry(): void {
    editingEntry.value = null;
    editEntryError.value = null;
}

/** What this entry is, in the words the sheet should use. */
const editEntryLabel = computed(() => {
    const entry = editingEntry.value;

    if (!entry) {
        return '';
    }

    if (entry.debt_id !== null) {
        return 'debt payment';
    }

    return entry.type;
});

function submitEditEntry(): void {
    const entry = editingEntry.value;

    if (!entry) {
        return;
    }

    editForm
        .transform((data) => ({
            ...data,
            // The server decides these by the entry's own type; sending the
            // fields that do not apply would only invite validation noise.
            account_id: entry.type === 'transfer' ? null : data.account_id,
            from_account_id:
                entry.type === 'transfer' ? data.from_account_id : null,
            to_account_id:
                entry.type === 'transfer' ? data.to_account_id : null,
            envelope_id: entry.type === 'expense' ? data.envelope_id : null,
        }))
        .patch(updateTransaction(entry.id).url, {
            preserveScroll: true,
            onSuccess: () => closeEditEntry(),
            onError: (errors) =>
                (editEntryError.value =
                    Object.values(errors)[0] ??
                    'That entry could not be saved.'),
        });
}

function deleteEditEntry(): void {
    const entry = editingEntry.value;

    if (!entry) {
        return;
    }

    if (
        !window.confirm(
            `Delete this ${editEntryLabel.value}? Every balance it moved goes back to where it was.`,
        )
    ) {
        return;
    }

    router.delete(destroyTransaction(entry.id).url, {
        preserveScroll: true,
        onSuccess: () => closeEditEntry(),
    });
}

// ── 10d · The Debt tab ───────────────────────────────────────────────────

const DEBT_PREVIEW_DEBOUNCE_MS = 350;

const targetDebt = computed(
    () => props.debts.find((debt) => debt.is_target) ?? null,
);

const selectedDebt = computed(
    () => props.debts.find((debt) => debt.id === entryForm.debt_id) ?? null,
);

const debtPreview = ref<DebtPaymentPreview | null>(null);
const debtPreviewHttp = useHttp({ debt_id: 0, amount: 0 });

/** The three amounts worth offering: what is owed, what is planned, and all of it. */
const debtAmountChips = computed(() => {
    const debt = selectedDebt.value;

    if (!debt) {
        return [];
    }

    const chips = [
        {
            key: 'minimum',
            label: `Minimum ${formatMoney(debt.minimum_payment)}`,
            amount: debt.minimum_payment,
        },
    ];

    if (debt.planned_payment > debt.minimum_payment) {
        chips.push({
            key: 'planned',
            label: `Planned ${formatMoney(debt.planned_payment)}`,
            amount: debt.planned_payment,
        });
    }

    chips.push({ key: 'payoff', label: 'Payoff', amount: debt.balance });

    return chips;
});

function applyPlannedPayment(): void {
    const debt = selectedDebt.value;

    if (debt && entryForm.amount <= 0) {
        entryForm.amount = debt.planned_payment;
    }
}

/** Share of the payment that actually comes off the balance. */
const principalShare = computed(() => {
    const preview = debtPreview.value;

    if (!preview || entryForm.amount <= 0 || !preview.coversInterest) {
        return 0;
    }

    return Math.min(100, (preview.principal / entryForm.amount) * 100);
});

let debtPreviewTimer: ReturnType<typeof setTimeout> | undefined;

function refreshDebtPreview(): void {
    clearTimeout(debtPreviewTimer);

    if (
        entryForm.type !== 'debt' ||
        !entryForm.debt_id ||
        entryForm.amount <= 0
    ) {
        debtPreview.value = null;

        return;
    }

    debtPreviewTimer = setTimeout(() => {
        debtPreviewHttp.debt_id = entryForm.debt_id as number;
        debtPreviewHttp.amount = entryForm.amount;

        debtPreviewHttp.post(previewDebtPayment().url, {
            onSuccess: (response: unknown) => {
                debtPreview.value = response as DebtPaymentPreview;
            },
            onError: () => {
                debtPreview.value = null;
            },
        });
    }, DEBT_PREVIEW_DEBOUNCE_MS);
}

watch(
    () => [entryForm.type, entryForm.debt_id, entryForm.amount],
    () => refreshDebtPreview(),
);

// Picking a different debt re-suggests what the plan means to send at it, but
// only when nothing has been typed over the suggestion yet.
watch(
    () => entryForm.debt_id,
    () => applyPlannedPayment(),
);

function swapTransferAccounts(): void {
    const from = entryForm.from_account_id;
    entryForm.from_account_id = entryForm.to_account_id;
    entryForm.to_account_id = from;
}

function addQuickAmount(amount: number): void {
    entryForm.amount = Math.round((entryForm.amount + amount) * 100) / 100;
}

function submitEntry(): void {
    entryForm.post(storeTransaction().url, {
        preserveScroll: true,
        onSuccess: () => (entrySheetOpen.value = false),
    });
}

const entryErrors = computed(
    () => Object.values(entryForm.errors).filter(Boolean) as string[],
);
const accountErrors = computed(
    () => Object.values(accountForm.errors).filter(Boolean) as string[],
);
</script>

<template>
    <Head title="Ledger" />

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
        <!-- Net worth hero -->
        <div
            class="flex flex-col gap-5 rounded-[22px] p-[22px] sm:flex-row sm:items-end sm:justify-between sm:px-[26px]"
            style="
                background: var(--hab-panel);
                border: 1px solid var(--hab-card-border);
            "
        >
            <div>
                <p
                    class="m-0 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                    style="color: var(--hab-faint)"
                >
                    Net worth
                </p>

                <div class="mt-[5px] overflow-hidden" style="height: 45px">
                    <div
                        class="hab-anim-roll-up"
                        :style="{ '--hab-roll-distance': rollDistance }"
                    >
                        <p
                            v-for="(stop, i) in rollStops"
                            :key="i"
                            class="m-0 text-[32px] font-extrabold tracking-[-.03em] tabular-nums sm:text-[40px]"
                            style="
                                height: 45px;
                                line-height: 45px;
                                font-family: var(--hab-mono);
                            "
                            :style="{ color: rollStopColor(i) }"
                        >
                            {{ formatMoney(stop) }}
                        </p>
                    </div>
                </div>

                <p
                    class="hab-anim-row-in m-0 mt-[6px] text-[12.5px]"
                    style="--hab-delay: 1.3s; color: var(--hab-muted)"
                >
                    {{ formatMoney(props.totals.assets) }} assets ·
                    {{ formatMoney(props.totals.liabilities) }} owed<span
                        v-if="
                            props.totals.changeThisMonth !== null &&
                            props.totals.changeThisMonth !== 0
                        "
                    >
                        ·
                        <span
                            class="font-semibold"
                            style="color: var(--hab-apricot-ink)"
                        >
                            {{
                                props.totals.changeThisMonth > 0 ? 'up' : 'down'
                            }}
                            {{
                                formatMoney(
                                    Math.abs(props.totals.changeThisMonth),
                                )
                            }}
                            this month
                        </span>
                    </span>
                </p>
            </div>

            <div
                v-if="props.netWorthHistory.length > 0"
                class="flex flex-col items-start gap-[6px] sm:items-end"
            >
                <div class="flex items-end gap-[4px]" style="height: 56px">
                    <div
                        v-for="(point, i) in props.netWorthHistory"
                        :key="point.month"
                        class="hab-anim-spring-bar relative w-[14px] rounded-t-[2px]"
                        :title="`${point.label} — ${formatMoney(point.value)}`"
                        :style="{
                            height: sparkHeight(point.value),
                            background: sparkColor(i),
                            '--hab-delay': `${i * 0.06}s`,
                        }"
                    >
                        <span
                            v-if="i === props.netWorthHistory.length - 1"
                            class="hab-anim-pulse-dot absolute inset-0 rounded-t-[2px]"
                            style="background: var(--hab-apricot-lit)"
                        ></span>
                    </div>
                </div>
                <p class="m-0 text-[10.5px]" style="color: var(--hab-faint)">
                    {{ sparklineCaption }}
                </p>
            </div>
        </div>

        <div class="mt-5 grid items-start gap-5 lg:grid-cols-[308px_1fr]">
            <!-- Account rail -->
            <div
                class="rounded-[22px] p-4"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <div class="flex items-center justify-between px-[6px] pb-3">
                    <p
                        class="m-0 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        Accounts
                    </p>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 18px; color: var(--hab-faint)"
                        @click="openAccountSheet()"
                    >
                        add
                    </button>
                </div>

                <p
                    v-if="props.accounts.length === 0"
                    class="px-[6px] text-[12.5px]"
                    style="color: var(--hab-muted)"
                >
                    Add an account to start tracking your balances.
                </p>

                <div class="flex flex-col gap-1">
                    <template
                        v-for="(account, i) in assetAccounts"
                        :key="account.id"
                    >
                        <button
                            type="button"
                            class="hab-anim-row-in group relative flex items-center gap-3 overflow-hidden rounded-[14px] p-3 text-left transition-transform duration-150 hover:translate-x-[3px]"
                            :style="{
                                '--hab-delay': `${0.1 + i * 0.06}s`,
                                background:
                                    account.id === props.selectedAccountId
                                        ? 'var(--hab-chip-fill)'
                                        : 'transparent',
                                border:
                                    account.id === props.selectedAccountId
                                        ? '1px solid var(--hab-leaf-soft)'
                                        : '1px solid transparent',
                            }"
                            @click="selectAccount(account)"
                        >
                            <span
                                v-if="account.id === props.selectedAccountId"
                                class="hab-anim-seam pointer-events-none absolute inset-y-0 left-0 w-[26%]"
                                style="
                                    background: linear-gradient(
                                        100deg,
                                        transparent,
                                        rgba(201, 124, 58, 0.22),
                                        transparent
                                    );
                                "
                            ></span>
                            <span
                                class="msf relative"
                                style="font-size: 18px"
                                :style="{
                                    color:
                                        account.id === props.selectedAccountId
                                            ? 'var(--hab-apricot)'
                                            : 'var(--hab-accent)',
                                }"
                            >
                                {{ accountIcon(account) }}
                            </span>
                            <span class="relative min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13.5px] font-semibold"
                                    style="color: var(--hab-ink)"
                                    >{{ account.name }}</span
                                >
                                <span
                                    class="block truncate text-[11.5px]"
                                    style="color: var(--hab-muted)"
                                >
                                    <template v-if="account.institution"
                                        >{{ account.institution }} · </template
                                    >{{ typeLabel(account.type) }}
                                </span>
                            </span>
                            <span
                                class="relative text-[14px] font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                {{
                                    balanceForDisplay(account, account.balance)
                                }}
                            </span>
                        </button>
                        <div
                            v-if="account.id === props.selectedAccountId"
                            class="flex items-center justify-end gap-3 px-3 pb-1"
                        >
                            <button
                                type="button"
                                class="flex items-center gap-1 text-[11.5px] font-semibold"
                                style="color: var(--hab-muted)"
                                @click="openAccountSheet(account)"
                            >
                                <span
                                    class="material-symbols-rounded"
                                    style="font-size: 15px"
                                    >edit</span
                                >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1 text-[11.5px] font-semibold"
                                style="color: var(--hab-muted)"
                                @click="removeAccount(account)"
                            >
                                <span
                                    class="material-symbols-rounded"
                                    style="font-size: 15px"
                                    >delete</span
                                >
                                Delete
                            </button>
                        </div>
                    </template>

                    <div
                        v-if="
                            liabilityAccounts.length > 0 &&
                            assetAccounts.length > 0
                        "
                        class="my-[6px] h-px"
                        style="background: var(--hab-hairline)"
                    ></div>

                    <template
                        v-for="(account, i) in liabilityAccounts"
                        :key="account.id"
                    >
                        <button
                            type="button"
                            class="hab-anim-row-in relative flex items-center gap-3 overflow-hidden rounded-[14px] p-3 text-left transition-transform duration-150 hover:translate-x-[3px]"
                            :style="{
                                '--hab-delay': `${0.1 + (assetAccounts.length + i) * 0.06}s`,
                                background: 'var(--hab-clay-tint)',
                                border:
                                    account.id === props.selectedAccountId
                                        ? '1px solid var(--hab-clay-border)'
                                        : '1px solid transparent',
                            }"
                            @click="selectAccount(account)"
                        >
                            <span
                                v-if="account.id === props.selectedAccountId"
                                class="hab-anim-seam pointer-events-none absolute inset-y-0 left-0 w-[26%]"
                                style="
                                    background: linear-gradient(
                                        100deg,
                                        transparent,
                                        rgba(140, 69, 48, 0.18),
                                        transparent
                                    );
                                "
                            ></span>
                            <span
                                class="msf relative"
                                style="font-size: 18px; color: var(--hab-clay)"
                                >credit_card</span
                            >
                            <span class="relative min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13.5px] font-semibold"
                                    style="color: var(--hab-clay)"
                                    >{{ account.name }}</span
                                >
                                <span
                                    class="block truncate text-[11.5px]"
                                    style="color: var(--hab-clay-ink)"
                                >
                                    owed<template
                                        v-if="
                                            account.linked_debt_names.length > 0
                                        "
                                    >
                                        · linked to Payoff Plan</template
                                    >
                                </span>
                            </span>
                            <span
                                class="relative text-[14px] font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-clay);
                                "
                            >
                                {{
                                    balanceForDisplay(account, account.balance)
                                }}
                            </span>
                        </button>
                        <div
                            v-if="account.id === props.selectedAccountId"
                            class="flex items-center justify-end gap-3 px-3 pb-1"
                        >
                            <button
                                type="button"
                                class="flex items-center gap-1 text-[11.5px] font-semibold"
                                style="color: var(--hab-muted)"
                                @click="openAccountSheet(account)"
                            >
                                <span
                                    class="material-symbols-rounded"
                                    style="font-size: 15px"
                                    >edit</span
                                >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1 text-[11.5px] font-semibold"
                                style="color: var(--hab-muted)"
                                @click="removeAccount(account)"
                            >
                                <span
                                    class="material-symbols-rounded"
                                    style="font-size: 15px"
                                    >delete</span
                                >
                                Delete
                            </button>
                        </div>
                    </template>
                </div>

                <button
                    type="button"
                    class="mt-[14px] w-full rounded-[12px] py-[10px] text-[12.5px] font-semibold transition-colors duration-150"
                    style="
                        border: 1px dashed var(--hab-leaf-soft);
                        color: var(--hab-muted);
                    "
                    @click="openAccountSheet()"
                >
                    Add an account
                </button>
            </div>

            <!-- Flow + activity -->
            <div
                class="rounded-[26px] p-5 sm:px-7 sm:py-[26px]"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
                "
            >
                <template v-if="activeFlow !== null">
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <p
                                class="m-0 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                {{ activeFlow.month_label }} flow ·
                                {{ flowScopeLabel }}
                            </p>
                            <h2
                                class="hab-anim-pop m-0 mt-[5px] font-['Bricolage_Grotesque'] text-[26px] font-extrabold tracking-[-.02em] sm:text-[34px]"
                                style="--hab-delay: 0.2s; color: var(--hab-ink)"
                            >
                                <span
                                    class="tabular-nums"
                                    style="font-family: var(--hab-mono)"
                                    >{{ formatMoney(activeFlow.stayed) }}</span
                                >
                                <span
                                    class="text-[16px] font-medium"
                                    style="color: var(--hab-muted)"
                                >
                                    {{
                                        activeFlow.stayed < 0
                                            ? ' over'
                                            : ' stayed'
                                    }}
                                </span>
                            </h2>
                        </div>
                        <div class="flex flex-col gap-2 sm:items-end">
                            <div
                                v-if="
                                    props.totalFlow !== null &&
                                    props.flow !== null &&
                                    selectedAccount !== null
                                "
                                class="flex gap-[2px] self-start rounded-[11px] p-[3px] sm:self-auto"
                                style="background: var(--hab-chip-fill)"
                            >
                                <button
                                    v-for="option in flowScopeOptions"
                                    :key="option.value"
                                    type="button"
                                    class="max-w-[140px] truncate rounded-[9px] px-[11px] py-[5px] text-[11.5px] font-semibold transition-colors duration-150"
                                    :style="{
                                        background:
                                            flowScope === option.value
                                                ? 'var(--hab-card-bg)'
                                                : 'transparent',
                                        color:
                                            flowScope === option.value
                                                ? 'var(--hab-ink)'
                                                : 'var(--hab-muted)',
                                    }"
                                    @click="flowScope = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                            <p
                                class="m-0 text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                {{ formatMoney(activeFlow.in) }} in ·
                                {{ formatMoney(activeFlow.out) }} out
                            </p>
                        </div>
                    </div>

                    <div class="mt-6">
                        <div class="mb-[6px] flex items-center gap-[10px]">
                            <span
                                class="w-[44px] shrink-0 text-[11px] font-semibold"
                                style="color: var(--hab-accent)"
                                >IN</span
                            >
                            <div
                                class="flex h-[34px] flex-1 gap-[2px] overflow-hidden rounded-[8px]"
                            >
                                <div
                                    v-for="(source, i) in activeFlow.in_sources"
                                    :key="source.label"
                                    class="hab-anim-grow-x relative flex items-center gap-[10px] overflow-hidden px-3"
                                    :style="{
                                        width: `${(source.amount / activeFlow.in) * 100}%`,
                                        background:
                                            i === 0
                                                ? 'linear-gradient(90deg, var(--hab-accent), #4d7a56)'
                                                : 'var(--hab-sage)',
                                        '--hab-delay': `${0.1 + i * 0.08}s`,
                                    }"
                                >
                                    <span
                                        v-if="i === 0"
                                        class="hab-anim-coin-drop h-3 w-3 shrink-0 rounded-full"
                                        style="
                                            background: var(--hab-apricot-lit);
                                            box-shadow: inset 0 -2px 0
                                                rgba(0, 0, 0, 0.15);
                                        "
                                    ></span>
                                    <span
                                        class="truncate text-[12px] font-semibold"
                                        style="color: var(--hab-on-accent)"
                                    >
                                        {{ source.label }}
                                        {{ formatMoney(source.amount) }}
                                    </span>
                                </div>
                                <div
                                    v-if="activeFlow.in_sources.length === 0"
                                    class="flex w-full items-center px-3 text-[11.5px]"
                                    style="
                                        background: var(--hab-progress-track);
                                        color: var(--hab-muted);
                                    "
                                >
                                    Nothing came in this month
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-[10px]">
                            <span
                                class="w-[44px] shrink-0 text-[11px] font-semibold"
                                style="color: var(--hab-clay)"
                                >OUT</span
                            >
                            <div class="flex-1">
                                <div
                                    class="flex h-[34px] gap-[2px] overflow-hidden rounded-[8px]"
                                    :style="{ width: outBarWidth }"
                                >
                                    <div
                                        v-for="(
                                            segment, i
                                        ) in activeFlow.out_segments"
                                        :key="segment.key"
                                        class="hab-anim-grow-x relative flex items-center overflow-hidden px-[10px]"
                                        :style="{
                                            width: segmentWidth(segment),
                                            background:
                                                segmentColors[segment.key].fill,
                                            '--hab-delay': `${0.22 + i * 0.12}s`,
                                        }"
                                    >
                                        <span
                                            v-if="segment.key === 'saved'"
                                            class="hab-anim-seam pointer-events-none absolute inset-y-0 left-0 w-[40%]"
                                            style="
                                                background: linear-gradient(
                                                    100deg,
                                                    transparent,
                                                    rgba(255, 255, 255, 0.28),
                                                    transparent
                                                );
                                            "
                                        ></span>
                                        <span
                                            class="relative truncate text-[11.5px] font-semibold"
                                            :style="{
                                                color: segmentColors[
                                                    segment.key
                                                ].ink,
                                            }"
                                        >
                                            {{ segment.label }}
                                            {{ formatMoney(segment.amount) }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="
                                            activeFlow.out_segments.length === 0
                                        "
                                        class="flex w-full items-center px-3 text-[11.5px]"
                                        style="
                                            background: var(
                                                --hab-progress-track
                                            );
                                            color: var(--hab-muted);
                                        "
                                    >
                                        Nothing left this month
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="
                                debtSegment !== null &&
                                activeFlow.debt_share_of_income !== null
                            "
                            class="hab-anim-row-in mt-[14px] flex items-center gap-2 rounded-[12px] px-[14px] py-3"
                            style="
                                --hab-delay: 0.8s;
                                background: var(--hab-chip-fill);
                            "
                        >
                            <span
                                class="msf hab-anim-sway shrink-0"
                                style="
                                    font-size: 17px;
                                    color: var(--hab-apricot);
                                "
                                >insights</span
                            >
                            <p
                                class="m-0 text-[12.5px]"
                                style="color: var(--hab-ink-secondary)"
                            >
                                {{ formatMoney(debtSegment.amount) }} of what
                                left this month went to killing debt —
                                <span class="font-semibold"
                                    >{{
                                        Math.round(
                                            activeFlow.debt_share_of_income *
                                                100,
                                        )
                                    }}% of your income</span
                                >.
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-[26px] h-px"
                        style="background: var(--hab-hairline)"
                    ></div>
                </template>

                <p v-else class="text-[14px]" style="color: var(--hab-muted)">
                    Add an account to see where your money went this month.
                </p>

                <!-- Latest activity -->
                <div class="relative mt-5 flex items-center justify-between">
                    <h3
                        class="m-0 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                        style="color: var(--hab-ink)"
                    >
                        Latest activity
                    </h3>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="flex items-center gap-[6px] rounded-[10px] px-3 py-[7px] text-[12.5px] font-semibold transition-transform duration-150 hover:-translate-y-px"
                            :style="{
                                border: `1px solid ${activeFilterCount > 0 ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                                background: 'var(--hab-chip-fill)',
                                color:
                                    activeFilterCount > 0
                                        ? 'var(--hab-accent)'
                                        : 'var(--hab-ink-secondary)',
                            }"
                            :disabled="props.accounts.length === 0"
                            @click="filterOpen = !filterOpen"
                        >
                            <span class="msf" style="font-size: 15px"
                                >filter_list</span
                            >
                            Filter
                            <span
                                v-if="activeFilterCount > 0"
                                class="rounded-full px-[6px] py-px text-[10.5px]"
                                style="
                                    background: var(--hab-accent);
                                    color: var(--hab-on-accent);
                                "
                            >
                                {{ activeFilterCount }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="rounded-[10px] px-[14px] py-[7px] text-[12.5px] font-semibold transition-transform duration-150 hover:-translate-y-px disabled:opacity-50"
                            style="
                                background: var(--hab-accent);
                                color: var(--hab-on-accent);
                            "
                            :disabled="props.accounts.length === 0"
                            @click="openEntrySheet"
                        >
                            New entry
                        </button>
                    </div>

                    <!-- Filter popover -->
                    <div
                        v-if="filterOpen"
                        class="fixed inset-0 z-10"
                        @click="filterOpen = false"
                    ></div>
                    <div
                        v-if="filterOpen"
                        class="hab-anim-row-in absolute top-[52px] right-0 z-20 w-[330px] max-w-[calc(100vw-3rem)] rounded-[20px] p-[18px]"
                        style="
                            background: var(--hab-card-bg);
                            border: 1px solid var(--hab-leaf-soft);
                            box-shadow: 0 26px 50px -24px var(--hab-card-shadow);
                        "
                    >
                        <div
                            class="absolute -top-[6px] right-[66px] h-[11px] w-[11px] rotate-45"
                            style="
                                background: var(--hab-card-bg);
                                border-left: 1px solid var(--hab-leaf-soft);
                                border-top: 1px solid var(--hab-leaf-soft);
                            "
                        ></div>

                        <div class="flex items-center justify-between">
                            <p
                                class="m-0 text-[13px] font-bold"
                                style="color: var(--hab-ink)"
                            >
                                Filter entries
                            </p>
                            <button
                                type="button"
                                class="text-[11.5px] font-semibold"
                                style="color: var(--hab-accent)"
                                @click="resetFilters"
                            >
                                Reset
                            </button>
                        </div>

                        <p
                            class="mt-4 mb-[7px] text-[11px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Type
                        </p>
                        <div class="flex flex-wrap gap-[6px]">
                            <button
                                v-for="option in kindFilters"
                                :key="option.key"
                                type="button"
                                class="rounded-full px-[11px] py-[5px] text-[11.5px] font-semibold"
                                :style="
                                    activeKinds.includes(option.key)
                                        ? {
                                              background: 'var(--hab-accent)',
                                              color: 'var(--hab-on-accent)',
                                          }
                                        : {
                                              background:
                                                  'var(--hab-chip-fill)',
                                              border: '1px solid var(--hab-leaf-soft)',
                                              color:
                                                  option.key === 'debt'
                                                      ? 'var(--hab-apricot-ink)'
                                                      : 'var(--hab-muted)',
                                          }
                                "
                                @click="toggleKind(option.key)"
                            >
                                {{ option.label }}
                            </button>
                        </div>

                        <template v-if="usedEnvelopes.length > 0">
                            <p
                                class="mt-4 mb-[7px] text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Envelope
                            </p>
                            <div class="flex flex-wrap gap-[6px]">
                                <button
                                    v-for="envelope in usedEnvelopes"
                                    :key="envelope.id"
                                    type="button"
                                    class="rounded-full px-[11px] py-[5px] text-[11.5px] font-semibold"
                                    :style="
                                        activeEnvelopeIds.includes(envelope.id)
                                            ? {
                                                  background:
                                                      'var(--hab-accent)',
                                                  color: 'var(--hab-on-accent)',
                                              }
                                            : {
                                                  background:
                                                      'var(--hab-chip-fill)',
                                                  border: '1px solid var(--hab-leaf-soft)',
                                                  color: 'var(--hab-muted)',
                                              }
                                    "
                                    @click="toggleEnvelope(envelope.id)"
                                >
                                    {{ envelope.name }}
                                </button>
                            </div>
                        </template>

                        <p
                            class="mt-4 mb-[7px] text-[11px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Amount over
                        </p>
                        <div class="flex items-center gap-[10px]">
                            <input
                                v-model.number="minAmount"
                                type="range"
                                min="0"
                                :max="amountCeiling"
                                step="100"
                                class="h-[6px] flex-1 cursor-pointer appearance-none rounded-full"
                                style="
                                    accent-color: var(--hab-accent);
                                    background: var(--hab-progress-track);
                                "
                            />
                            <span
                                class="w-[74px] text-right text-[12.5px] font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                {{ formatMoney(minAmount) }}
                            </span>
                        </div>

                        <p
                            class="mt-4 mb-[7px] text-[11px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            When
                        </p>
                        <div class="flex gap-[6px]">
                            <button
                                v-for="(label, key) in rangeLabels"
                                :key="key"
                                type="button"
                                class="rounded-full px-[11px] py-[5px] text-[11.5px] font-semibold"
                                :style="
                                    props.filters.range === key
                                        ? {
                                              background: 'var(--hab-accent)',
                                              color: 'var(--hab-on-accent)',
                                          }
                                        : {
                                              background:
                                                  'var(--hab-chip-fill)',
                                              border: '1px solid var(--hab-leaf-soft)',
                                              color: 'var(--hab-muted)',
                                          }
                                "
                                @click="applyRange(key)"
                            >
                                {{ label }}
                            </button>
                        </div>

                        <div
                            v-if="props.filters.range === 'custom'"
                            class="mt-[10px] grid grid-cols-2 gap-2"
                        >
                            <input
                                v-model="customFrom"
                                type="date"
                                class="h-9 w-full rounded-[10px] px-2 text-[12.5px] tabular-nums"
                                style="
                                    border: 1px solid var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                    font-family: var(--hab-mono);
                                "
                                @change="applyRange('custom')"
                            />
                            <input
                                v-model="customTo"
                                type="date"
                                class="h-9 w-full rounded-[10px] px-2 text-[12.5px] tabular-nums"
                                style="
                                    border: 1px solid var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                    font-family: var(--hab-mono);
                                "
                                @change="applyRange('custom')"
                            />
                        </div>

                        <div
                            class="mt-[18px] flex items-center justify-between border-t pt-[14px]"
                            style="border-color: var(--hab-hairline)"
                        >
                            <p
                                class="m-0 text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                <span
                                    class="font-bold"
                                    style="color: var(--hab-ink)"
                                    >{{ filteredEntries.length }}</span
                                >
                                of {{ props.activity.entries.length }} entries ·
                                {{ formatMoney(filteredTotal) }}
                            </p>
                            <button
                                type="button"
                                class="rounded-[10px] px-[14px] py-2 text-[12.5px] font-semibold"
                                style="
                                    background: var(--hab-accent);
                                    color: var(--hab-on-accent);
                                "
                                @click="filterOpen = false"
                            >
                                Show {{ filteredEntries.length }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Active filter chips -->
                <div
                    v-if="activeFilterCount > 0"
                    class="mt-[14px] flex flex-wrap gap-[6px]"
                >
                    <span
                        v-for="kind in activeKinds"
                        :key="kind"
                        class="flex items-center gap-[5px] rounded-full px-[10px] py-[5px] text-[11.5px] font-semibold"
                        style="
                            background: var(--hab-chip-fill);
                            border: 1px solid var(--hab-leaf-soft);
                            color: var(--hab-ink);
                        "
                    >
                        {{ kindFilters.find((k) => k.key === kind)?.label }}
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 14px; color: var(--hab-muted)"
                            @click="toggleKind(kind)"
                        >
                            close
                        </button>
                    </span>
                    <span
                        v-for="id in activeEnvelopeIds"
                        :key="id"
                        class="flex items-center gap-[5px] rounded-full px-[10px] py-[5px] text-[11.5px] font-semibold"
                        style="
                            background: var(--hab-chip-fill);
                            border: 1px solid var(--hab-leaf-soft);
                            color: var(--hab-ink);
                        "
                    >
                        {{ props.envelopes.find((e) => e.id === id)?.name }}
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 14px; color: var(--hab-muted)"
                            @click="toggleEnvelope(id)"
                        >
                            close
                        </button>
                    </span>
                    <span
                        v-if="minAmount > 0"
                        class="flex items-center gap-[5px] rounded-full px-[10px] py-[5px] text-[11.5px] font-semibold"
                        style="
                            background: var(--hab-chip-fill);
                            border: 1px solid var(--hab-leaf-soft);
                            color: var(--hab-ink);
                        "
                    >
                        Over {{ formatMoney(minAmount) }}
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 14px; color: var(--hab-muted)"
                            @click="minAmount = 0"
                        >
                            close
                        </button>
                    </span>
                    <span
                        v-if="props.filters.range !== 'this_month'"
                        class="flex items-center gap-[5px] rounded-full px-[10px] py-[5px] text-[11.5px] font-semibold"
                        style="
                            background: var(--hab-chip-fill);
                            border: 1px solid var(--hab-leaf-soft);
                            color: var(--hab-ink);
                        "
                    >
                        {{ props.activity.range_label }}
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 14px; color: var(--hab-muted)"
                            @click="applyRange('this_month')"
                        >
                            close
                        </button>
                    </span>
                    <button
                        type="button"
                        class="px-1 py-[5px] text-[11.5px] font-semibold"
                        style="color: var(--hab-accent)"
                        @click="resetFilters"
                    >
                        Clear all
                    </button>
                </div>

                <div class="mt-3 flex flex-col">
                    <p
                        v-if="props.activity.entries.length === 0"
                        class="py-2 text-[13px]"
                        style="color: var(--hab-muted)"
                    >
                        Nothing recorded for
                        {{ props.activity.range_label }} yet.
                    </p>
                    <p
                        v-else-if="filteredEntries.length === 0"
                        class="py-2 text-[13px]"
                        style="color: var(--hab-muted)"
                    >
                        No entries match these filters.
                    </p>

                    <button
                        v-for="(entry, i) in visibleEntries"
                        :key="entry.id"
                        type="button"
                        class="hab-anim-row-in flex w-full items-center gap-3 py-[11px] text-left transition-transform duration-150 hover:translate-x-[4px]"
                        :title="`Edit ${entry.title}`"
                        @click="openEditEntry(entry)"
                        :style="{
                            '--hab-delay': `${0.9 + Math.min(i, 8) * 0.08}s`,
                            borderBottom:
                                i === visibleEntries.length - 1
                                    ? 'none'
                                    : '1px solid var(--hab-hairline)',
                        }"
                    >
                        <span
                            class="msf shrink-0"
                            style="font-size: 17px"
                            :style="{
                                color:
                                    entry.kind === 'income'
                                        ? 'var(--hab-accent)'
                                        : entry.kind === 'debt'
                                          ? 'var(--hab-apricot)'
                                          : 'var(--hab-muted)',
                            }"
                        >
                            {{ entry.icon }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="m-0 truncate text-[13.5px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                {{ entry.title }}
                            </p>
                            <p
                                class="m-0 mt-px truncate text-[11.5px]"
                                :style="{
                                    color:
                                        entry.kind === 'income'
                                            ? 'var(--hab-accent)'
                                            : entry.kind === 'debt'
                                              ? 'var(--hab-apricot-ink)'
                                              : 'var(--hab-muted)',
                                }"
                            >
                                {{ entry.occurred_at }} · {{ entry.kind_label
                                }}<template v-if="entry.envelope_name">
                                    · {{ entry.envelope_name }}</template
                                >
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-[14px] font-semibold tabular-nums"
                            style="font-family: var(--hab-mono)"
                            :style="{
                                color:
                                    entry.direction === 'in'
                                        ? 'var(--hab-accent)'
                                        : 'var(--hab-ink)',
                            }"
                        >
                            {{ entry.direction === 'in' ? '+' : '−'
                            }}{{ formatMoney(entry.amount) }}
                        </span>
                    </button>
                </div>

                <div
                    v-if="filteredEntries.length > 7"
                    class="mt-[14px] text-center"
                >
                    <button
                        type="button"
                        class="text-[12.5px] font-semibold"
                        style="color: var(--hab-accent)"
                        @click="showAll = !showAll"
                    >
                        {{
                            showAll
                                ? 'Show fewer'
                                : `See all ${filteredEntries.length} entries →`
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Edit an entry -->
        <div
            v-if="editingEntry"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="closeEditEntry"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[480px] rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 30px 60px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3
                            class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                            style="color: var(--hab-ink)"
                        >
                            Edit entry
                        </h3>
                        <p
                            class="m-0 mt-[3px] text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ editingEntry.title }} · this {{ editEntryLabel }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="closeEditEntry"
                    >
                        close
                    </button>
                </div>

                <div
                    class="mt-[18px] rounded-[18px] p-[18px]"
                    style="
                        background: var(--hab-panel);
                        border: 1px solid var(--hab-card-border);
                    "
                >
                    <label
                        for="edit-amount"
                        class="mb-2 block text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        Amount
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
                            id="edit-amount"
                            v-model="editForm.amount"
                            class="h-[46px] w-full min-w-0 flex-1 bg-transparent p-0 text-[28px] font-extrabold tabular-nums"
                            style="
                                border: none;
                                border-bottom: 2px solid var(--hab-accent);
                                border-radius: 0;
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        />
                    </div>
                </div>

                <div class="mt-4 flex flex-col gap-3">
                    <!-- Transfer: both legs -->
                    <div
                        v-if="editingEntry.type === 'transfer'"
                        class="grid grid-cols-2 gap-[10px]"
                    >
                        <div>
                            <label
                                for="edit-from"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                                >From</label
                            >
                            <select
                                id="edit-from"
                                v-model.number="editForm.from_account_id"
                                class="w-full rounded-[10px] px-[10px] text-[13.5px]"
                                style="
                                    height: 40px;
                                    background: var(--hab-card-bg);
                                    border: 1px solid var(--hab-card-border);
                                    color: var(--hab-ink);
                                "
                            >
                                <option
                                    v-for="account in props.accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label
                                for="edit-to"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                                >To</label
                            >
                            <select
                                id="edit-to"
                                v-model.number="editForm.to_account_id"
                                class="w-full rounded-[10px] px-[10px] text-[13.5px]"
                                style="
                                    height: 40px;
                                    background: var(--hab-card-bg);
                                    border: 1px solid var(--hab-card-border);
                                    color: var(--hab-ink);
                                "
                            >
                                <option
                                    v-for="account in props.accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Everything else: one account -->
                    <div v-else>
                        <label
                            for="edit-account"
                            class="mb-[6px] block text-[12px] font-semibold"
                            style="color: var(--hab-ink-secondary)"
                        >
                            {{
                                editingEntry.type === 'income'
                                    ? 'Deposit to'
                                    : 'Paid from'
                            }}
                        </label>
                        <select
                            id="edit-account"
                            v-model.number="editForm.account_id"
                            class="w-full rounded-[10px] px-[10px] text-[13.5px]"
                            style="
                                height: 40px;
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-card-border);
                                color: var(--hab-ink);
                            "
                        >
                            <option
                                v-for="account in props.accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Expense: envelope -->
                    <div v-if="editingEntry.type === 'expense'">
                        <label
                            for="edit-envelope"
                            class="mb-[6px] block text-[12px] font-semibold"
                            style="color: var(--hab-ink-secondary)"
                            >Envelope</label
                        >
                        <select
                            id="edit-envelope"
                            v-model.number="editForm.envelope_id"
                            class="w-full rounded-[10px] px-[10px] text-[13.5px]"
                            style="
                                height: 40px;
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-card-border);
                                color: var(--hab-ink);
                            "
                        >
                            <option :value="null">No envelope</option>
                            <option
                                v-for="envelope in props.envelopes"
                                :key="envelope.id"
                                :value="envelope.id"
                            >
                                {{ envelope.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-[10px]">
                        <div>
                            <label
                                for="edit-date"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                                >Date</label
                            >
                            <input
                                id="edit-date"
                                v-model="editForm.occurred_at"
                                type="date"
                                class="w-full rounded-[10px] px-3 text-[13.5px] tabular-nums"
                                style="
                                    height: 40px;
                                    background: var(--hab-card-bg);
                                    border: 1px solid var(--hab-card-border);
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            />
                        </div>
                        <div>
                            <label
                                for="edit-description"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                                >Note</label
                            >
                            <input
                                id="edit-description"
                                v-model="editForm.description"
                                type="text"
                                placeholder="Optional"
                                class="w-full rounded-[10px] px-3 text-[13.5px]"
                                style="
                                    height: 40px;
                                    background: var(--hab-card-bg);
                                    border: 1px solid var(--hab-card-border);
                                    color: var(--hab-ink);
                                "
                            />
                        </div>
                    </div>
                </div>

                <p
                    v-if="editingEntry.debt_id !== null"
                    class="m-0 mt-3 flex items-start gap-2 rounded-[12px] p-3 text-[12px]"
                    style="background: var(--hab-apricot-tint); color: #8c6a4a"
                >
                    <span class="msf shrink-0" style="font-size: 16px"
                        >bolt</span
                    >
                    Editing this also moves what the debt owes, and the payoff
                    date with it.
                </p>

                <p
                    v-if="editEntryError"
                    class="m-0 mt-3 text-[12.5px]"
                    style="color: var(--hab-clay)"
                >
                    {{ editEntryError }}
                </p>

                <div class="mt-5 flex items-center gap-3">
                    <button
                        type="button"
                        class="text-[12.5px] font-semibold"
                        style="color: var(--hab-clay)"
                        @click="deleteEditEntry"
                    >
                        Delete entry
                    </button>
                    <button
                        type="button"
                        class="ml-auto rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="closeEditEntry"
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
                        :disabled="editForm.processing"
                        @click="submitEditEntry"
                    >
                        Save changes
                    </button>
                </div>
            </div>
        </div>

        <!-- Add / edit account sheet -->
        <div
            v-if="accountSheet !== 'closed'"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(43, 58, 43, 0.42)"
            @click.self="accountSheet = 'closed'"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[24px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 30px 60px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3
                            class="m-0 font-['Bricolage_Grotesque'] text-[21px] font-bold"
                            style="color: var(--hab-ink)"
                        >
                            {{
                                accountSheet === 'new'
                                    ? 'Add an account'
                                    : 'Edit account'
                            }}
                        </h3>
                        <p
                            class="m-0 mt-[3px] text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            Anything that holds or owes money
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="accountSheet = 'closed'"
                    >
                        close
                    </button>
                </div>

                <p
                    class="mt-5 mb-2 text-[12px] font-semibold"
                    style="color: var(--hab-ink-secondary)"
                >
                    What kind?
                </p>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="option in accountTypeOptions"
                        :key="option.value"
                        type="button"
                        class="flex flex-col items-center gap-[6px] rounded-[14px] px-[6px] py-3"
                        :style="
                            accountForm.type === option.value
                                ? option.value === 'credit'
                                    ? {
                                          background: 'var(--hab-clay-tint)',
                                          border: '1px solid var(--hab-clay-border)',
                                      }
                                    : {
                                          background: 'var(--hab-chip-fill)',
                                          border: '1px solid var(--hab-leaf-soft)',
                                      }
                                : {
                                      background: 'var(--hab-panel)',
                                      border: '1px solid var(--hab-hairline)',
                                  }
                        "
                        @click="pickAccountType(option.value)"
                    >
                        <span
                            class="msf"
                            style="font-size: 19px"
                            :style="{
                                color:
                                    option.value === 'credit'
                                        ? 'var(--hab-clay)'
                                        : accountForm.type === option.value
                                          ? 'var(--hab-accent)'
                                          : 'var(--hab-muted)',
                            }"
                        >
                            {{ option.icon }}
                        </span>
                        <span
                            class="text-[11.5px] font-semibold"
                            :style="{
                                color:
                                    option.value === 'credit'
                                        ? 'var(--hab-clay)'
                                        : accountForm.type === option.value
                                          ? 'var(--hab-ink)'
                                          : 'var(--hab-muted)',
                            }"
                        >
                            {{ option.label }}
                        </span>
                    </button>
                </div>

                <div class="mt-[18px] flex flex-col gap-[14px]">
                    <div>
                        <label
                            for="account-name"
                            class="mb-[6px] block text-[12px] font-semibold"
                            style="color: var(--hab-ink-secondary)"
                        >
                            Account name
                        </label>
                        <input
                            id="account-name"
                            v-model="accountForm.name"
                            type="text"
                            class="h-10 w-full rounded-[10px] px-3 text-[14px]"
                            style="
                                border: 1px solid var(--hab-card-border);
                                background: var(--hab-card-bg);
                                color: var(--hab-ink);
                            "
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label
                                for="account-institution"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                            >
                                Institution
                            </label>
                            <input
                                id="account-institution"
                                v-model="accountForm.institution"
                                type="text"
                                class="h-10 w-full rounded-[10px] px-3 text-[14px]"
                                style="
                                    border: 1px solid var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            />
                        </div>
                        <div>
                            <label
                                for="account-balance"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                            >
                                {{
                                    accountForm.is_liability
                                        ? 'Balance owed'
                                        : 'Starting balance'
                                }}
                            </label>
                            <div class="relative">
                                <span
                                    class="absolute top-1/2 left-3 -translate-y-1/2 text-[15px] font-semibold"
                                    style="color: var(--hab-muted)"
                                >
                                    ₱
                                </span>
                                <MoneyInput
                                    id="account-balance"
                                    v-model="accountForm.balance"
                                    class="h-11 w-full rounded-[10px] pr-3 pl-[26px] text-[17px] font-bold tabular-nums"
                                    style="
                                        border: 1px solid var(--hab-card-border);
                                        background: var(--hab-card-bg);
                                        color: var(--hab-ink);
                                        font-family: var(--hab-mono);
                                    "
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-start gap-[10px] rounded-[14px] px-[15px] py-[13px]"
                        style="
                            background: var(--hab-chip-fill);
                            border: 1px dashed var(--hab-leaf-soft);
                        "
                    >
                        <span
                            class="msf shrink-0"
                            style="font-size: 17px; color: var(--hab-clay)"
                            >credit_card</span
                        >
                        <div class="flex-1">
                            <p
                                class="m-0 text-[12.5px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                This is a liability
                            </p>
                            <p
                                class="m-0 mt-[2px] text-[11.5px]"
                                style="color: var(--hab-muted)"
                            >
                                Its balance is money you owe, so it counts
                                against your net worth.
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="accountForm.is_liability"
                            class="relative h-[22px] w-[38px] shrink-0 rounded-full transition-colors"
                            :style="{
                                background: accountForm.is_liability
                                    ? 'var(--hab-clay)'
                                    : 'var(--hab-progress-track)',
                            }"
                            @click="
                                accountForm.is_liability =
                                    !accountForm.is_liability
                            "
                        >
                            <span
                                class="absolute top-[3px] h-4 w-4 rounded-full transition-all"
                                style="background: var(--hab-card-bg)"
                                :style="{
                                    right: accountForm.is_liability
                                        ? '3px'
                                        : '19px',
                                }"
                            ></span>
                        </button>
                    </div>
                </div>

                <p
                    v-for="error in accountErrors"
                    :key="error"
                    class="mt-2 text-[12px]"
                    style="color: var(--hab-clay)"
                >
                    {{ error }}
                </p>

                <div class="mt-[22px] flex justify-end gap-2">
                    <button
                        type="button"
                        class="h-10 rounded-[10px] px-4 text-[13px] font-semibold"
                        style="
                            border: 1px solid var(--hab-card-border);
                            background: var(--hab-card-bg);
                            color: var(--hab-muted);
                        "
                        :disabled="accountForm.processing"
                        @click="accountSheet = 'closed'"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="hab-anim-breathe h-10 rounded-[10px] px-[18px] text-[13px] font-semibold disabled:opacity-50"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-on-accent);
                        "
                        :disabled="accountForm.processing"
                        @click="submitAccount"
                    >
                        {{
                            accountSheet === 'new'
                                ? 'Add account'
                                : 'Save changes'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- New entry sheet -->
        <div
            v-if="entrySheetOpen"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(43, 58, 43, 0.42)"
            @click.self="entrySheetOpen = false"
        >
            <div
                class="hab-anim-pop my-auto w-full max-w-[560px] rounded-[24px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 30px 60px -28px var(--hab-card-shadow);
                "
            >
                <div class="flex items-start justify-between">
                    <h3
                        class="m-0 font-['Bricolage_Grotesque'] text-[21px] font-bold"
                        style="color: var(--hab-ink)"
                    >
                        New entry
                    </h3>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="entrySheetOpen = false"
                    >
                        close
                    </button>
                </div>

                <div
                    class="mt-4 flex gap-[2px] rounded-[12px] p-[3px]"
                    :style="{
                        background:
                            entryTone === 'debt'
                                ? '#faeee5'
                                : 'var(--hab-chip-fill)',
                    }"
                >
                    <button
                        v-for="tab in entryTabs"
                        :key="tab.key"
                        type="button"
                        class="h-[34px] flex-1 rounded-[9px] text-[12.5px] font-semibold"
                        :style="
                            entryForm.type === tab.key
                                ? {
                                      background: 'var(--hab-card-bg)',
                                      color: activeTabColor(tab.key),
                                      boxShadow:
                                          tab.key === 'debt'
                                              ? '0 1px 3px rgba(120, 70, 30, 0.16)'
                                              : '0 1px 3px rgba(45, 70, 45, 0.12)',
                                  }
                                : {
                                      background: 'transparent',
                                      color:
                                          entryTone === 'debt'
                                              ? '#a58a72'
                                              : 'var(--hab-muted)',
                                  }
                        "
                        @click="pickEntryTab(tab.key)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <!-- Amount hero -->
                <div
                    class="mt-[18px] rounded-[18px] p-[18px]"
                    :style="amountHeroStyle"
                >
                    <label
                        for="entry-amount"
                        class="mb-2 block text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        :style="{ color: amountLabelColor }"
                    >
                        {{ amountLabel }}
                    </label>
                    <div class="flex items-center gap-2">
                        <span
                            class="text-[26px] font-bold sm:text-[30px]"
                            style="font-family: var(--hab-mono)"
                            :style="{ color: amountLabelColor }"
                        >
                            {{ entryTone === 'income' ? '+₱' : '₱' }}
                        </span>
                        <MoneyInput
                            id="entry-amount"
                            v-model="entryForm.amount"
                            class="h-[52px] w-full min-w-0 flex-1 bg-transparent p-0 text-[28px] font-extrabold tabular-nums sm:text-[34px]"
                            style="
                                border: none;
                                border-radius: 0;
                                font-family: var(--hab-mono);
                            "
                            :style="{
                                borderBottom: `2px solid ${amountRuleColor}`,
                                color: amountTextColor,
                            }"
                        />
                    </div>
                    <div
                        v-if="entryForm.type === 'debt'"
                        class="mt-[10px] flex flex-wrap gap-[6px]"
                    >
                        <button
                            v-for="chip in debtAmountChips"
                            :key="chip.key"
                            type="button"
                            class="rounded-full px-[11px] py-[5px] text-[11.5px] font-semibold"
                            :style="
                                Math.abs(entryForm.amount - chip.amount) < 0.005
                                    ? {
                                          background: 'var(--hab-apricot)',
                                          border: '1px solid var(--hab-apricot)',
                                          color: '#fff7ef',
                                      }
                                    : {
                                          background: 'var(--hab-card-bg)',
                                          border: '1px solid var(--hab-apricot-border)',
                                          color: '#8c6a4a',
                                      }
                            "
                            @click="entryForm.amount = chip.amount"
                        >
                            {{ chip.label }}
                        </button>
                    </div>
                    <div v-else class="mt-3 flex flex-wrap gap-[6px]">
                        <button
                            v-for="quick in quickAmounts"
                            :key="quick"
                            type="button"
                            class="rounded-full px-[11px] py-[5px] text-[11.5px] font-semibold"
                            style="
                                background: var(--hab-chip-fill);
                                border: 1px solid var(--hab-leaf-soft);
                                color: var(--hab-ink-secondary);
                            "
                            @click="addQuickAmount(quick)"
                        >
                            +{{ formatMoney(quick) }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex flex-col gap-3">
                    <!-- Debt: which debt, paid from where -->
                    <template v-if="entryForm.type === 'debt'">
                        <p
                            v-if="props.debts.length === 0"
                            class="m-0 rounded-[12px] p-3 text-[13px]"
                            style="
                                background: var(--hab-apricot-tint);
                                color: #8c6a4a;
                            "
                        >
                            Nothing owed yet. Add a debt on the
                            <a
                                href="/payoff-plan"
                                class="font-semibold underline"
                                >Payoff Plan</a
                            >
                            first.
                        </p>

                        <div v-else>
                            <label
                                for="entry-debt"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                                >Toward</label
                            >
                            <div
                                class="flex items-center gap-[9px] rounded-[10px] pr-[10px] pl-3"
                                style="
                                    height: 44px;
                                    background: var(--hab-card-bg);
                                    border: 1px solid var(--hab-apricot-border);
                                "
                            >
                                <span
                                    class="msf"
                                    style="
                                        font-size: 18px;
                                        color: var(--hab-apricot);
                                    "
                                >
                                    {{
                                        selectedDebt?.debt_type === 'revolving'
                                            ? 'credit_card'
                                            : 'account_balance'
                                    }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <select
                                        id="entry-debt"
                                        v-model.number="entryForm.debt_id"
                                        class="w-full border-none bg-transparent p-0 text-[13.5px] font-semibold outline-none"
                                        style="color: var(--hab-ink)"
                                    >
                                        <option
                                            v-for="debt in props.debts"
                                            :key="debt.id"
                                            :value="debt.id"
                                        >
                                            {{ debt.name }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="selectedDebt"
                                        class="m-0 mt-[1px] truncate text-[11px]"
                                        style="color: #8c6a4a"
                                    >
                                        <span v-if="selectedDebt.is_target"
                                            >Target now · </span
                                        ><span
                                            v-if="
                                                selectedDebt.monthly_rate !==
                                                null
                                            "
                                            >{{ selectedDebt.monthly_rate }}%
                                            monthly ·
                                        </span>
                                        {{ formatMoney(selectedDebt.balance) }}
                                        left
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-[10px]">
                            <div>
                                <label
                                    for="entry-debt-from"
                                    class="mb-[6px] block text-[12px] font-semibold"
                                    style="color: var(--hab-ink-secondary)"
                                    >Paid from</label
                                >
                                <select
                                    id="entry-debt-from"
                                    v-model.number="entryForm.from_account_id"
                                    class="w-full rounded-[10px] px-[10px] text-[14px]"
                                    style="
                                        height: 40px;
                                        background: var(--hab-card-bg);
                                        border: 1px solid var(--hab-card-border);
                                        color: var(--hab-ink);
                                    "
                                >
                                    <option
                                        v-for="account in assetAccounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label
                                    for="entry-debt-date"
                                    class="mb-[6px] block text-[12px] font-semibold"
                                    style="color: var(--hab-ink-secondary)"
                                    >Date</label
                                >
                                <input
                                    id="entry-debt-date"
                                    v-model="entryForm.occurred_at"
                                    type="date"
                                    class="w-full rounded-[10px] px-3 text-[13.5px] tabular-nums"
                                    style="
                                        height: 40px;
                                        background: var(--hab-card-bg);
                                        border: 1px solid var(--hab-card-border);
                                        font-family: var(--hab-mono);
                                        color: var(--hab-ink);
                                    "
                                />
                            </div>
                        </div>

                        <!-- Where it lands -->
                        <div
                            v-if="debtPreview"
                            class="rounded-[16px] p-[14px]"
                            style="
                                background: var(--hab-apricot-tint);
                                border: 1px solid var(--hab-apricot-border);
                            "
                        >
                            <div
                                class="flex items-baseline justify-between gap-3"
                            >
                                <p
                                    class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                    style="color: #a58a72"
                                >
                                    Where it lands
                                </p>
                                <p
                                    class="m-0 text-[11.5px]"
                                    style="color: #8c6a4a"
                                >
                                    {{ formatMoney(debtPreview.balanceBefore) }}
                                    →
                                    <span
                                        class="font-bold"
                                        style="color: var(--hab-ink)"
                                        >{{
                                            formatMoney(
                                                debtPreview.balanceAfter,
                                            )
                                        }}</span
                                    >
                                </p>
                            </div>

                            <div
                                v-if="debtPreview.coversInterest"
                                class="mt-[9px] flex overflow-hidden rounded-full"
                                style="height: 10px; background: #f2e2d5"
                            >
                                <div
                                    class="hab-anim-grow-x"
                                    :style="{
                                        width: `${principalShare}%`,
                                        background: 'var(--hab-accent)',
                                    }"
                                ></div>
                                <div
                                    class="hab-anim-grow-x flex-1"
                                    style="
                                        background: var(--hab-apricot);
                                        --hab-delay: 0.1s;
                                    "
                                ></div>
                            </div>

                            <div
                                v-if="debtPreview.coversInterest"
                                class="mt-2 flex justify-between text-[11.5px]"
                            >
                                <span
                                    class="font-semibold"
                                    style="color: var(--hab-accent)"
                                    >{{
                                        formatMoney(debtPreview.principal)
                                    }}
                                    principal</span
                                >
                                <span style="color: var(--hab-apricot-ink)"
                                    >{{
                                        formatMoney(debtPreview.interest)
                                    }}
                                    interest</span
                                >
                            </div>
                            <p
                                v-else
                                class="m-0 mt-2 text-[11.5px] font-semibold"
                                style="color: var(--hab-clay)"
                            >
                                This is less than the
                                {{ formatMoney(debtPreview.interest) }} of
                                interest this month, so the balance still grows.
                            </p>

                            <div
                                v-if="debtPreview.debtFreeAfter"
                                class="mt-3 flex items-start gap-2 border-t pt-3"
                                style="border-color: var(--hab-apricot-border)"
                            >
                                <span
                                    class="msf shrink-0"
                                    style="
                                        font-size: 16px;
                                        color: var(--hab-accent);
                                    "
                                    >event_available</span
                                >
                                <p
                                    class="m-0 text-[11.5px]"
                                    style="color: #8c6a4a"
                                >
                                    Debt-free
                                    <span
                                        v-if="
                                            debtPreview.debtFreeBefore !==
                                            debtPreview.debtFreeAfter
                                        "
                                        class="line-through"
                                        >{{ debtPreview.debtFreeBefore }}</span
                                    >
                                    <span
                                        class="font-bold"
                                        style="color: var(--hab-ink)"
                                    >
                                        {{ debtPreview.debtFreeAfter }}</span
                                    ><template
                                        v-if="
                                            debtPreview.daysEarlier !== null &&
                                            debtPreview.daysEarlier > 0
                                        "
                                    >
                                        —
                                        <span
                                            class="font-semibold"
                                            style="color: var(--hab-accent)"
                                            >{{ debtPreview.daysEarlier }} days
                                            earlier</span
                                        ><template
                                            v-if="debtPreview.interestSaved > 0"
                                        >
                                            ,
                                            {{
                                                formatMoney(
                                                    debtPreview.interestSaved,
                                                )
                                            }}
                                            less interest</template
                                        >.
                                    </template>
                                    <template v-else>
                                        — the horizon holds where it is.
                                    </template>
                                </p>
                            </div>
                        </div>
                    </template>

                    <!-- Expense: envelope -->
                    <div v-if="entryForm.type === 'expense'">
                        <label
                            for="entry-envelope"
                            class="mb-[6px] block text-[12px] font-semibold"
                            style="color: var(--hab-ink-secondary)"
                            >Envelope</label
                        >
                        <select
                            id="entry-envelope"
                            v-model.number="entryForm.envelope_id"
                            class="h-10 w-full rounded-[10px] px-[10px] text-[14px]"
                            style="
                                border: 1px solid var(--hab-card-border);
                                background: var(--hab-card-bg);
                                color: var(--hab-ink);
                            "
                        >
                            <option :value="null">None</option>
                            <option
                                v-for="envelope in props.envelopes"
                                :key="envelope.id"
                                :value="envelope.id"
                            >
                                {{ envelope.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Transfer: from / to -->
                    <div
                        v-if="entryForm.type === 'transfer'"
                        class="relative flex flex-col gap-2"
                    >
                        <div
                            class="rounded-[14px] px-[13px] py-[11px]"
                            style="
                                border: 1px solid var(--hab-card-border);
                                background: var(--hab-card-bg);
                            "
                        >
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.1em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                From
                            </p>
                            <div class="mt-1 flex items-center gap-2">
                                <select
                                    v-model.number="entryForm.from_account_id"
                                    aria-label="Transfer from"
                                    class="min-w-0 flex-1 bg-transparent text-[14px] font-semibold"
                                    style="border: none; color: var(--hab-ink)"
                                >
                                    <option
                                        v-for="account in props.accounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name }}
                                    </option>
                                </select>
                                <span
                                    v-if="transferFrom"
                                    class="shrink-0 text-right text-[11px] tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-muted);
                                    "
                                >
                                    {{
                                        balanceForDisplay(
                                            transferFrom,
                                            transferFrom.balance,
                                        )
                                    }}
                                    →
                                    {{
                                        balanceForDisplay(
                                            transferFrom,
                                            projectedBalance(
                                                transferFrom,
                                                'out',
                                            ),
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="rounded-[14px] px-[13px] py-[11px]"
                            style="
                                border: 1px solid #cfdfc4;
                                background: var(--hab-panel);
                            "
                        >
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.1em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                To
                            </p>
                            <div class="mt-1 flex items-center gap-2">
                                <select
                                    v-model.number="entryForm.to_account_id"
                                    aria-label="Transfer to"
                                    class="min-w-0 flex-1 bg-transparent text-[14px] font-semibold"
                                    style="border: none; color: var(--hab-ink)"
                                >
                                    <option
                                        v-for="account in props.accounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name }}
                                    </option>
                                </select>
                                <span
                                    v-if="transferTo"
                                    class="shrink-0 text-right text-[11px] tabular-nums"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-accent);
                                    "
                                >
                                    {{
                                        balanceForDisplay(
                                            transferTo,
                                            transferTo.balance,
                                        )
                                    }}
                                    →
                                    {{
                                        balanceForDisplay(
                                            transferTo,
                                            projectedBalance(transferTo, 'in'),
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            aria-label="Swap accounts"
                            class="absolute top-1/2 left-1/2 flex h-8 w-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full"
                            style="
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-leaf-soft);
                                box-shadow: 0 2px 6px rgba(45, 70, 45, 0.16);
                            "
                            @click="swapTransferAccounts"
                        >
                            <span
                                class="material-symbols-rounded"
                                style="
                                    font-size: 17px;
                                    color: var(--hab-accent);
                                "
                                >swap_vert</span
                            >
                        </button>
                    </div>

                    <!-- Description -->
                    <div>
                        <label
                            for="entry-description"
                            class="mb-[6px] block text-[12px] font-semibold"
                            style="color: var(--hab-ink-secondary)"
                        >
                            {{
                                entryForm.type === 'expense'
                                    ? 'Merchant'
                                    : entryForm.type === 'income'
                                      ? 'Source'
                                      : 'Note'
                            }}
                        </label>
                        <input
                            id="entry-description"
                            v-model="entryForm.description"
                            type="text"
                            class="h-10 w-full rounded-[10px] px-3 text-[14px]"
                            style="
                                border: 1px solid var(--hab-card-border);
                                background: var(--hab-card-bg);
                                color: var(--hab-ink);
                            "
                        />
                    </div>

                    <div
                        v-if="entryForm.type !== 'debt'"
                        class="grid gap-[10px] sm:grid-cols-2"
                    >
                        <div v-if="entryForm.type !== 'transfer'">
                            <label
                                for="entry-account"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                            >
                                {{
                                    entryForm.type === 'expense'
                                        ? 'Paid from'
                                        : 'Deposit to'
                                }}
                            </label>
                            <select
                                id="entry-account"
                                v-model.number="entryForm.account_id"
                                class="h-10 w-full rounded-[10px] px-[10px] text-[14px]"
                                style="
                                    border: 1px solid var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            >
                                <option
                                    v-for="account in props.accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label
                                for="entry-date"
                                class="mb-[6px] block text-[12px] font-semibold"
                                style="color: var(--hab-ink-secondary)"
                                >Date</label
                            >
                            <input
                                id="entry-date"
                                v-model="entryForm.occurred_at"
                                type="date"
                                class="h-10 w-full rounded-[10px] px-3 text-[13.5px] tabular-nums"
                                style="
                                    border: 1px solid var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                    font-family: var(--hab-mono);
                                "
                            />
                        </div>
                    </div>

                    <!-- One line of consequence before you save. The Debt tab
                         says its piece in "Where it lands" instead. -->
                    <div
                        v-if="entryForm.type !== 'debt'"
                        class="flex items-center gap-[10px] rounded-[14px] px-[14px] py-3"
                        style="background: var(--hab-chip-fill)"
                    >
                        <span
                            class="msf shrink-0"
                            style="font-size: 17px; color: var(--hab-apricot)"
                            >insights</span
                        >
                        <p
                            class="m-0 flex-1 text-[12px]"
                            style="color: var(--hab-ink-secondary)"
                        >
                            <template v-if="entryForm.type === 'transfer'">
                                No envelope changes — your spending plan and net
                                worth stay exactly where they are.
                            </template>
                            <template v-else-if="entryForm.type === 'income'">
                                Net worth rises to
                                <span
                                    class="font-semibold"
                                    style="color: var(--hab-ink)"
                                    >{{
                                        formatMoney(
                                            props.totals.netWorth +
                                                entryForm.amount,
                                        )
                                    }}</span
                                >. Assign it in Budget to give it a job.
                            </template>
                            <template v-else>
                                Net worth falls to
                                <span
                                    class="font-semibold"
                                    style="color: var(--hab-ink)"
                                    >{{
                                        formatMoney(
                                            props.totals.netWorth -
                                                entryForm.amount,
                                        )
                                    }}</span
                                ><template v-if="entryForm.envelope_id === null"
                                    >, and it lands outside any
                                    envelope</template
                                >.
                            </template>
                        </p>
                    </div>
                </div>

                <p
                    v-for="error in entryErrors"
                    :key="error"
                    class="mt-2 text-[12px]"
                    style="color: var(--hab-clay)"
                >
                    {{ error }}
                </p>

                <div class="mt-[22px] flex justify-end gap-2">
                    <button
                        type="button"
                        class="h-10 rounded-[10px] px-4 text-[13px] font-semibold"
                        style="
                            border: 1px solid var(--hab-card-border);
                            background: var(--hab-card-bg);
                            color: var(--hab-muted);
                        "
                        :disabled="entryForm.processing"
                        @click="entrySheetOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="hab-anim-breathe h-10 rounded-[10px] px-[18px] text-[13px] font-semibold disabled:opacity-50"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-on-accent);
                        "
                        :disabled="entryForm.processing"
                        @click="submitEntry"
                    >
                        {{ entrySubmitLabel }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
