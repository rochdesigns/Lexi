<script setup lang="ts">
import { Head, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    destroyDebt,
    linkDebt,
    sendExtra,
    setPriorityDebt,
    previewDebt,
    storeDebt,
    unlinkDebt,
    updateDebt,
    updateSettings,
} from '@/actions/App/Http/Controllers/PayoffPlanController';
import MoneyInput from '@/components/MoneyInput.vue';

type DebtType = 'revolving' | 'installment';
type DebtKind = 'card' | 'loan' | 'installment' | 'person';
type Strategy = 'avalanche' | 'snowball';

type SpendVsPayment = {
    spent: number;
    paid: number;
    net: number;
    period_label: string;
};

type DebtPayment = {
    id: number;
    amount: number;
    occurred_at: string;
    description: string | null;
};

type PayoffDebt = {
    id: number;
    name: string;
    balance: number;
    starting_balance: number;
    percent_paid: number;
    monthly_rate: number | null;
    minimum_payment: number;
    debt_type: DebtType;
    kind: DebtKind | null;
    due_day: number | null;
    linked_account_id: number | null;
    linked_account_name: string | null;
    payoff_month: number | null;
    total_interest: number | null;
    spend_vs_payment: SpendVsPayment | null;
    is_priority: boolean;
    is_pinned: boolean;
    going_backwards: boolean;
    minimum_covers_interest: boolean;
    top_up_to_clear: number | null;
    monthly_interest: number | null;
    paid_this_period: number;
    payments: DebtPayment[];
    cleared_at: string | null;
};

type LiabilityAccount = { id: number; name: string; balance: number };
type AssetAccount = { id: number; name: string; balance: number };

type PayoffSettings = {
    strategy: Strategy;
    extra_monthly: number;
    priority_debt_id: number | null;
};

type PayoffProgress = {
    totalStartingBalance: number;
    totalBalance: number;
    totalPaidOff: number;
    percentPaid: number;
    paidOffCount: number;
    totalDebtsCount: number;
    milestonesReached: number[];
};

type PayoffPace = {
    elapsedMonths: number;
    status: 'ahead' | 'behind' | 'on_pace';
    deltaAmount: number;
};

type PayoffTotals = {
    totalMinimumMonthly: number;
    totalMonthlyPayment: number;
    totalInterestProjected: number;
    totalInterestIsComplete: boolean;
};

type RailBar = { label: string; remaining: number };
type Baseline = {
    months: number | null;
    debtFreeDate: string | null;
    monthsSaved: number | null;
    interestAvoided: number | null;
};
type CurvePoint = {
    extra: number;
    months: number | null;
    totalInterest: number;
};
type QueueEntry = {
    id: number;
    name: string;
    monthly_rate: number | null;
    balance: number;
};
type StrategyProjection = {
    months: number | null;
    debtFreeDate: string | null;
    totalInterest: number;
    firstDebtGoneMonth: number | null;
    queue: QueueEntry[];
};

type NotificationType = 'debt_due_soon' | 'debt_not_on_track';
type PayoffNotification = {
    type: NotificationType;
    severity: 'critical' | 'warning';
    subject: string;
    days?: number;
};

const props = defineProps<{
    debts: PayoffDebt[];
    liabilityAccounts: LiabilityAccount[];
    assetAccounts: AssetAccount[];
    settings: PayoffSettings;
    monthsRemaining: number | null;
    onTrack: boolean;
    debtFreeDate: string | null;
    progress: PayoffProgress | null;
    pace: PayoffPace | null;
    totals: PayoffTotals;
    monthRail: RailBar[] | null;
    baseline: Baseline;
    extraCurve: CurvePoint[];
    unplaced: number;
    strategyCompare: Record<Strategy, StrategyProjection>;
    notifications: PayoffNotification[];
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

function formatRate(rate: number | null): string {
    return rate === null ? 'no rate set' : `${rate}% monthly`;
}

function pluralMonths(months: number): string {
    return `${months} month${months === 1 ? '' : 's'}`;
}

function notificationMessage(notification: PayoffNotification): string {
    if (notification.type === 'debt_not_on_track') {
        return `${notification.subject} isn't on track — its minimum payment doesn't cover the interest it's accruing.`;
    }

    return notification.days === 0
        ? `${notification.subject} is due today.`
        : `${notification.subject} is due in ${notification.days} day${notification.days === 1 ? '' : 's'}.`;
}

// ── Derived plan reads ───────────────────────────────────────────────────

const liveDebts = computed(() =>
    props.debts.filter((debt) => debt.balance > 0),
);
const clearedDebts = computed(() =>
    props.debts.filter((debt) => debt.balance <= 0),
);
const hasDebts = computed(() => props.debts.length > 0);

/**
 * A missing countdown means two very different things: nothing is owed at all,
 * or something is owed that the plan never clears. Only the second is a warning.
 */
const heroLabel = computed(() => {
    if (props.monthsRemaining !== null) {
        return 'You will be debt-free in';
    }

    return hasDebts.value ? 'This plan never finishes' : 'Where you stand';
});
const targetDebt = computed(
    () =>
        liveDebts.value.find((debt) => debt.is_priority) ??
        liveDebts.value[0] ??
        null,
);
const queueAfterTarget = computed(() =>
    liveDebts.value.filter((debt) => debt.id !== targetDebt.value?.id),
);

const largestBalance = computed(() =>
    Math.max(1, ...liveDebts.value.map((debt) => debt.balance)),
);

/** Bar width for the to-scale column: every debt measured against the biggest one. */
function scaleWidth(debt: PayoffDebt): string {
    return `${Math.max(8, (debt.balance / largestBalance.value) * 100)}%`;
}

const railMax = computed(() =>
    Math.max(1, ...(props.monthRail ?? []).map((bar) => bar.remaining)),
);

function railHeight(bar: RailBar): string {
    return `${Math.max(2, (bar.remaining / railMax.value) * 44)}px`;
}

/** Bars already behind us stay apricot; the ones still to come fade toward the page. */
function railColor(index: number): string {
    if (index === (props.monthRail?.length ?? 0) - 1) {
        return 'var(--hab-apricot)';
    }

    const progress = index / Math.max(1, (props.monthRail?.length ?? 1) - 1);

    return progress < 0.25
        ? 'linear-gradient(180deg, var(--hab-apricot), var(--hab-apricot-ink))'
        : `color-mix(in srgb, var(--hab-leaf-soft) ${100 - progress * 45}%, var(--hab-surface))`;
}

const monthlySplit = computed(() => {
    const extra = props.settings.extra_monthly;

    return extra > 0
        ? `${formatMoney(props.totals.totalMinimumMonthly)} minimums + ${formatMoney(extra)} extra`
        : `${formatMoney(props.totals.totalMinimumMonthly)} in minimums`;
});

const paceCopy = computed(() => {
    if (!props.pace) {
        return null;
    }

    if (props.pace.status === 'on_pace') {
        return 'right on pace';
    }

    return `${formatMoney(props.pace.deltaAmount)} ${props.pace.status === 'ahead' ? 'ahead of' : 'behind'} plan`;
});

const sinceStartCopy = computed(() => {
    const saved = props.baseline.monthsSaved;

    if (saved === null || saved <= 0) {
        return null;
    }

    return `${pluralMonths(saved)} sooner than paying minimums alone.`;
});

// ── The standing monthly extra ───────────────────────────────────────────

const editingMonthlyExtra = ref(false);
const monthlyExtraDraft = ref(props.settings.extra_monthly);

function openMonthlyExtra(): void {
    monthlyExtraDraft.value = props.settings.extra_monthly;
    editingMonthlyExtra.value = true;
}

/**
 * The extra sent every month, on top of the minimums. Distinct from 15a's
 * one-off payment: this is the pace the whole projection is drawn from.
 */
function saveMonthlyExtra(): void {
    router.patch(
        updateSettings().url,
        {
            strategy: props.settings.strategy,
            extra_monthly: Math.max(0, monthlyExtraDraft.value || 0),
        },
        {
            preserveScroll: true,
            onSuccess: () => (editingMonthlyExtra.value = false),
        },
    );
}

// ── Send extra (15a) ─────────────────────────────────────────────────────

const extraSheetDebt = ref<PayoffDebt | null>(null);
const extraAmount = ref(0);
const extraPermanent = ref(false);
const extraAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);
const extraError = ref<string | null>(null);

const extraCeiling = computed(
    () => props.extraCurve[props.extraCurve.length - 1]?.extra ?? 0,
);

function openExtraSheet(debt: PayoffDebt | null): void {
    if (!debt) {
        return;
    }

    extraSheetDebt.value = debt;
    extraAmount.value = Math.min(
        props.unplaced > 0 ? props.unplaced : 1000,
        extraCeiling.value,
    );
    extraPermanent.value = false;
    extraError.value = null;
    extraAccountId.value = props.assetAccounts[0]?.id ?? null;
}

/**
 * Reads the pre-computed curve at an arbitrary slider position, interpolating
 * between the two sampled points either side of it. The whole point is that the
 * date and the interest move under the thumb without a round trip per drag.
 */
function projectExtra(
    amount: number,
): { months: number | null; totalInterest: number } | null {
    const curve = props.extraCurve;

    if (curve.length === 0) {
        return null;
    }

    if (amount <= curve[0].extra) {
        return curve[0];
    }

    for (let i = 1; i < curve.length; i++) {
        if (amount > curve[i].extra) {
            continue;
        }

        const low = curve[i - 1];
        const high = curve[i];
        const span = high.extra - low.extra;
        const t = span === 0 ? 0 : (amount - low.extra) / span;

        return {
            months:
                low.months === null || high.months === null
                    ? null
                    : low.months + (high.months - low.months) * t,
            totalInterest:
                low.totalInterest +
                (high.totalInterest - low.totalInterest) * t,
        };
    }

    return curve[curve.length - 1];
}

const extraProjection = computed(() => {
    const withExtra = projectExtra(
        props.settings.extra_monthly + extraAmount.value,
    );
    const asIs = projectExtra(props.settings.extra_monthly);

    if (!withExtra || !asIs) {
        return null;
    }

    const daysSooner =
        withExtra.months === null || asIs.months === null
            ? null
            : Math.max(0, Math.round((asIs.months - withExtra.months) * 30.44));

    return {
        daysSooner,
        interestSaved: Math.max(
            0,
            asIs.totalInterest - withExtra.totalInterest,
        ),
        monthsNow: asIs.months,
        monthsWith: withExtra.months,
    };
});

function submitExtra(): void {
    const debt = extraSheetDebt.value;

    if (!debt || extraAccountId.value === null) {
        extraError.value = 'Pick an account to pay from.';

        return;
    }

    if (extraAmount.value <= 0) {
        extraError.value = 'Enter an amount above zero.';

        return;
    }

    router.post(
        sendExtra(debt.id).url,
        {
            amount: extraAmount.value,
            from_account_id: extraAccountId.value,
            make_permanent: extraPermanent.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (extraSheetDebt.value = null),
            onError: (errors) =>
                (extraError.value =
                    Object.values(errors)[0] ??
                    'That payment could not be sent.'),
        },
    );
}

// ── Fix the minimum (15b) ────────────────────────────────────────────────

type FixChoice = 'top_up' | 'make_target' | 'pay_off';

const fixSheetDebt = ref<PayoffDebt | null>(null);
const fixChoice = ref<FixChoice>('top_up');

function openFixSheet(debt: PayoffDebt): void {
    fixSheetDebt.value = debt;
    fixChoice.value = 'top_up';
}

const payOffShortfall = computed(() => {
    const debt = fixSheetDebt.value;

    if (!debt) {
        return 0;
    }

    return Math.max(0, debt.balance - props.unplaced);
});

function submitFix(): void {
    const debt = fixSheetDebt.value;

    if (!debt) {
        return;
    }

    if (fixChoice.value === 'top_up' && debt.top_up_to_clear !== null) {
        router.patch(
            updateDebt(debt.id).url,
            {
                name: debt.name,
                balance: debt.balance,
                monthly_rate: debt.monthly_rate,
                minimum_payment: debt.minimum_payment + debt.top_up_to_clear,
                debt_type: debt.debt_type,
                due_day: debt.due_day,
            },
            {
                preserveScroll: true,
                onSuccess: () => (fixSheetDebt.value = null),
            },
        );

        return;
    }

    if (fixChoice.value === 'make_target') {
        router.patch(
            setPriorityDebt().url,
            { priority_debt_id: debt.id },
            {
                preserveScroll: true,
                onSuccess: () => (fixSheetDebt.value = null),
            },
        );

        return;
    }

    fixSheetDebt.value = null;
    openExtraSheet(debt);
    extraAmount.value = Math.min(debt.balance, extraCeiling.value);
}

// ── Which order? (15c) ───────────────────────────────────────────────────

const orderSheetOpen = ref(false);

const strategyGap = computed(() => {
    const current = props.strategyCompare[props.settings.strategy];
    const other =
        props.strategyCompare[
            props.settings.strategy === 'avalanche' ? 'snowball' : 'avalanche'
        ];

    if (
        !current ||
        !other ||
        current.months === null ||
        other.months === null
    ) {
        return null;
    }

    return {
        months: other.months - current.months,
        interest: other.totalInterest - current.totalInterest,
    };
});

function chooseStrategy(strategy: Strategy): void {
    if (strategy === props.settings.strategy) {
        orderSheetOpen.value = false;

        return;
    }

    router.patch(
        updateSettings().url,
        { strategy, extra_monthly: props.settings.extra_monthly },
        {
            preserveScroll: true,
            onSuccess: () => (orderSheetOpen.value = false),
        },
    );
}

// ── One debt, opened (15d) ───────────────────────────────────────────────

const debtSheet = ref<PayoffDebt | null>(null);

function openDebtSheet(debt: PayoffDebt): void {
    debtSheet.value = debt;
    editingId.value = null;
    linkAccountId.value = '';
    linkChoicePending.value = null;
}

/** Keeps the open sheet pointed at fresh props after a partial reload. */
watch(
    () => props.debts,
    (debts) => {
        if (debtSheet.value) {
            debtSheet.value =
                debts.find((debt) => debt.id === debtSheet.value?.id) ?? null;
        }

        if (extraSheetDebt.value) {
            extraSheetDebt.value =
                debts.find((debt) => debt.id === extraSheetDebt.value?.id) ??
                null;
        }

        if (fixSheetDebt.value) {
            fixSheetDebt.value =
                debts.find((debt) => debt.id === fixSheetDebt.value?.id) ??
                null;
        }
    },
);

function makeTarget(debt: PayoffDebt): void {
    router.patch(
        setPriorityDebt().url,
        { priority_debt_id: debt.is_pinned ? null : debt.id },
        { preserveScroll: true },
    );
}

// ── 15e · Add a debt, with the horizon it would push the plan to ─────────

type DebtKindChoice = {
    kind: DebtKind;
    label: string;
    icon: string;
    debtType: DebtType;
    placeholder: string;
};

const DEBT_KINDS: DebtKindChoice[] = [
    {
        kind: 'card',
        label: 'Card',
        icon: 'credit_card',
        debtType: 'revolving',
        placeholder: 'BPI, Metrobank…',
    },
    {
        kind: 'loan',
        label: 'Loan',
        icon: 'account_balance',
        debtType: 'installment',
        placeholder: 'Pag-IBIG, SSS…',
    },
    {
        kind: 'installment',
        label: 'Instal.',
        icon: 'phone_iphone',
        debtType: 'installment',
        placeholder: 'Home Credit, Lazada…',
    },
    {
        kind: 'person',
        label: 'Person',
        icon: 'group',
        debtType: 'installment',
        placeholder: 'A friend or family member',
    },
];

type PreviewRailBar = { remaining: number; added: boolean };
type DebtPreview = {
    monthsRemaining: number | null;
    debtFreeDate: string | null;
    monthsAdded: number | null;
    interestAdded: number;
    queuePosition: number | null;
    queueAhead: string[];
    rail: PreviewRailBar[] | null;
};

const preview = ref<DebtPreview | null>(null);
const previewHttp = useHttp({
    balance: 0,
    monthly_rate: null as number | null,
    minimum_payment: 0,
});

const editingId = ref<number | 'new' | null>(null);
const debtForm = useForm({
    name: '',
    balance: 0,
    monthly_rate: null as number | null,
    minimum_payment: 0,
    debt_type: 'installment' as DebtType,
    kind: 'card' as DebtKind,
    linked_account_id: null as number | null,
    due_day: null as number | null,
});

/** A guess is only worth drawing once there is a balance and something paying it down. */
const previewIsAnswerable = computed(
    () => debtForm.balance > 0 && debtForm.minimum_payment > 0,
);

const previewReady = computed(
    () => previewIsAnswerable.value && preview.value !== null,
);

/**
 * The kind decides how the engine treats the debt — a card revolves, everything
 * else amortises — and gives the name field a useful placeholder.
 */
function chooseKind(kind: DebtKind): void {
    const choice = DEBT_KINDS.find((entry) => entry.kind === kind);

    if (!choice) {
        return;
    }

    debtForm.kind = kind;
    debtForm.debt_type = choice.debtType;
}

/** The icon a debt is recognised by, falling back to its behaviour when unset. */
function kindIcon(debt: PayoffDebt): string {
    const choice = DEBT_KINDS.find((entry) => entry.kind === debt.kind);

    if (choice) {
        return choice.icon;
    }

    return debt.debt_type === 'revolving' ? 'credit_card' : 'account_balance';
}

const kindPlaceholder = computed(
    () =>
        DEBT_KINDS.find((entry) => entry.kind === debtForm.kind)?.placeholder ??
        'Name',
);

/** Liability accounts that no debt is mirroring yet. */
const linkableAccounts = computed(() => {
    const taken = new Set(
        props.debts
            .map((debt) => debt.linked_account_id)
            .filter((id): id is number => id !== null),
    );

    return props.liabilityAccounts.filter((account) => !taken.has(account.id));
});

const linkedAccountForNewDebt = computed(
    () =>
        props.liabilityAccounts.find(
            (account) => account.id === debtForm.linked_account_id,
        ) ?? null,
);

/**
 * Choosing the account it lives on fills in what is owed, since the account is
 * the live figure, and takes the account's name unless one has been typed. A
 * debt that mirrors an account but is called something else is impossible to
 * follow once there are two of them.
 *
 * Typing over either is still allowed — the account is brought to whatever
 * balance is entered when the debt is saved.
 */
function onNewDebtAccountChange(): void {
    const account = linkedAccountForNewDebt.value;

    if (account === null) {
        return;
    }

    if (debtForm.balance <= 0) {
        debtForm.balance = account.balance;
    }

    if (debtForm.name.trim() === '') {
        debtForm.name = account.name;
    }
}

/**
 * Share of the principal a suggested minimum clears on top of the interest.
 * Mirrors PayoffEngine::MINIMUM_PRINCIPAL_SHARE, which applies the same rule
 * when a card arrives without a minimum. Kept here too so the field fills as
 * you type rather than after a round trip.
 */
const MINIMUM_PRINCIPAL_SHARE = 0.01;

const suggestedMinimum = computed(() => {
    if (debtForm.monthly_rate === null || debtForm.balance <= 0) {
        return null;
    }

    const interest = debtForm.balance * (debtForm.monthly_rate / 100);

    return (
        Math.round(
            (interest + debtForm.balance * MINIMUM_PRINCIPAL_SHARE) * 100,
        ) / 100
    );
});

/** Set once a minimum is typed by hand, so the estimate stops overwriting it. */
const minimumTouched = ref(false);

/**
 * A card's minimum follows its balance and rate until someone states a real
 * one. Only cards: an installment loan's minimum is a fixed contractual figure
 * that no formula here can guess.
 */
watch(
    () => [debtForm.kind, debtForm.balance, debtForm.monthly_rate],
    () => {
        if (
            editingId.value !== 'new' ||
            debtForm.kind !== 'card' ||
            minimumTouched.value ||
            suggestedMinimum.value === null
        ) {
            return;
        }

        debtForm.minimum_payment = suggestedMinimum.value;
    },
);

watch(
    () => debtForm.minimum_payment,
    (value) => {
        if (
            suggestedMinimum.value !== null &&
            Math.abs(value - suggestedMinimum.value) > 0.005
        ) {
            minimumTouched.value = true;
        }
    },
);

const minimumIsEstimated = computed(
    () =>
        debtForm.kind === 'card' &&
        !minimumTouched.value &&
        suggestedMinimum.value !== null,
);

let previewTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * Asks the server what the plan becomes with this debt in it. Debounced, because
 * the numbers are typed a digit at a time and each keystroke is a simulation.
 */
function refreshPreview(): void {
    clearTimeout(previewTimer);

    if (!previewIsAnswerable.value) {
        preview.value = null;

        return;
    }

    previewTimer = setTimeout(() => {
        previewHttp.balance = debtForm.balance;
        previewHttp.monthly_rate = debtForm.monthly_rate;
        previewHttp.minimum_payment = debtForm.minimum_payment;

        previewHttp.post(previewDebt().url, {
            onSuccess: (response: unknown) => {
                preview.value = response as DebtPreview;
            },
            onError: () => {
                preview.value = null;
            },
        });
    }, PREVIEW_DEBOUNCE_MS);
}

const PREVIEW_DEBOUNCE_MS = 350;

watch(
    () => [
        debtForm.balance,
        debtForm.monthly_rate,
        debtForm.minimum_payment,
        editingId.value,
    ],
    () => {
        if (editingId.value === 'new') {
            refreshPreview();
        }
    },
);

const previewRailMax = computed(() =>
    Math.max(1, ...(preview.value?.rail ?? []).map((bar) => bar.remaining)),
);

function previewBarHeight(bar: PreviewRailBar): string {
    return `${Math.max(4, (bar.remaining / previewRailMax.value) * 100)}%`;
}

/** One line saying what this debt costs and where it lands in the queue. */
const previewCopy = computed(() => {
    if (!preview.value) {
        return '';
    }

    const parts: string[] = [];

    if (debtForm.monthly_rate !== null && preview.value.interestAdded > 0) {
        parts.push(
            `${formatMoney(debtForm.balance)} at ${debtForm.monthly_rate}% adds about ${formatMoney(preview.value.interestAdded)} in interest.`,
        );
    } else {
        parts.push(`${formatMoney(debtForm.balance)} with no interest set.`);
    }

    const position = preview.value.queuePosition;

    if (position !== null) {
        const ahead = preview.value.queueAhead;
        const behind =
            ahead.length === 0
                ? 'It goes to the front of the queue.'
                : `It slots in ${ordinal(position)}, after ${listNames(ahead)}.`;

        parts.push(behind);
    }

    return parts.join(' ');
});

function ordinal(n: number): string {
    const names = ['first', 'second', 'third', 'fourth', 'fifth'];

    return names[n - 1] ?? `${n}th`;
}

function listNames(names: string[]): string {
    if (names.length === 1) {
        return names[0];
    }

    if (names.length === 2) {
        return `${names[0]} and ${names[1]}`;
    }

    return `${names[0]} and ${names.length - 1} others`;
}

// ── Add / edit debt ──────────────────────────────────────────────────────

function openAddRow(): void {
    debtSheet.value = null;
    editingId.value = 'new';
    debtForm.clearErrors();
    debtForm.name = '';
    debtForm.balance = 0;
    debtForm.monthly_rate = null;
    debtForm.minimum_payment = 0;
    debtForm.debt_type = 'revolving';
    debtForm.kind = 'card';
    debtForm.linked_account_id = null;
    minimumTouched.value = false;
    debtForm.due_day = null;
    preview.value = null;
}

function openEditRow(debt: PayoffDebt): void {
    editingId.value = debt.id;
    debtForm.clearErrors();
    debtForm.name = debt.name;
    debtForm.balance = debt.balance;
    debtForm.monthly_rate = debt.monthly_rate;
    debtForm.minimum_payment = debt.minimum_payment;
    debtForm.debt_type = debt.debt_type;
    debtForm.kind =
        debt.kind ?? (debt.debt_type === 'revolving' ? 'card' : 'loan');
    debtForm.due_day = debt.due_day;
    linkAccountId.value = '';
    linkChoicePending.value = null;
}

function cancelRow(): void {
    editingId.value = null;
    debtForm.clearErrors();
    linkAccountId.value = '';
    linkChoicePending.value = null;
}

// ── Link / unlink a debt to a Ledger account ────────────────────────────

const linkAccountId = ref<number | ''>('');
const linkChoicePending = ref<{
    debtId: number;
    accountId: number;
    debtBalance: number;
    accountBalance: number;
} | null>(null);

function onLinkAccountChange(debt: PayoffDebt): void {
    if (linkAccountId.value === '') {
        return;
    }

    const account = props.liabilityAccounts.find(
        (a) => a.id === linkAccountId.value,
    );

    if (!account) {
        return;
    }

    if (debt.balance !== account.balance) {
        linkChoicePending.value = {
            debtId: debt.id,
            accountId: account.id,
            debtBalance: debt.balance,
            accountBalance: account.balance,
        };

        return;
    }

    submitLink(debt.id, account.id, 'account');
}

function submitLink(
    debtId: number,
    accountId: number,
    balanceChoice: 'debt' | 'account',
): void {
    router.post(
        linkDebt(debtId).url,
        { linked_account_id: accountId, balance_choice: balanceChoice },
        { preserveScroll: true, onSuccess: () => (editingId.value = null) },
    );
}

function cancelLinkChoice(): void {
    linkChoicePending.value = null;
    linkAccountId.value = '';
}

function unlinkDebtAccount(debt: PayoffDebt): void {
    if (
        !window.confirm(
            `Unlink ${debt.name} from its ledger account? Its balance will stop syncing and stay editable here.`,
        )
    ) {
        return;
    }

    router.post(unlinkDebt(debt.id).url, {}, { preserveScroll: true });
}

function calculateMinimumPayment(): void {
    if (debtForm.debt_type !== 'revolving' || debtForm.monthly_rate === null) {
        return;
    }

    const interest = debtForm.balance * (debtForm.monthly_rate / 100);
    debtForm.minimum_payment =
        Math.round((interest + debtForm.balance * 0.01) * 100) / 100;
}

function submitRow(): void {
    if (editingId.value === 'new') {
        debtForm.post(storeDebt().url, {
            preserveScroll: true,
            onSuccess: () => (editingId.value = null),
        });

        return;
    }

    if (editingId.value === null) {
        return;
    }

    // Linking an existing debt goes through its own control, which asks which
    // balance is right when the two disagree. Sending the link from here would
    // skip that question, so the edit form leaves it alone.
    debtForm
        .transform((data) => ({
            name: data.name,
            balance: data.balance,
            monthly_rate: data.monthly_rate,
            minimum_payment: data.minimum_payment,
            debt_type: data.debt_type,
            kind: data.kind,
            due_day: data.due_day,
        }))
        .patch(updateDebt(editingId.value).url, {
            preserveScroll: true,
            onSuccess: () => (editingId.value = null),
        });
}

function removeDebt(debt: PayoffDebt): void {
    if (!window.confirm(`Delete ${debt.name}?`)) {
        return;
    }

    router.delete(destroyDebt(debt.id).url, {
        preserveScroll: true,
        onSuccess: () => (debtSheet.value = null),
    });
}
</script>

<template>
    <Head title="Payoff Plan" />

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
        <!-- Notifications -->
        <div
            v-if="props.notifications.length > 0"
            class="hab-anim-row-in mb-5 rounded-[18px] p-4"
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

        <!-- ── Hero: countdown + month rail ───────────────────────────── -->
        <div
            class="relative overflow-hidden rounded-[22px] px-[26px] pt-[22px] pb-5"
            style="
                background: var(--hab-panel);
                border: 1px solid var(--hab-card-border);
                box-shadow: 0 14px 34px -28px var(--hab-card-shadow);
            "
        >
            <div
                class="pointer-events-none absolute inset-0"
                style="
                    background: repeating-linear-gradient(
                        90deg,
                        rgba(45, 70, 45, 0.05) 0 1px,
                        transparent 1px 46px
                    );
                "
            ></div>
            <div
                class="hab-anim-glow pointer-events-none absolute -top-[30%] -right-[6%] h-[420px] w-[420px] rounded-full"
                style="
                    background: radial-gradient(
                        circle,
                        rgba(201, 124, 58, 0.2) 0%,
                        transparent 66%
                    );
                "
            ></div>

            <div
                class="relative flex flex-wrap items-end justify-between gap-8"
            >
                <div>
                    <p
                        class="m-0 text-[12px] font-semibold tracking-[.16em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        {{ heroLabel }}
                    </p>

                    <div
                        v-if="props.monthsRemaining !== null"
                        class="hab-anim-row-in mt-[6px] flex items-end gap-[14px]"
                    >
                        <span
                            class="text-[64px] leading-[.86] font-extrabold tracking-[-.045em] tabular-nums sm:text-[88px]"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        >
                            {{ props.monthsRemaining }}
                        </span>
                        <span
                            class="pb-[9px] font-['Bricolage_Grotesque'] text-[24px] leading-none font-semibold"
                            style="color: var(--hab-muted)"
                        >
                            {{
                                props.monthsRemaining === 1 ? 'month' : 'months'
                            }}
                        </span>
                    </div>
                    <p
                        v-else-if="hasDebts"
                        class="hab-anim-row-in m-0 mt-2 max-w-[420px] text-[14px]"
                        style="color: var(--hab-clay)"
                    >
                        At least one debt earns more interest each month than
                        its minimum pays off, so the balance never reaches zero.
                    </p>
                    <p
                        v-else
                        class="hab-anim-row-in m-0 mt-[6px] font-['Bricolage_Grotesque'] text-[40px] leading-none font-bold"
                        style="color: var(--hab-ink)"
                    >
                        Nothing owed
                    </p>

                    <p
                        v-if="props.debtFreeDate"
                        class="m-0 mt-[10px] text-[15px]"
                        style="color: var(--hab-ink)"
                    >
                        <span style="color: var(--hab-muted)">That's </span>
                        <span class="font-semibold">{{
                            props.debtFreeDate
                        }}</span>
                        <span
                            v-if="sinceStartCopy"
                            style="color: var(--hab-muted)"
                        >
                            — {{ sinceStartCopy }}</span
                        >
                    </p>
                    <p
                        v-else-if="!hasDebts"
                        class="m-0 mt-[10px] text-[15px]"
                        style="color: var(--hab-muted)"
                    >
                        Add what you owe and this page will tell you when you'll
                        be free of it.
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-6 pb-[2px]">
                    <div v-if="props.progress">
                        <p
                            class="m-0 text-[12px]"
                            style="color: var(--hab-faint)"
                        >
                            Paid off so far
                        </p>
                        <p
                            class="m-0 mt-1 text-[26px] leading-none font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-apricot-ink);
                            "
                        >
                            {{ formatMoney(props.progress.totalPaidOff) }}
                        </p>
                        <p
                            class="m-0 mt-[5px] text-[12.5px]"
                            style="color: var(--hab-faint)"
                        >
                            of
                            {{
                                formatMoney(props.progress.totalStartingBalance)
                            }}
                            ·
                            {{ Math.round(props.progress.percentPaid * 100) }}%
                        </p>
                    </div>

                    <div
                        class="border-l pl-7"
                        style="border-color: var(--hab-card-border)"
                    >
                        <p
                            class="m-0 text-[12px]"
                            style="color: var(--hab-faint)"
                        >
                            Sending monthly
                        </p>
                        <p
                            class="m-0 mt-1 text-[26px] leading-none font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-accent);
                            "
                        >
                            {{ formatMoney(props.totals.totalMonthlyPayment) }}
                        </p>
                        <button
                            v-if="!editingMonthlyExtra"
                            type="button"
                            class="m-0 mt-[5px] flex items-center gap-1 text-[12.5px] hover:underline"
                            style="color: var(--hab-faint)"
                            title="Change the extra you send each month"
                            @click="openMonthlyExtra"
                        >
                            {{ monthlySplit }}
                            <span
                                class="material-symbols-rounded"
                                style="font-size: 14px"
                                >edit</span
                            >
                        </button>
                        <div
                            v-else
                            class="mt-[5px] flex items-center gap-2"
                            @keydown.esc="editingMonthlyExtra = false"
                        >
                            <label
                                for="monthly-extra"
                                class="text-[12px] whitespace-nowrap"
                                style="color: var(--hab-faint)"
                                >Extra each month</label
                            >
                            <MoneyInput
                                id="monthly-extra"
                                v-model="monthlyExtraDraft"
                                class="w-[110px] rounded-[8px] border px-2 py-[5px] text-[13px] font-semibold tabular-nums"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                                @keydown.enter="saveMonthlyExtra"
                            />
                            <button
                                type="button"
                                class="rounded-full px-3 py-[5px] text-[12px] font-semibold"
                                style="
                                    background: var(--hab-accent);
                                    color: var(--hab-btn-dark-ink);
                                "
                                @click="saveMonthlyExtra"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="text-[12px] font-semibold"
                                style="color: var(--hab-muted)"
                                @click="editingMonthlyExtra = false"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>

                    <div v-if="paceCopy" class="pb-[2px]">
                        <span
                            class="inline-flex items-center gap-[6px] rounded-full px-3 py-[6px] text-[12px] font-semibold"
                            :style="{
                                background:
                                    props.pace?.status === 'behind'
                                        ? 'var(--hab-clay-tint)'
                                        : 'var(--hab-apricot-tint)',
                                color:
                                    props.pace?.status === 'behind'
                                        ? 'var(--hab-clay)'
                                        : 'var(--hab-apricot-ink)',
                            }"
                        >
                            <span class="msf" style="font-size: 14px">
                                {{
                                    props.pace?.status === 'behind'
                                        ? 'trending_down'
                                        : 'trending_up'
                                }}
                            </span>
                            {{ paceCopy }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Month rail: one bar per month of what's still owed -->
            <div
                v-if="props.monthRail && props.monthRail.length > 1"
                class="relative mt-5"
            >
                <div class="flex items-end gap-[3px]" style="height: 44px">
                    <div
                        v-for="(bar, i) in props.monthRail"
                        :key="bar.label + i"
                        class="hab-anim-rise flex-1 rounded-t-[3px]"
                        :title="`${bar.label} — ${formatMoney(bar.remaining)} left`"
                        :style="{
                            height: railHeight(bar),
                            background: railColor(i),
                            '--hab-delay': `${Math.min(i * 0.03, 0.8)}s`,
                        }"
                    ></div>
                </div>
                <div
                    class="mt-2 flex justify-between text-[11px]"
                    style="color: var(--hab-faint)"
                >
                    <span
                        >{{ props.monthRail[0].label }} —
                        {{ formatMoney(props.monthRail[0].remaining) }}
                        left</span
                    >
                    <span class="hidden sm:inline"
                        >each bar is a month of what you still owe</span
                    >
                    <span
                        class="font-semibold"
                        style="color: var(--hab-apricot-ink)"
                    >
                        {{ props.monthRail[props.monthRail.length - 1].label }}
                        — ₱0
                    </span>
                </div>
            </div>
        </div>

        <div
            class="mt-5 grid grid-cols-1 items-start gap-5 lg:grid-cols-[1fr_440px]"
        >
            <!-- ── Target right now ───────────────────────────────────── -->
            <div
                v-if="targetDebt"
                class="rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                    box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
                "
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-4"
                >
                    <div>
                        <p
                            class="m-0 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Target right now
                        </p>
                        <button
                            type="button"
                            class="m-0 mt-[6px] block text-left font-['Bricolage_Grotesque'] text-[26px] font-bold hover:underline"
                            style="color: var(--hab-ink)"
                            @click="openDebtSheet(targetDebt)"
                        >
                            {{ targetDebt.name }}
                        </button>
                        <p
                            class="m-0 mt-1 text-[13px]"
                            style="color: var(--hab-muted)"
                        >
                            <span v-if="targetDebt.is_pinned"
                                >Pinned by you</span
                            >
                            <span v-else>{{
                                props.settings.strategy === 'avalanche'
                                    ? 'Highest rate'
                                    : 'Smallest balance'
                            }}</span>
                            · {{ formatRate(targetDebt.monthly_rate) }}
                            <span v-if="targetDebt.due_day !== null"
                                >· due day {{ targetDebt.due_day }}</span
                            >
                        </p>
                    </div>
                    <div class="text-right">
                        <p
                            class="m-0 text-[30px] font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        >
                            {{ formatMoney(targetDebt.balance) }}
                        </p>
                        <p
                            class="m-0 mt-[2px] text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            left of
                            {{ formatMoney(targetDebt.starting_balance) }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-[18px] h-[14px] overflow-hidden rounded-full"
                    style="background: var(--hab-progress-track)"
                >
                    <div
                        class="hab-anim-grow-x h-full rounded-full"
                        :style="{
                            width: `${Math.max(2, targetDebt.percent_paid * 100)}%`,
                            background:
                                'linear-gradient(90deg, var(--hab-apricot-ink), var(--hab-apricot))',
                        }"
                    ></div>
                </div>
                <div
                    class="mt-2 flex justify-between text-[12px]"
                    style="color: var(--hab-muted)"
                >
                    <span
                        >{{
                            formatMoney(
                                Math.max(
                                    0,
                                    targetDebt.starting_balance -
                                        targetDebt.balance,
                                ),
                            )
                        }}
                        knocked down</span
                    >
                    <span v-if="targetDebt.payoff_month !== null"
                        >clears month {{ targetDebt.payoff_month }}</span
                    >
                    <span v-else style="color: var(--hab-clay)"
                        >not falling at this rate</span
                    >
                </div>

                <!-- What one more payment buys -->
                <div
                    class="mt-[22px] rounded-[16px] p-[18px]"
                    style="
                        background: var(--hab-chip-fill);
                        border: 1px solid var(--hab-card-border);
                    "
                >
                    <template
                        v-if="
                            extraProjection &&
                            extraProjection.daysSooner !== null &&
                            extraProjection.daysSooner > 0
                        "
                    >
                        <p
                            class="m-0 text-[13px]"
                            style="color: var(--hab-ink-secondary)"
                        >
                            If you sent
                            <span
                                class="font-semibold"
                                style="color: var(--hab-ink)"
                                >{{ formatMoney(extraAmount) }}</span
                            >
                            more each month, you'd be free
                        </p>
                        <p
                            class="m-0 mt-[6px] text-[32px] leading-none font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-accent);
                            "
                        >
                            {{ extraProjection.daysSooner }} days sooner<span
                                class="text-[16px] font-medium"
                                style="color: var(--hab-muted)"
                            >
                                ·
                                {{ formatMoney(extraProjection.interestSaved) }}
                                less interest</span
                            >
                        </p>
                    </template>
                    <p
                        v-else
                        class="m-0 text-[13px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        Drag to see what one more payment would buy you.
                    </p>

                    <div class="mt-[14px] flex items-center gap-3">
                        <input
                            v-model.number="extraAmount"
                            type="range"
                            min="0"
                            :max="Math.max(1, extraCeiling)"
                            step="100"
                            class="h-[6px] flex-1 cursor-pointer appearance-none rounded-full"
                            style="
                                accent-color: var(--hab-accent);
                                background: var(--hab-leaf-soft);
                            "
                            aria-label="Extra payment to preview"
                        />
                        <span
                            class="w-[96px] text-right text-[12.5px] font-semibold"
                            style="color: var(--hab-ink)"
                        >
                            {{ formatMoney(extraAmount) }} extra
                        </span>
                    </div>

                    <button
                        type="button"
                        class="hab-anim-breathe mt-4 rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        @click="openExtraSheet(targetDebt)"
                    >
                        Send {{ formatMoney(extraAmount) }} more this month
                    </button>
                </div>

                <div
                    class="mt-[22px] h-px"
                    style="background: var(--hab-hairline)"
                ></div>
                <p
                    class="mt-[18px] mb-3 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                    style="color: var(--hab-faint)"
                >
                    Then, in order
                </p>

                <p
                    v-if="queueAfterTarget.length === 0"
                    class="m-0 text-[13.5px]"
                    style="color: var(--hab-muted)"
                >
                    Nothing else queued — this is the last one.
                </p>
                <div class="flex flex-col gap-[10px]">
                    <div
                        v-for="(debt, i) in queueAfterTarget"
                        :key="debt.id"
                        class="hab-anim-row-in flex items-center gap-[14px] rounded-[14px] px-[14px] py-3"
                        :style="{
                            background: debt.going_backwards
                                ? 'var(--hab-clay-tint)'
                                : 'var(--hab-chip-fill)',
                            border: debt.going_backwards
                                ? '1px solid var(--hab-clay-border)'
                                : '1px solid transparent',
                            '--hab-delay': `${0.1 + i * 0.06}s`,
                        }"
                    >
                        <span
                            class="w-[18px] text-[15px] font-bold tabular-nums"
                            style="font-family: var(--hab-mono)"
                            :style="{
                                color: debt.going_backwards
                                    ? 'var(--hab-clay)'
                                    : 'var(--hab-apricot)',
                            }"
                        >
                            {{ i + 2 }}
                        </span>
                        <button
                            type="button"
                            class="flex-1 text-left"
                            @click="openDebtSheet(debt)"
                        >
                            <p
                                class="m-0 text-[13.5px] font-semibold"
                                :style="{
                                    color: debt.going_backwards
                                        ? 'var(--hab-clay)'
                                        : 'var(--hab-ink)',
                                }"
                            >
                                {{ debt.name }}
                            </p>
                            <p
                                class="m-0 mt-[1px] text-[12px]"
                                :style="{
                                    color: debt.going_backwards
                                        ? 'var(--hab-clay-ink)'
                                        : 'var(--hab-muted)',
                                }"
                            >
                                {{ formatMoney(debt.balance) }}
                                <span v-if="debt.going_backwards"
                                    >· minimum doesn't cover interest</span
                                >
                                <span v-else-if="debt.payoff_month !== null"
                                    >· clears month
                                    {{ debt.payoff_month }}</span
                                >
                            </p>
                        </button>
                        <button
                            v-if="debt.going_backwards"
                            type="button"
                            class="rounded-[9px] px-3 py-[7px] text-[12px] font-semibold"
                            style="
                                background: var(--hab-clay);
                                color: var(--hab-clay-tint);
                            "
                            @click="openFixSheet(debt)"
                        >
                            Fix it
                        </button>
                        <div
                            v-else
                            class="h-[6px] w-[120px] overflow-hidden rounded-full"
                            style="background: var(--hab-card-border)"
                        >
                            <div
                                class="hab-anim-grow-x h-full rounded-full"
                                :style="{
                                    width: `${Math.max(2, debt.percent_paid * 100)}%`,
                                    background: 'var(--hab-apricot)',
                                }"
                            ></div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="mt-4 flex items-center gap-2 text-[13px] font-semibold"
                    style="color: var(--hab-accent)"
                    @click="openAddRow"
                >
                    <span
                        class="material-symbols-rounded"
                        style="font-size: 16px"
                        >add</span
                    >
                    Add a debt
                </button>
            </div>

            <!-- Empty state stands in for the target card -->
            <div
                v-else
                class="rounded-[22px] p-6"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <h2
                    class="m-0 font-['Bricolage_Grotesque'] text-[20px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    No debts yet
                </h2>
                <p
                    class="m-0 mt-2 text-[13.5px]"
                    style="color: var(--hab-muted)"
                >
                    Add what you owe and this page will tell you when you'll be
                    free of it.
                </p>
                <button
                    type="button"
                    class="mt-4 flex items-center gap-2 rounded-[12px] px-[16px] py-[10px] text-[13px] font-semibold"
                    style="
                        background: var(--hab-accent);
                        color: var(--hab-btn-dark-ink);
                    "
                    @click="openAddRow"
                >
                    <span
                        class="material-symbols-rounded"
                        style="font-size: 16px"
                        >add</span
                    >
                    Add a debt
                </button>
            </div>

            <!-- ── What's left, to scale ──────────────────────────────── -->
            <div
                class="rounded-[22px] px-6 py-[22px]"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <div class="flex items-center justify-between">
                    <p
                        class="m-0 text-[11.5px] font-semibold tracking-[.14em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        What's left, to scale
                    </p>
                    <div class="flex gap-[6px]">
                        <button
                            v-for="strategy in [
                                'avalanche',
                                'snowball',
                            ] as Strategy[]"
                            :key="strategy"
                            type="button"
                            class="rounded-full px-[10px] py-1 text-[11.5px] font-semibold capitalize"
                            :style="{
                                background:
                                    props.settings.strategy === strategy
                                        ? 'var(--hab-accent)'
                                        : 'var(--hab-chip-fill)',
                                color:
                                    props.settings.strategy === strategy
                                        ? 'var(--hab-btn-dark-ink)'
                                        : 'var(--hab-ink-secondary)',
                            }"
                            @click="orderSheetOpen = true"
                        >
                            {{ strategy }}
                        </button>
                    </div>
                </div>

                <p
                    v-if="liveDebts.length === 0"
                    class="m-0 mt-5 text-[13.5px]"
                    style="color: var(--hab-muted)"
                >
                    Nothing left to scale — you owe nothing.
                </p>

                <div class="mt-5 flex flex-col gap-[18px]">
                    <button
                        v-for="(debt, i) in liveDebts"
                        :key="debt.id"
                        type="button"
                        class="block w-full text-left"
                        @click="openDebtSheet(debt)"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <span
                                class="text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                {{ debt.name }}
                                <span
                                    v-if="debt.is_priority"
                                    class="text-[11.5px] font-semibold"
                                    style="color: var(--hab-apricot)"
                                    >· TARGET</span
                                >
                            </span>
                            <span
                                class="text-[17px] font-bold tabular-nums"
                                style="font-family: var(--hab-mono)"
                                :style="{
                                    color: debt.going_backwards
                                        ? 'var(--hab-clay)'
                                        : 'var(--hab-ink)',
                                }"
                            >
                                {{ formatMoney(debt.balance) }}
                            </span>
                        </div>
                        <div
                            class="mt-[7px] flex h-[26px] overflow-hidden rounded-[8px]"
                            style="background: var(--hab-progress-track)"
                            :style="{ width: scaleWidth(debt) }"
                        >
                            <div
                                class="hab-anim-grow-x"
                                :style="{
                                    width: `${Math.max(1, debt.percent_paid * 100)}%`,
                                    background:
                                        'linear-gradient(90deg, var(--hab-apricot-ink), var(--hab-apricot))',
                                    '--hab-delay': `${i * 0.05}s`,
                                }"
                            ></div>
                            <div
                                class="flex-1"
                                :style="{
                                    background: debt.going_backwards
                                        ? 'repeating-linear-gradient(45deg, var(--hab-clay-border) 0 5px, #e2c3b5 5px 10px)'
                                        : 'var(--hab-leaf-soft)',
                                }"
                            ></div>
                        </div>
                        <div
                            class="mt-[5px] text-[11.5px]"
                            :style="{
                                color: debt.going_backwards
                                    ? 'var(--hab-clay)'
                                    : 'var(--hab-muted)',
                            }"
                        >
                            <template v-if="debt.going_backwards"
                                >not falling — minimum doesn't cover
                                interest</template
                            >
                            <template v-else>
                                {{
                                    formatMoney(
                                        Math.max(
                                            0,
                                            debt.starting_balance -
                                                debt.balance,
                                        ),
                                    )
                                }}
                                paid
                                <span v-if="debt.monthly_rate !== null"
                                    >· {{ debt.monthly_rate }}%/mo</span
                                >
                                <span v-if="debt.payoff_month !== null"
                                    >· clears month
                                    {{ debt.payoff_month }}</span
                                >
                            </template>
                        </div>
                    </button>

                    <div
                        v-for="debt in clearedDebts"
                        :key="debt.id"
                        class="flex items-center gap-[10px] rounded-[12px] px-3 py-[10px]"
                        style="background: var(--hab-chip-fill)"
                    >
                        <span
                            class="msf"
                            style="font-size: 17px; color: var(--hab-accent)"
                            >check_circle</span
                        >
                        <span
                            class="flex-1 text-[13px] font-semibold"
                            style="color: var(--hab-accent)"
                        >
                            {{ debt.name }} — cleared<span
                                v-if="debt.cleared_at"
                            >
                                {{ debt.cleared_at }}</span
                            >
                        </span>
                        <span
                            class="text-[12px]"
                            style="color: var(--hab-ink-secondary)"
                            >{{ formatMoney(debt.starting_balance) }}</span
                        >
                    </div>
                </div>

                <div
                    v-if="props.progress"
                    class="mt-5 flex flex-wrap gap-[26px] border-t pt-[18px]"
                    style="border-color: var(--hab-hairline)"
                >
                    <div
                        v-if="
                            props.baseline.monthsSaved !== null &&
                            props.baseline.monthsSaved > 0
                        "
                    >
                        <p
                            class="m-0 text-[24px] leading-none font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        >
                            {{ pluralMonths(props.baseline.monthsSaved) }}
                        </p>
                        <p
                            class="m-0 mt-[3px] text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            shaved off by the extra you send
                        </p>
                    </div>
                    <div
                        v-if="props.totals.totalInterestIsComplete"
                        :class="
                            props.baseline.monthsSaved
                                ? 'border-l pl-[26px]'
                                : ''
                        "
                        style="border-color: var(--hab-hairline)"
                    >
                        <p
                            class="m-0 text-[24px] leading-none font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        >
                            ≈{{
                                formatMoney(props.totals.totalInterestProjected)
                            }}
                        </p>
                        <p
                            class="m-0 mt-[3px] text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            interest still ahead<span
                                v-if="props.baseline.interestAvoided"
                            >
                                ·
                                {{
                                    formatMoney(props.baseline.interestAvoided)
                                }}
                                already avoided</span
                            >
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 15a · Send extra ───────────────────────────────────────── -->
        <div
            v-if="extraSheetDebt"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="extraSheetDebt = null"
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
                            Send extra to {{ extraSheetDebt.name }}
                        </h3>
                        <p
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            On top of the
                            {{ formatMoney(props.totals.totalMonthlyPayment) }}
                            you already send each month.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="extraSheetDebt = null"
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
                        On top of the
                        {{ formatMoney(props.settings.extra_monthly) }} you
                        already send
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
                            v-model="extraAmount"
                            class="h-[46px] w-full min-w-0 flex-1 border-0 border-b-2 bg-transparent p-0 text-[30px] font-extrabold tabular-nums outline-none"
                            style="
                                font-family: var(--hab-mono);
                                border-color: var(--hab-accent);
                                color: var(--hab-ink);
                            "
                        />
                    </div>
                    <div class="mt-4 flex items-center gap-3">
                        <input
                            v-model.number="extraAmount"
                            type="range"
                            min="0"
                            :max="Math.max(1, extraCeiling)"
                            step="100"
                            class="h-[6px] flex-1 cursor-pointer appearance-none rounded-full"
                            style="
                                accent-color: var(--hab-accent);
                                background: var(--hab-leaf-soft);
                            "
                            aria-label="Extra payment amount"
                        />
                        <span
                            class="text-[12px] whitespace-nowrap"
                            style="color: var(--hab-muted)"
                            >up to {{ formatMoney(extraCeiling) }}</span
                        >
                    </div>
                    <p
                        class="m-0 mt-2 text-[12px]"
                        style="color: var(--hab-muted)"
                    >
                        <template v-if="props.unplaced > 0">
                            {{ formatMoney(props.unplaced) }} is the unplaced
                            money sitting in your Budget right now.
                        </template>
                        <template v-else
                            >Nothing is unplaced in your Budget — this would
                            come out of your cushion.</template
                        >
                    </p>
                </div>

                <div
                    v-if="extraProjection"
                    class="mt-4 rounded-[18px] p-[18px]"
                    style="
                        background: var(--hab-surface);
                        border: 1px solid var(--hab-card-border);
                    "
                >
                    <p
                        class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        What it buys you
                    </p>
                    <div class="mt-3 flex flex-wrap gap-6">
                        <div>
                            <p
                                class="m-0 text-[26px] leading-none font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-accent);
                                "
                            >
                                {{ extraProjection.daysSooner ?? '—' }} days
                            </p>
                            <p
                                class="m-0 mt-1 text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                sooner
                            </p>
                        </div>
                        <div
                            class="border-l pl-6"
                            style="border-color: var(--hab-card-border)"
                        >
                            <p
                                class="m-0 text-[26px] leading-none font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-apricot-ink);
                                "
                            >
                                {{ formatMoney(extraProjection.interestSaved) }}
                            </p>
                            <p
                                class="m-0 mt-1 text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                less interest paid
                            </p>
                        </div>
                    </div>
                    <div
                        class="mt-4 flex flex-col gap-2 text-[12px]"
                        style="color: var(--hab-muted)"
                    >
                        <div class="flex items-center gap-3">
                            <span class="w-[68px]">Now</span>
                            <div
                                class="h-[6px] flex-1 overflow-hidden rounded-full"
                                style="background: var(--hab-leaf-soft)"
                            >
                                <div
                                    class="h-full rounded-full"
                                    style="
                                        width: 100%;
                                        background: var(--hab-leaf-mid);
                                    "
                                ></div>
                            </div>
                            <span class="w-[86px] text-right"
                                >{{
                                    extraProjection.monthsNow?.toFixed(1) ?? '—'
                                }}
                                months</span
                            >
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-[68px]">With extra</span>
                            <div
                                class="h-[6px] flex-1 overflow-hidden rounded-full"
                                style="background: var(--hab-leaf-soft)"
                            >
                                <div
                                    class="h-full rounded-full"
                                    style="background: var(--hab-accent)"
                                    :style="{
                                        width: `${
                                            extraProjection.monthsNow &&
                                            extraProjection.monthsWith
                                                ? Math.max(
                                                      4,
                                                      (extraProjection.monthsWith /
                                                          extraProjection.monthsNow) *
                                                          100,
                                                  )
                                                : 100
                                        }%`,
                                    }"
                                ></div>
                            </div>
                            <span class="w-[86px] text-right"
                                >{{
                                    extraProjection.monthsWith?.toFixed(1) ??
                                    '—'
                                }}
                                months</span
                            >
                        </div>
                    </div>
                </div>

                <div
                    class="mt-4 rounded-[16px] p-4"
                    style="background: var(--hab-chip-fill)"
                >
                    <div class="flex items-center gap-3">
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
                                Comes from
                            </p>
                            <select
                                v-model.number="extraAccountId"
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
                                    {{ account.name }} —
                                    {{ formatMoney(account.balance) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <label
                        class="mt-3 flex items-center gap-[10px] text-[12.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        <input
                            v-model="extraPermanent"
                            type="checkbox"
                            class="h-4 w-4"
                            style="accent-color: var(--hab-accent)"
                        />
                        Make it permanent — raise the monthly extra to
                        {{
                            formatMoney(
                                props.settings.extra_monthly + extraAmount,
                            )
                        }}
                    </label>
                </div>

                <p
                    v-if="extraError"
                    class="m-0 mt-3 text-[12.5px]"
                    style="color: var(--hab-clay)"
                >
                    {{ extraError }}
                </p>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="extraSheetDebt = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        @click="submitExtra"
                    >
                        Send {{ formatMoney(extraAmount) }} more
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 15b · Fix the minimum ──────────────────────────────────── -->
        <div
            v-if="fixSheetDebt"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="fixSheetDebt = null"
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
                            {{ fixSheetDebt.name }} is going backwards
                        </h3>
                        <p
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ formatMoney(fixSheetDebt.balance) }} owed ·
                            {{ formatRate(fixSheetDebt.monthly_rate) }} — the
                            {{ formatMoney(fixSheetDebt.minimum_payment) }}
                            minimum is less than the
                            {{
                                formatMoney(fixSheetDebt.monthly_interest ?? 0)
                            }}
                            of interest it earns.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="fixSheetDebt = null"
                    >
                        close
                    </button>
                </div>

                <p
                    class="mt-5 mb-3 text-[11px] font-semibold tracking-[.12em] uppercase"
                    style="color: var(--hab-faint)"
                >
                    Pick a fix
                </p>

                <div class="flex flex-col gap-[10px]">
                    <button
                        v-if="fixSheetDebt.top_up_to_clear !== null"
                        type="button"
                        class="flex items-start gap-3 rounded-[14px] p-4 text-left"
                        :style="{
                            background:
                                fixChoice === 'top_up'
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${fixChoice === 'top_up' ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                        @click="fixChoice = 'top_up'"
                    >
                        <span
                            class="msf mt-[1px]"
                            style="font-size: 18px; color: var(--hab-accent)"
                        >
                            {{
                                fixChoice === 'top_up'
                                    ? 'radio_button_checked'
                                    : 'radio_button_unchecked'
                            }}
                        </span>
                        <span class="flex-1">
                            <span
                                class="block text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                Add
                                {{ formatMoney(fixSheetDebt.top_up_to_clear) }}
                                a month to the minimum
                            </span>
                            <span
                                class="mt-1 block text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                Enough to make it actually fall, not just stall.
                            </span>
                        </span>
                        <span
                            class="rounded-full px-[10px] py-[3px] text-[10.5px] font-bold"
                            style="
                                background: var(--hab-accent);
                                color: var(--hab-btn-dark-ink);
                            "
                        >
                            stops the bleed
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex items-start gap-3 rounded-[14px] p-4 text-left"
                        :style="{
                            background:
                                fixChoice === 'make_target'
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${fixChoice === 'make_target' ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                        @click="fixChoice = 'make_target'"
                    >
                        <span
                            class="msf mt-[1px]"
                            style="font-size: 18px; color: var(--hab-accent)"
                        >
                            {{
                                fixChoice === 'make_target'
                                    ? 'radio_button_checked'
                                    : 'radio_button_unchecked'
                            }}
                        </span>
                        <span class="flex-1">
                            <span
                                class="block text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                Make it the target instead<span
                                    v-if="
                                        targetDebt &&
                                        targetDebt.id !== fixSheetDebt.id
                                    "
                                >
                                    of {{ targetDebt.name }}</span
                                >
                            </span>
                            <span
                                class="mt-1 block text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                The whole extra payment goes here until it's
                                gone — but the rate you leave running costs you
                                elsewhere.
                            </span>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex items-start gap-3 rounded-[14px] p-4 text-left"
                        :style="{
                            background:
                                fixChoice === 'pay_off'
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${fixChoice === 'pay_off' ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                        @click="fixChoice = 'pay_off'"
                    >
                        <span
                            class="msf mt-[1px]"
                            style="font-size: 18px; color: var(--hab-accent)"
                        >
                            {{
                                fixChoice === 'pay_off'
                                    ? 'radio_button_checked'
                                    : 'radio_button_unchecked'
                            }}
                        </span>
                        <span class="flex-1">
                            <span
                                class="block text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                            >
                                Pay it off outright —
                                {{ formatMoney(fixSheetDebt.balance) }}
                            </span>
                            <span
                                class="mt-1 block text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                <template v-if="payOffShortfall > 0">
                                    You'd need
                                    {{ formatMoney(payOffShortfall) }} more than
                                    the
                                    {{ formatMoney(props.unplaced) }} unplaced.
                                </template>
                                <template v-else
                                    >Your unplaced
                                    {{ formatMoney(props.unplaced) }} covers
                                    it.</template
                                >
                            </span>
                        </span>
                        <span
                            v-if="payOffShortfall > 0"
                            class="rounded-full px-[10px] py-[3px] text-[10.5px] font-bold"
                            style="
                                background: var(--hab-clay-tint);
                                color: var(--hab-clay);
                            "
                        >
                            can't yet
                        </span>
                    </button>
                </div>

                <div
                    class="mt-5 flex items-start gap-3 rounded-[14px] p-4"
                    style="background: var(--hab-chip-fill)"
                >
                    <span
                        class="msf"
                        style="font-size: 18px; color: var(--hab-accent)"
                        >event_available</span
                    >
                    <p
                        class="m-0 text-[12.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        <template
                            v-if="
                                fixChoice === 'top_up' &&
                                fixSheetDebt.top_up_to_clear !== null
                            "
                        >
                            With
                            {{ formatMoney(fixSheetDebt.top_up_to_clear) }}
                            added, this debt starts falling instead of growing —
                            and the plan gets an end date again.
                        </template>
                        <template v-else-if="fixChoice === 'make_target'">
                            Pinning it sends the whole extra payment here first.
                            The rate you leave running elsewhere is the cost.
                        </template>
                        <template v-else>
                            Clearing it outright removes it from the plan
                            entirely.
                        </template>
                    </p>
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="fixSheetDebt = null"
                    >
                        Not now
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        @click="submitFix"
                    >
                        <template
                            v-if="
                                fixChoice === 'top_up' &&
                                fixSheetDebt.top_up_to_clear !== null
                            "
                        >
                            Add
                            {{ formatMoney(fixSheetDebt.top_up_to_clear) }} a
                            month
                        </template>
                        <template v-else-if="fixChoice === 'make_target'"
                            >Make it the target</template
                        >
                        <template v-else>Pay it off</template>
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 15c · Which order? ─────────────────────────────────────── -->
        <div
            v-if="orderSheetOpen"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="orderSheetOpen = false"
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
                            Which order?
                        </h3>
                        <p
                            class="m-0 mt-1 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            Same
                            {{ formatMoney(props.totals.totalMonthlyPayment) }}
                            a month either way. Only the order changes.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="material-symbols-rounded"
                        style="font-size: 20px; color: var(--hab-faint)"
                        @click="orderSheetOpen = false"
                    >
                        close
                    </button>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div
                        v-for="strategy in [
                            'avalanche',
                            'snowball',
                        ] as Strategy[]"
                        :key="strategy"
                        class="rounded-[16px] p-4"
                        :style="{
                            background:
                                props.settings.strategy === strategy
                                    ? 'var(--hab-chip-fill)'
                                    : 'transparent',
                            border: `1px solid ${props.settings.strategy === strategy ? 'var(--hab-accent)' : 'var(--hab-card-border)'}`,
                        }"
                    >
                        <div class="flex items-center justify-between">
                            <p
                                class="m-0 text-[15px] font-bold capitalize"
                                style="color: var(--hab-ink)"
                            >
                                {{ strategy }}
                            </p>
                            <span
                                v-if="props.settings.strategy === strategy"
                                class="rounded-full px-[9px] py-[2px] text-[10.5px] font-bold"
                                style="
                                    background: var(--hab-accent);
                                    color: var(--hab-btn-dark-ink);
                                "
                            >
                                current
                            </span>
                        </div>
                        <p
                            class="m-0 mt-1 text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            {{
                                strategy === 'avalanche'
                                    ? 'Highest rate first'
                                    : 'Smallest balance first'
                            }}
                        </p>
                        <p
                            class="m-0 mt-3 text-[18px] font-bold tabular-nums"
                            style="
                                font-family: var(--hab-mono);
                                color: var(--hab-ink);
                            "
                        >
                            {{
                                props.strategyCompare[strategy]?.debtFreeDate ??
                                'never'
                            }}
                        </p>
                        <p
                            class="m-0 mt-1 text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            ≈{{
                                formatMoney(
                                    props.strategyCompare[strategy]
                                        ?.totalInterest ?? 0,
                                )
                            }}
                            interest ahead
                        </p>
                        <p
                            v-if="
                                props.strategyCompare[strategy]
                                    ?.firstDebtGoneMonth
                            "
                            class="m-0 mt-1 text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            First debt gone: month
                            {{
                                props.strategyCompare[strategy]
                                    .firstDebtGoneMonth
                            }}
                        </p>
                    </div>
                </div>

                <p
                    class="mt-5 mb-2 text-[11px] font-semibold tracking-[.12em] uppercase"
                    style="color: var(--hab-faint)"
                >
                    The queue, side by side
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div
                        v-for="strategy in [
                            'avalanche',
                            'snowball',
                        ] as Strategy[]"
                        :key="strategy"
                        class="flex flex-col gap-[6px]"
                    >
                        <div
                            v-for="(entry, i) in props.strategyCompare[strategy]
                                ?.queue ?? []"
                            :key="entry.id"
                            class="flex items-center gap-2 rounded-[10px] px-[10px] py-2 text-[12px]"
                            style="background: var(--hab-chip-fill)"
                        >
                            <span
                                class="font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-apricot);
                                "
                                >{{ i + 1 }}</span
                            >
                            <span
                                class="flex-1 truncate"
                                style="color: var(--hab-ink)"
                                >{{ entry.name }}</span
                            >
                            <span style="color: var(--hab-muted)">
                                {{
                                    strategy === 'avalanche'
                                        ? entry.monthly_rate !== null
                                            ? `${entry.monthly_rate}%`
                                            : '—'
                                        : formatMoney(entry.balance)
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="strategyGap"
                    class="mt-5 flex items-start gap-3 rounded-[14px] p-4"
                    style="background: var(--hab-chip-fill)"
                >
                    <span
                        class="msf"
                        style="font-size: 18px; color: var(--hab-accent)"
                        >psychology</span
                    >
                    <p
                        class="m-0 text-[12.5px]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        <template
                            v-if="
                                strategyGap.months > 0 ||
                                strategyGap.interest > 0
                            "
                        >
                            Switching costs
                            {{ formatMoney(Math.abs(strategyGap.interest)) }}
                            and
                            {{ pluralMonths(Math.abs(strategyGap.months)) }} —
                            but you'd clear something sooner. If quitting is the
                            risk, that's worth paying for.
                        </template>
                        <template v-else>
                            Switching would cost you nothing here — the two
                            orders finish within a rounding error of each other.
                        </template>
                    </p>
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-[12px] px-4 py-[11px] text-[13px] font-semibold"
                        style="color: var(--hab-muted)"
                        @click="chooseStrategy(props.settings.strategy)"
                    >
                        Keep {{ props.settings.strategy }}
                    </button>
                    <button
                        type="button"
                        class="rounded-[12px] px-[18px] py-[11px] text-[13px] font-semibold"
                        style="
                            background: var(--hab-accent);
                            color: var(--hab-btn-dark-ink);
                        "
                        @click="
                            chooseStrategy(
                                props.settings.strategy === 'avalanche'
                                    ? 'snowball'
                                    : 'avalanche',
                            )
                        "
                    >
                        Switch to
                        {{
                            props.settings.strategy === 'avalanche'
                                ? 'snowball'
                                : 'avalanche'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 15d · One debt, opened ─────────────────────────────────── -->
        <div
            v-if="debtSheet"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="debtSheet = null"
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
                    class="p-6"
                    style="
                        background: var(--hab-panel);
                        border-bottom: 1px solid var(--hab-card-border);
                    "
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="msf"
                                    style="
                                        font-size: 18px;
                                        color: var(--hab-apricot);
                                    "
                                >
                                    {{ kindIcon(debtSheet) }}
                                </span>
                                <span
                                    v-if="debtSheet.is_priority"
                                    class="rounded-full px-[9px] py-[2px] text-[10.5px] font-bold"
                                    style="
                                        background: var(--hab-apricot);
                                        color: #fff7ef;
                                    "
                                >
                                    target
                                </span>
                            </div>
                            <h3
                                class="m-0 mt-2 font-['Bricolage_Grotesque'] text-[22px] font-bold"
                                style="color: var(--hab-ink)"
                            >
                                {{ debtSheet.name }}
                            </h3>
                            <p
                                class="m-0 mt-1 text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                {{ formatRate(debtSheet.monthly_rate) }}
                                <span v-if="debtSheet.due_day !== null"
                                    >· due day {{ debtSheet.due_day }}</span
                                >
                                · minimum
                                {{ formatMoney(debtSheet.minimum_payment) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 20px; color: var(--hab-faint)"
                            @click="debtSheet = null"
                        >
                            close
                        </button>
                    </div>

                    <div class="mt-4 flex items-end justify-between gap-4">
                        <div>
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Owed now
                            </p>
                            <p
                                class="m-0 mt-1 text-[30px] leading-none font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                {{ formatMoney(debtSheet.balance) }}
                            </p>
                        </div>
                        <p
                            class="m-0 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{
                                formatMoney(
                                    Math.max(
                                        0,
                                        debtSheet.starting_balance -
                                            debtSheet.balance,
                                    ),
                                )
                            }}
                            down from
                            {{ formatMoney(debtSheet.starting_balance) }}
                        </p>
                    </div>

                    <div
                        class="mt-3 h-[10px] overflow-hidden rounded-full"
                        style="background: var(--hab-progress-track)"
                    >
                        <div
                            class="hab-anim-grow-x h-full rounded-full"
                            :style="{
                                width: `${Math.max(2, debtSheet.percent_paid * 100)}%`,
                                background:
                                    'linear-gradient(90deg, var(--hab-apricot-ink), var(--hab-apricot))',
                            }"
                        ></div>
                    </div>
                    <p
                        class="m-0 mt-2 text-[12px]"
                        style="color: var(--hab-muted)"
                    >
                        {{ Math.round(debtSheet.percent_paid * 100) }}% cleared
                        <span v-if="debtSheet.payoff_month !== null"
                            >· clears month {{ debtSheet.payoff_month }} at the
                            current pace</span
                        >
                        <span v-else style="color: var(--hab-clay)"
                            >· not falling at the current pace</span
                        >
                    </p>
                </div>

                <div class="p-6">
                    <div class="flex flex-wrap gap-6">
                        <div v-if="debtSheet.total_interest !== null">
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Still ahead
                            </p>
                            <p
                                class="m-0 mt-1 text-[19px] font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-apricot-ink);
                                "
                            >
                                {{ formatMoney(debtSheet.total_interest) }}
                            </p>
                        </div>
                        <div v-if="debtSheet.monthly_interest !== null">
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Interest a month
                            </p>
                            <p
                                class="m-0 mt-1 text-[19px] font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            >
                                {{ formatMoney(debtSheet.monthly_interest) }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="m-0 text-[11px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Paid this month
                            </p>
                            <p
                                class="m-0 mt-1 text-[19px] font-bold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-accent);
                                "
                            >
                                {{ formatMoney(debtSheet.paid_this_period) }}
                            </p>
                        </div>
                    </div>

                    <p
                        class="mt-5 mb-2 text-[11px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        Payments
                    </p>
                    <p
                        v-if="debtSheet.payments.length === 0"
                        class="m-0 text-[13px]"
                        style="color: var(--hab-muted)"
                    >
                        Nothing logged against this debt yet.
                    </p>
                    <div v-else class="flex flex-col">
                        <div
                            v-for="(payment, i) in debtSheet.payments"
                            :key="payment.id"
                            class="hab-anim-row-in flex items-center gap-3 py-[9px]"
                            :style="{
                                '--hab-delay': `${i * 0.05}s`,
                                borderTop:
                                    i > 0
                                        ? '1px solid var(--hab-hairline)'
                                        : 'none',
                            }"
                        >
                            <span
                                class="w-[54px] text-[12px]"
                                style="color: var(--hab-muted)"
                                >{{ payment.occurred_at }}</span
                            >
                            <span
                                class="flex-1 truncate text-[13px]"
                                style="color: var(--hab-ink)"
                                >{{ payment.description ?? 'Payment' }}</span
                            >
                            <span
                                class="text-[13.5px] font-semibold tabular-nums"
                                style="
                                    font-family: var(--hab-mono);
                                    color: var(--hab-accent);
                                "
                            >
                                {{ formatMoney(payment.amount) }}
                            </span>
                        </div>
                    </div>

                    <!-- Edit debt, inline -->
                    <div
                        v-if="editingId === debtSheet.id"
                        class="mt-5 rounded-[14px] p-4"
                        style="background: var(--hab-chip-fill)"
                    >
                        <div class="grid grid-cols-2 gap-2">
                            <input
                                v-model="debtForm.name"
                                type="text"
                                placeholder="Name"
                                class="col-span-2 rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            />
                            <MoneyInput
                                v-model="debtForm.balance"
                                placeholder="Balance"
                                :disabled="debtSheet.linked_account_id !== null"
                                class="rounded-[8px] border px-2 py-[7px] text-[12.5px] disabled:opacity-50"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            />
                            <MoneyInput
                                v-model="debtForm.monthly_rate"
                                placeholder="Monthly rate %"
                                class="rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                                unit="%"
                                :empty-value="null"
                            />
                            <MoneyInput
                                v-model="debtForm.minimum_payment"
                                placeholder="Minimum"
                                class="rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            />
                            <input
                                v-model.number="debtForm.due_day"
                                type="number"
                                min="1"
                                max="31"
                                placeholder="Due day"
                                class="rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            />
                            <select
                                v-model="debtForm.debt_type"
                                class="col-span-2 rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                            >
                                <option value="installment">
                                    Installment loan
                                </option>
                                <option value="revolving">
                                    Revolving credit
                                </option>
                            </select>
                        </div>

                        <button
                            v-if="
                                debtForm.debt_type === 'revolving' &&
                                debtForm.monthly_rate !== null
                            "
                            type="button"
                            class="mt-2 text-[12px] font-semibold"
                            style="color: var(--hab-accent)"
                            @click="calculateMinimumPayment"
                        >
                            Estimate the minimum from the rate
                        </button>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded-full px-3 py-[7px] text-[12px] font-semibold"
                                style="
                                    background: var(--hab-accent);
                                    color: var(--hab-btn-dark-ink);
                                "
                                :disabled="debtForm.processing"
                                @click="submitRow"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="rounded-full px-3 py-[7px] text-[12px] font-semibold"
                                style="color: var(--hab-muted)"
                                @click="cancelRow"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="ml-auto text-[12px] font-semibold"
                                style="color: var(--hab-clay)"
                                @click="removeDebt(debtSheet)"
                            >
                                Delete debt
                            </button>
                        </div>

                        <!-- Link to a ledger account -->
                        <div
                            class="mt-3 border-t pt-3"
                            style="border-color: var(--hab-card-border)"
                        >
                            <template
                                v-if="debtSheet.linked_account_id !== null"
                            >
                                <p
                                    class="m-0 text-[12px]"
                                    style="color: var(--hab-muted)"
                                >
                                    Balance syncs from
                                    <span class="font-semibold">{{
                                        debtSheet.linked_account_name
                                    }}</span
                                    >.
                                    <button
                                        type="button"
                                        class="font-semibold"
                                        style="color: var(--hab-accent)"
                                        @click="unlinkDebtAccount(debtSheet)"
                                    >
                                        Unlink
                                    </button>
                                </p>
                            </template>
                            <template
                                v-else-if="props.liabilityAccounts.length > 0"
                            >
                                <label
                                    class="mb-1 block text-[12px]"
                                    style="color: var(--hab-muted)"
                                    >Link to a ledger account</label
                                >
                                <select
                                    v-model.number="linkAccountId"
                                    class="w-full rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                    style="
                                        border-color: var(--hab-card-border);
                                        background: var(--hab-card-bg);
                                        color: var(--hab-ink);
                                    "
                                    @change="onLinkAccountChange(debtSheet)"
                                >
                                    <option value="">Not linked</option>
                                    <option
                                        v-for="account in props.liabilityAccounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name }} —
                                        {{ formatMoney(account.balance) }}
                                    </option>
                                </select>
                                <div
                                    v-if="linkChoicePending"
                                    class="mt-2 rounded-[10px] p-3"
                                    style="background: var(--hab-apricot-tint)"
                                >
                                    <p
                                        class="m-0 text-[12px]"
                                        style="color: var(--hab-apricot-ink)"
                                    >
                                        The balances don't match. Which one is
                                        right?
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-full px-3 py-[6px] text-[12px] font-semibold"
                                            style="
                                                background: var(--hab-apricot);
                                                color: #fff7ef;
                                            "
                                            @click="
                                                submitLink(
                                                    linkChoicePending.debtId,
                                                    linkChoicePending.accountId,
                                                    'debt',
                                                )
                                            "
                                        >
                                            Keep
                                            {{
                                                formatMoney(
                                                    linkChoicePending.debtBalance,
                                                )
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-full px-3 py-[6px] text-[12px] font-semibold"
                                            style="
                                                background: var(--hab-apricot);
                                                color: #fff7ef;
                                            "
                                            @click="
                                                submitLink(
                                                    linkChoicePending.debtId,
                                                    linkChoicePending.accountId,
                                                    'account',
                                                )
                                            "
                                        >
                                            Keep
                                            {{
                                                formatMoney(
                                                    linkChoicePending.accountBalance,
                                                )
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-[12px] font-semibold"
                                            style="color: var(--hab-muted)"
                                            @click="cancelLinkChoice"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <p
                            v-for="(error, field) in debtForm.errors"
                            :key="field"
                            class="m-0 mt-2 text-[12px]"
                            style="color: var(--hab-clay)"
                        >
                            {{ error }}
                        </p>
                    </div>

                    <!-- What this debt is mirrored by, and how to change it.
                         Kept out here rather than buried in Edit, because a card
                         that tracks a real account is how most debts behave. -->
                    <div
                        v-else
                        class="mt-5 rounded-[14px] p-[14px]"
                        style="background: var(--hab-chip-fill)"
                    >
                        <template v-if="debtSheet.linked_account_id !== null">
                            <div class="flex items-center gap-[10px]">
                                <span
                                    class="msf shrink-0"
                                    style="
                                        font-size: 17px;
                                        color: var(--hab-accent);
                                    "
                                    >link</span
                                >
                                <p
                                    class="m-0 flex-1 text-[12.5px]"
                                    style="color: var(--hab-ink-secondary)"
                                >
                                    Balance follows
                                    <span class="font-semibold">{{
                                        debtSheet.linked_account_name
                                    }}</span>
                                    in the Ledger — spending on it raises this
                                    debt, payments lower it.
                                </p>
                                <button
                                    type="button"
                                    class="shrink-0 text-[12px] font-semibold"
                                    style="color: var(--hab-muted)"
                                    @click="unlinkDebtAccount(debtSheet)"
                                >
                                    Unlink
                                </button>
                            </div>
                        </template>

                        <template
                            v-else-if="props.liabilityAccounts.length > 0"
                        >
                            <div class="flex items-center gap-[10px]">
                                <span
                                    class="msf shrink-0"
                                    style="
                                        font-size: 17px;
                                        color: var(--hab-faint);
                                    "
                                    >link_off</span
                                >
                                <label
                                    for="debt-link"
                                    class="m-0 flex-1 text-[12.5px]"
                                    style="color: var(--hab-ink-secondary)"
                                >
                                    Not linked to an account. Connect the card
                                    or loan it lives on and its balance keeps
                                    itself up to date.
                                </label>
                            </div>
                            <select
                                id="debt-link"
                                v-model.number="linkAccountId"
                                class="mt-[10px] w-full rounded-[8px] border px-2 py-[7px] text-[12.5px]"
                                style="
                                    border-color: var(--hab-card-border);
                                    background: var(--hab-card-bg);
                                    color: var(--hab-ink);
                                "
                                @change="onLinkAccountChange(debtSheet)"
                            >
                                <option value="">Pick an account…</option>
                                <option
                                    v-for="account in props.liabilityAccounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }} —
                                    {{ formatMoney(account.balance) }}
                                </option>
                            </select>

                            <div
                                v-if="linkChoicePending"
                                class="mt-2 rounded-[10px] p-3"
                                style="background: var(--hab-apricot-tint)"
                            >
                                <p
                                    class="m-0 text-[12px]"
                                    style="color: var(--hab-apricot-ink)"
                                >
                                    The balances don't match. Which one is
                                    right?
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-full px-3 py-[6px] text-[12px] font-semibold"
                                        style="
                                            background: var(--hab-apricot);
                                            color: #fff7ef;
                                        "
                                        @click="
                                            submitLink(
                                                linkChoicePending.debtId,
                                                linkChoicePending.accountId,
                                                'debt',
                                            )
                                        "
                                    >
                                        Keep
                                        {{
                                            formatMoney(
                                                linkChoicePending.debtBalance,
                                            )
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-full px-3 py-[6px] text-[12px] font-semibold"
                                        style="
                                            background: var(--hab-apricot);
                                            color: #fff7ef;
                                        "
                                        @click="
                                            submitLink(
                                                linkChoicePending.debtId,
                                                linkChoicePending.accountId,
                                                'account',
                                            )
                                        "
                                    >
                                        Keep
                                        {{
                                            formatMoney(
                                                linkChoicePending.accountBalance,
                                            )
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-[12px] font-semibold"
                                        style="color: var(--hab-muted)"
                                        @click="cancelLinkChoice"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </template>

                        <p
                            v-else
                            class="m-0 text-[12.5px]"
                            style="color: var(--hab-muted)"
                        >
                            To have this track a real card, add it as a
                            liability account in the
                            <a href="/ledger" class="font-semibold underline"
                                >Ledger</a
                            >
                            first.
                        </p>
                    </div>

                    <div
                        v-if="editingId !== debtSheet.id"
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <button
                            type="button"
                            class="rounded-[10px] border px-3 py-[9px] text-[12.5px] font-semibold"
                            style="
                                border-color: var(--hab-card-border);
                                color: var(--hab-ink-secondary);
                            "
                            @click="openEditRow(debtSheet)"
                        >
                            Edit debt
                        </button>
                        <button
                            type="button"
                            class="rounded-[10px] border px-3 py-[9px] text-[12.5px] font-semibold"
                            style="
                                border-color: var(--hab-card-border);
                                color: var(--hab-ink-secondary);
                            "
                            @click="makeTarget(debtSheet)"
                        >
                            {{
                                debtSheet.is_pinned
                                    ? 'Unpin target'
                                    : 'Change target'
                            }}
                        </button>
                        <button
                            v-if="debtSheet.balance > 0"
                            type="button"
                            class="rounded-[10px] px-3 py-[9px] text-[12.5px] font-semibold"
                            style="
                                background: var(--hab-accent);
                                color: var(--hab-btn-dark-ink);
                            "
                            @click="openExtraSheet(debtSheet)"
                        >
                            Log a payment
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 15e · Add a debt ───────────────────────────────────────── -->
        <div
            v-if="editingId === 'new'"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-5"
            style="background: rgba(28, 40, 28, 0.42)"
            @click.self="cancelRow"
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
                    class="px-[22px] pt-5 pb-4"
                    style="border-bottom: 1px solid var(--hab-hairline)"
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <h3
                                class="m-0 font-['Bricolage_Grotesque'] text-[21px] font-bold"
                                style="color: var(--hab-ink)"
                            >
                                Add a debt
                            </h3>
                            <p
                                class="m-0 mt-1 text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                It joins the queue in the right place on its
                                own.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="material-symbols-rounded"
                            style="font-size: 20px; color: var(--hab-faint)"
                            @click="cancelRow"
                        >
                            close
                        </button>
                    </div>
                </div>

                <div class="px-[22px] py-[18px]">
                    <p
                        class="m-0 mb-[9px] text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        What kind
                    </p>
                    <div class="grid grid-cols-4 gap-[7px]">
                        <button
                            v-for="choice in DEBT_KINDS"
                            :key="choice.kind"
                            type="button"
                            class="rounded-[12px] px-[6px] py-3 text-center"
                            :class="
                                debtForm.kind === choice.kind
                                    ? 'hab-anim-pop'
                                    : ''
                            "
                            :style="{
                                background:
                                    debtForm.kind === choice.kind
                                        ? 'var(--hab-apricot-tint)'
                                        : 'var(--hab-panel)',
                                border:
                                    debtForm.kind === choice.kind
                                        ? '1.5px solid var(--hab-apricot)'
                                        : '1px solid var(--hab-card-border)',
                            }"
                            @click="chooseKind(choice.kind)"
                        >
                            <span
                                :class="
                                    debtForm.kind === choice.kind
                                        ? 'msf'
                                        : 'material-symbols-rounded'
                                "
                                style="font-size: 19px"
                                :style="{
                                    color:
                                        debtForm.kind === choice.kind
                                            ? 'var(--hab-apricot)'
                                            : 'var(--hab-faint)',
                                }"
                            >
                                {{ choice.icon }}
                            </span>
                            <p
                                class="m-0 mt-[5px] text-[11px]"
                                :style="{
                                    fontWeight:
                                        debtForm.kind === choice.kind
                                            ? 700
                                            : 600,
                                    color:
                                        debtForm.kind === choice.kind
                                            ? '#8c5a24'
                                            : 'var(--hab-muted)',
                                }"
                            >
                                {{ choice.label }}
                            </p>
                        </button>
                    </div>

                    <div class="mt-[18px]">
                        <p
                            class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                            style="color: var(--hab-faint)"
                        >
                            Tracked in the Ledger
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
                                class="material-symbols-rounded"
                                style="font-size: 18px; color: var(--hab-faint)"
                                >{{
                                    debtForm.linked_account_id === null
                                        ? 'link_off'
                                        : 'link'
                                }}</span
                            >
                            <select
                                v-model.number="debtForm.linked_account_id"
                                class="w-full min-w-0 flex-1 border-none bg-transparent p-0 text-[13.5px] outline-none"
                                style="color: var(--hab-ink)"
                                @change="onNewDebtAccountChange"
                            >
                                <option :value="null">
                                    Not tracked — I'll enter the balance myself
                                </option>
                                <option
                                    v-for="account in linkableAccounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }} —
                                    {{ formatMoney(account.balance) }}
                                </option>
                            </select>
                        </div>
                        <p
                            v-if="linkedAccountForNewDebt"
                            class="m-0 mt-2 text-[11.5px]"
                            style="color: var(--hab-muted)"
                        >
                            Its balance will follow
                            {{ linkedAccountForNewDebt.name }}: spending on the
                            card raises this debt, payments lower it.<template
                                v-if="
                                    Math.abs(
                                        linkedAccountForNewDebt.balance -
                                            debtForm.balance,
                                    ) >= 0.01
                                "
                            >
                                <span
                                    class="font-semibold"
                                    style="color: var(--hab-apricot-ink)"
                                >
                                    {{ linkedAccountForNewDebt.name }} will be
                                    corrected to
                                    {{ formatMoney(debtForm.balance) }}.</span
                                ></template
                            >
                        </p>
                        <p
                            v-else-if="linkableAccounts.length === 0"
                            class="m-0 mt-2 text-[11.5px]"
                            style="color: var(--hab-muted)"
                        >
                            No spare liability accounts. Add the card in the
                            <a href="/ledger" class="font-semibold underline"
                                >Ledger</a
                            >
                            to have this track it.
                        </p>
                    </div>

                    <p
                        class="m-0 mt-[18px] mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                        style="color: var(--hab-faint)"
                    >
                        Who it's with
                    </p>
                    <input
                        v-model="debtForm.name"
                        type="text"
                        :placeholder="kindPlaceholder"
                        class="w-full rounded-[12px] px-[14px] py-3 text-[14px] font-semibold"
                        style="
                            background: var(--hab-panel);
                            border: 1px solid var(--hab-card-border);
                            color: var(--hab-ink);
                        "
                    />

                    <div class="mt-[18px] flex gap-[10px]">
                        <div class="flex-1">
                            <p
                                class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Owed now
                            </p>
                            <div
                                class="flex items-baseline gap-[2px] rounded-[12px] px-[14px] py-[11px]"
                                style="
                                    background: var(--hab-card-bg);
                                    border: 1.5px solid var(--hab-accent);
                                "
                            >
                                <span
                                    class="text-[16px]"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-faint);
                                    "
                                    >₱</span
                                >
                                <MoneyInput
                                    v-model="debtForm.balance"
                                    unit=""
                                    class="w-full min-w-0 flex-1 border-none bg-transparent p-0 text-[19px] font-extrabold tabular-nums outline-none"
                                    style="
                                        font-family: var(--hab-mono);
                                        color: var(--hab-ink);
                                    "
                                />
                            </div>
                        </div>
                        <div style="width: 132px">
                            <p
                                class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Rate / mo
                            </p>
                            <MoneyInput
                                v-model="debtForm.monthly_rate"
                                unit="%"
                                :empty-value="null"
                                placeholder="—"
                                class="w-full rounded-[12px] px-[14px] py-[11px] text-[19px] font-extrabold tabular-nums"
                                style="
                                    background: var(--hab-panel);
                                    border: 1px solid var(--hab-card-border);
                                    font-family: var(--hab-mono);
                                    color: var(--hab-apricot-ink);
                                "
                            />
                        </div>
                    </div>

                    <div class="mt-[14px] flex gap-[10px]">
                        <div class="flex-1">
                            <p
                                class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Minimum
                            </p>
                            <MoneyInput
                                v-model="debtForm.minimum_payment"
                                class="w-full rounded-[12px] px-[14px] py-[11px] text-[19px] font-extrabold tabular-nums"
                                style="
                                    background: var(--hab-panel);
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                                :style="{
                                    border: minimumIsEstimated
                                        ? '1px dashed var(--hab-accent)'
                                        : '1px solid var(--hab-card-border)',
                                }"
                            />
                            <p
                                v-if="minimumIsEstimated"
                                class="m-0 mt-[6px] text-[11px]"
                                style="color: var(--hab-accent)"
                            >
                                Estimated from the rate — enough to cover the
                                interest and start clearing it. Type the card's
                                real minimum over it.
                            </p>
                        </div>
                        <div style="width: 132px">
                            <p
                                class="m-0 mb-2 text-[11.5px] font-semibold tracking-[.12em] uppercase"
                                style="color: var(--hab-faint)"
                            >
                                Due day
                            </p>
                            <input
                                v-model.number="debtForm.due_day"
                                type="number"
                                min="1"
                                max="31"
                                placeholder="—"
                                class="w-full rounded-[12px] px-[14px] py-[11px] text-[19px] font-extrabold tabular-nums"
                                style="
                                    background: var(--hab-panel);
                                    border: 1px solid var(--hab-card-border);
                                    font-family: var(--hab-mono);
                                    color: var(--hab-ink);
                                "
                            />
                        </div>
                    </div>

                    <!-- The horizon, redrawn as the fields land -->
                    <div
                        v-if="previewReady"
                        class="mt-5 rounded-[16px] p-4"
                        style="
                            background: var(--hab-panel);
                            border: 1px solid var(--hab-card-border);
                        "
                    >
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <p
                                    class="m-0 text-[11px] font-semibold tracking-[.1em] uppercase"
                                    style="color: var(--hab-faint)"
                                >
                                    {{
                                        preview?.debtFreeDate
                                            ? 'Debt-free date moves to'
                                            : 'With this added'
                                    }}
                                </p>
                                <p
                                    class="m-0 mt-[6px] font-['Bricolage_Grotesque'] text-[24px] leading-none font-bold"
                                    :style="{
                                        color: preview?.debtFreeDate
                                            ? 'var(--hab-ink)'
                                            : 'var(--hab-clay)',
                                    }"
                                >
                                    {{
                                        preview?.debtFreeDate ??
                                        'the plan never finishes'
                                    }}
                                </p>
                            </div>
                            <p
                                v-if="
                                    preview?.monthsAdded !== null &&
                                    preview?.monthsAdded !== undefined
                                "
                                class="hab-anim-pop m-0 rounded-full px-[10px] py-[5px] text-[11.5px] font-bold whitespace-nowrap"
                                :style="{
                                    background:
                                        preview.monthsAdded > 0
                                            ? 'var(--hab-apricot-tint)'
                                            : 'var(--hab-chip-fill)',
                                    color:
                                        preview.monthsAdded > 0
                                            ? 'var(--hab-apricot-ink)'
                                            : 'var(--hab-accent)',
                                }"
                            >
                                {{
                                    preview.monthsAdded > 0
                                        ? `+${pluralMonths(preview.monthsAdded)}`
                                        : 'no months added'
                                }}
                            </p>
                        </div>

                        <div
                            v-if="preview?.rail"
                            class="mt-[13px] flex items-end gap-[2px]"
                            style="height: 44px"
                        >
                            <div
                                v-for="(bar, i) in preview.rail"
                                :key="i"
                                class="flex-1 rounded-t-[2px]"
                                :class="bar.added ? 'hab-anim-rise' : ''"
                                :style="{
                                    height: previewBarHeight(bar),
                                    background: bar.added
                                        ? 'var(--hab-apricot)'
                                        : 'var(--hab-accent)',
                                    opacity: bar.added ? 1 : 0.85,
                                    '--hab-delay': `${Math.min(i * 0.03, 0.5)}s`,
                                }"
                            ></div>
                        </div>

                        <p
                            class="m-0 mt-[9px] text-[11.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ previewCopy }}
                        </p>
                    </div>

                    <p
                        v-for="(error, field) in debtForm.errors"
                        :key="field"
                        class="m-0 mt-2 text-[12px]"
                        style="color: var(--hab-clay)"
                    >
                        {{ error }}
                    </p>

                    <div class="mt-[18px] flex gap-2">
                        <button
                            type="button"
                            class="h-11 rounded-[11px] text-[13px] font-semibold"
                            style="
                                width: 112px;
                                background: var(--hab-card-bg);
                                border: 1px solid var(--hab-card-border);
                                color: var(--hab-ink-secondary);
                            "
                            @click="cancelRow"
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
                            :disabled="debtForm.processing"
                            @click="submitRow"
                        >
                            Add to the plan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
