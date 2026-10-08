<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import {
    destroy as destroyCategory,
    store as storeCategory,
} from '@/actions/App/Http/Controllers/Money/CustomCategoryController';
import { store } from '@/actions/App/Http/Controllers/Money/TransactionsController';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import InfoNote from '@/components/InfoNote.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import StatementImportDialog from '@/components/StatementImportDialog.vue';
import TransactionDialog from '@/components/TransactionDialog.vue';
import type { TransactionRow } from '@/components/TransactionDialog.vue';
import { transactions as transactionsRoute } from '@/routes/money';
import { sheetWidth } from '@/utils/blueprint';
import {
    categoryLabel,
    formatDay,
    formatPeso,
    toPesos,
    transactionCategories,
} from '@/utils/money';

type CardGuard = {
    debtId: number;
    name: string;
    revolving: number;
    monthlyRate: number;
    suggestedAccount: { id: number; name: string; balance: number } | null;
};

const props = defineProps<{
    month: string;
    all: boolean;
    accountFilter: number | null;
    currentMonth: string;
    today: string;
    transactions: TransactionRow[];
    debtPayments: {
        id: number;
        date: string;
        amount: number;
        debtName: string;
        paidWith: string | null;
    }[];
    categoryTotals: { category: string; total: number }[];
    total: number;
    received: number;
    incomeSources: { id: number; name: string; amount: number }[];
    prefill: {
        incomeSourceId: number | null;
        billId: number | null;
        name: string;
        amount: number;
        date: string;
    } | null;
    accounts: {
        id: number;
        name: string;
        balance: number;
        isSpendable: boolean;
    }[];
    bills: {
        id: number;
        name: string;
        amount: number;
        accountId: number | null;
    }[];
    cards: { id: number; name: string; guard: CardGuard | null }[];
    customCategories: { id: number; name: string }[];
    lastPaidWith: { accountId: number | null; debtId: number | null } | null;
}>();

/** An entry is spending or money received. Income goes into an account. */
const kind = ref<'expense' | 'income' | 'transfer'>(
    props.prefill?.incomeSourceId ? 'income' : 'expense',
);
const incomeSourceId = ref<number | ''>(props.prefill?.incomeSourceId ?? '');
const payDate = ref<string | null>(props.prefill?.date ?? null);

function defaultPaidWith(): string {
    const billAccountId = props.bills.find(
        (bill) => bill.id === props.prefill?.billId,
    )?.accountId;

    if (billAccountId) {
        return `account:${billAccountId}`;
    }

    if (props.lastPaidWith?.accountId) {
        return `account:${props.lastPaidWith.accountId}`;
    }

    if (props.lastPaidWith?.debtId) {
        return `card:${props.lastPaidWith.debtId}`;
    }

    if (props.accounts[0]) {
        return `account:${props.accounts[0].id}`;
    }

    return props.cards[0] ? `card:${props.cards[0].id}` : '';
}

const form = useForm({
    amount: (props.prefill ? toPesos(props.prefill.amount) : null) as
        number | null,
    category: '',
    custom_category_id: null as number | null,
    paidWith: defaultPaidWith(),
    bill: props.prefill?.billId ? String(props.prefill.billId) : '',
    toAccount: '',
    date: props.prefill?.date ?? props.today,
    note: props.prefill?.name ?? '',
});

/** Picking a bill fills in what it costs and the account it is paid from. */
function chooseBill(): void {
    const bill = props.bills.find((item) => String(item.id) === form.bill);

    if (!bill) {
        return;
    }

    if (form.amount === null) {
        form.amount = toPesos(bill.amount);
    }

    if (bill.accountId) {
        form.paidWith = `account:${bill.accountId}`;
    }

    form.note ||= bill.name;
}

function chooseCategory(value: string): void {
    form.category = value;
    form.custom_category_id = null;
}

function chooseCustomCategory(id: number): void {
    form.category = 'other';
    form.custom_category_id = id;
}

const addingCategory = ref(false);
const categoryForm = useForm({ name: '' });

function addCategory(): void {
    categoryForm.post(storeCategory().url, {
        preserveScroll: true,
        onSuccess: () => {
            const name = categoryForm.name.trim().toLowerCase();
            const added = props.customCategories.find(
                (item) => item.name.toLowerCase() === name,
            );

            if (added) {
                chooseCustomCategory(added.id);
            }

            categoryForm.reset();
            addingCategory.value = false;
        },
    });
}

function removeCategory(id: number): void {
    if (form.custom_category_id === id) {
        chooseCategory('');
    }

    router.delete(destroyCategory(id).url, { preserveScroll: true });
}

function chooseKind(next: 'expense' | 'income' | 'transfer'): void {
    kind.value = next;

    if (next === 'transfer') {
        const from = props.accounts.find(
            (account) => form.paidWith === `account:${account.id}`,
        );
        const to = props.accounts.find((account) => account.id !== from?.id);

        form.paidWith = `account:${(from ?? props.accounts[0])?.id}`;
        form.toAccount = to ? `account:${to.id}` : '';
    }

    if (
        next === 'income' &&
        !form.paidWith.startsWith('account:') &&
        props.accounts[0]
    ) {
        form.paidWith = `account:${props.accounts[0].id}`;
    }
}

/** Picking an income source fills in what it usually pays, unless something is typed already. */
function chooseSource(): void {
    const source = props.incomeSources.find(
        (item) => item.id === incomeSourceId.value,
    );

    payDate.value = null;

    if (source) {
        form.amount ??= toPesos(source.amount);
        form.note ||= source.name;
    }
}

const hasMethods = computed(() => {
    if (kind.value === 'transfer') {
        return props.accounts.length > 1;
    }

    return kind.value === 'income'
        ? props.accounts.length > 0
        : props.accounts.length > 0 || props.cards.length > 0;
});

/** Spending on a card that already carries a balance adds to what is charging interest. */
const guard = computed(
    () =>
        (kind.value === 'expense' &&
            props.cards.find((card) => `card:${card.id}` === form.paidWith)
                ?.guard) ??
        null,
);

const amountBox = ref<HTMLElement | null>(null);

function focusAmount(): void {
    amountBox.value?.querySelector('input')?.focus();
}

onMounted(focusAmount);

function submit(): void {
    form.transform(({ paidWith, toAccount, category, bill, ...data }) => {
        const account = paidWith.startsWith('account:')
            ? Number(paidWith.slice(8))
            : null;

        if (kind.value === 'transfer') {
            return {
                ...data,
                kind: 'transfer',
                note: data.note || null,
                account_id: account,
                to_account_id: toAccount.startsWith('account:')
                    ? Number(toAccount.slice(8))
                    : null,
            };
        }

        if (kind.value === 'income') {
            return {
                ...data,
                kind: 'income',
                note: data.note || null,
                account_id: account,
                income_source_id: incomeSourceId.value || null,
                pay_date: payDate.value,
            };
        }

        return {
            ...data,
            category,
            bill_id: bill ? Number(bill) : null,
            note: data.note || null,
            account_id: account,
            debt_id: paidWith.startsWith('card:')
                ? Number(paidWith.slice(5))
                : null,
        };
    }).post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset(
                'amount',
                'note',
                'category',
                'custom_category_id',
                'bill',
            );

            if (kind.value === 'transfer') {
                form.reset('toAccount');
            }

            incomeSourceId.value = '';
            payDate.value = null;
            focusAmount();
        },
    });
}

const title = computed(() => {
    if (props.all) {
        return 'All time';
    }

    const [year, month] = props.month.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    });
});

function shiftMonth(delta: number): string {
    const [year, month] = props.month.split('-').map(Number);
    const next = new Date(year, month - 1 + delta, 1);

    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
}

function goTo(
    month: string,
    account: number | null = props.accountFilter,
): void {
    router.get(
        transactionsRoute({
            query: { month, ...(account ? { account } : {}) },
        }).url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

type DayItem =
    | { type: 'transaction'; key: string; row: TransactionRow }
    | {
          type: 'payment';
          key: string;
          row: (typeof props.debtPayments)[number];
      };

/** Debt payments sit among the day's entries for reference; they are not counted in the totals above. */
const days = computed(() => {
    const groups = new Map<string, DayItem[]>();
    const add = (date: string, item: DayItem): void => {
        groups.set(date, [...(groups.get(date) ?? []), item]);
    };

    for (const row of props.transactions) {
        add(row.date, { type: 'transaction', key: `t${row.id}`, row });
    }

    for (const row of props.debtPayments) {
        add(row.date, { type: 'payment', key: `p${row.id}`, row });
    }

    return [...groups.entries()]
        .sort(([a], [b]) => b.localeCompare(a))
        .map(([date, items]) => ({ date, items }));
});

const importOpen = ref(false);
const editing = ref<TransactionRow | null>(null);
const editOpen = ref(false);

function edit(transaction: TransactionRow): void {
    editing.value = transaction;
    editOpen.value = true;
}

/** The server reports a missing or invalid payer under the column it checks. */
const methodError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.account_id ?? errors.debt_id;
});
const billError = computed(
    () => (form.errors as Record<string, string | undefined>).bill_id,
);
const toAccountError = computed(
    () => (form.errors as Record<string, string | undefined>).to_account_id,
);

const kindOptions = [
    { value: 'expense', label: 'Expense' },
    { value: 'income', label: 'Income' },
    { value: 'transfer', label: 'Transfer' },
] as const;

/** Accounts and, for spending, cards: the rows of the "paid with" picker. */
const payers = computed(() => [
    ...props.accounts.map((account) => ({
        value: `account:${account.id}`,
        name: account.name,
        detail: formatPeso(account.balance),
    })),
    ...(kind.value === 'expense'
        ? props.cards.map((card) => ({
              value: `card:${card.id}`,
              name: card.name,
              detail: 'card',
          }))
        : []),
]);

const categoryIcons: Record<string, string> = {
    food: 'restaurant',
    groceries: 'shopping_cart',
    transport: 'directions_car',
    shopping: 'shopping_bag',
    health: 'medication',
    fun: 'movie',
    home: 'home',
    other: 'sell',
};

function entryIcon(row: TransactionRow): string {
    if (row.kind === 'income') {
        return 'payments';
    }

    if (row.kind === 'transfer') {
        return 'swap_horiz';
    }

    return row.billId
        ? 'receipt_long'
        : (categoryIcons[row.category] ?? 'sell');
}

function entryName(row: TransactionRow): string {
    return (
        row.note ||
        (row.kind === 'income'
            ? 'Income'
            : (row.categoryName ?? categoryLabel(row.category)))
    );
}

function entryMeta(row: TransactionRow): string {
    if (row.kind === 'income') {
        return `Income · into ${row.paidWith}`;
    }

    if (row.kind === 'transfer') {
        return `Transfer · ${row.paidWith} → ${row.toAccountName}`;
    }

    const parts = [
        row.categoryName ?? categoryLabel(row.category),
        row.paidWith,
    ];

    if (row.billName) {
        parts.push(`bill: ${row.billName}`);
    }

    return parts.filter(Boolean).join(' · ');
}

/** What a day spent: expenses only, the same as Spent at the top. */
function daySpent(items: DayItem[]): number {
    return items.reduce(
        (sum, item) =>
            item.type === 'transaction' && item.row.kind === 'expense'
                ? sum + item.row.amount
                : sum,
        0,
    );
}

function dayParts(date: string): { day: string; weekday: string } {
    const [year, month, day] = date.split('-').map(Number);

    return {
        day: String(day).padStart(2, '0'),
        weekday: new Date(year, month - 1, day)
            .toLocaleDateString('en-US', { weekday: 'short' })
            .toUpperCase(),
    };
}

const entryCount = computed(
    () => props.transactions.length + props.debtPayments.length,
);

/** "OCT 01 — OCT 06 · 14 entries": the month so far, or all of a past month. */
const dimension = computed(() => {
    if (props.all) {
        return `ALL TIME · ${entryCount.value} ${entryCount.value === 1 ? 'entry' : 'entries'}`;
    }

    const [year, month] = props.month.split('-').map(Number);
    const monthShort = new Date(year, month - 1, 1)
        .toLocaleDateString('en-US', { month: 'short' })
        .toUpperCase();
    const lastDay =
        props.month === props.currentMonth
            ? Number(props.today.slice(8, 10))
            : new Date(year, month, 0).getDate();

    return `${monthShort} 01 — ${monthShort} ${String(lastDay).padStart(2, '0')} · ${entryCount.value} ${entryCount.value === 1 ? 'entry' : 'entries'}`;
});

/** Category bars, largest first, shaded from the accent down. */
const categoryBars = computed(() => {
    const sorted = [...props.categoryTotals].sort((a, b) => b.total - a.total);
    const largest = sorted[0]?.total ?? 0;
    const shades = [100, 78, 60, 45, 32, 22, 14];

    return sorted.map((item, index) => ({
        ...item,
        width: largest > 0 ? (item.total / largest) * 100 : 0,
        fill: `color-mix(in oklch, var(--bp-accent) ${shades[Math.min(index, shades.length - 1)]}%, var(--bp-card))`,
    }));
});

const rowClass =
    'flex items-center justify-between gap-3 border-b border-[var(--bp-hair)] pb-2 text-[13.5px]';
const selectClass =
    'min-w-0 bg-transparent text-right font-medium outline-none';
const errorClass = 'text-[12px] text-[#a3311f]';
const chipClass = 'rounded-full border px-2.5 py-[3px] text-[12.5px]';
const chipOn =
    'border-[var(--bp-ink)] bg-[var(--bp-ink)] font-semibold text-[var(--bp-card)]';
const chipOff =
    'border-[var(--bp-line)] text-[var(--bp-ink-2)] hover:border-[var(--bp-ink)]';
</script>

<template>
    <Head title="Transactions" />

    <Teleport defer to="#page-actions">
        <!-- Always an element here, so the default "New entry" stays hidden: the blueprint has only the import. -->
        <span class="contents">
            <button
                v-if="cards.length > 0"
                type="button"
                class="bp flex items-center gap-1.5 rounded border border-[var(--bp-line)] bg-[var(--bp-card)] px-3 py-1.5 text-[13px] font-medium hover:border-[var(--bp-ink)]"
                @click="importOpen = true"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >upload_file</span
                >
                <span class="hidden sm:inline">Import statement</span>
            </button>
        </span>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            icon="receipt_long"
            code="M-04"
            section="Money"
            title="Transactions"
            subtitle="Log what you spend and what you receive. Everything else works without it."
            :dimension="dimension"
        />

        <div
            :class="sheetWidth.wide"
            class="grid grid-cols-1 items-start gap-10 lg:grid-cols-[minmax(0,1fr)_340px]"
        >
            <!-- Entry slip first on phones, pinned right on wide screens -->
            <div
                class="flex flex-col gap-3.5 lg:sticky lg:top-[76px] lg:order-2"
            >
                <div
                    class="flex items-center justify-between border border-[var(--bp-ink)] bg-[var(--bp-card)] px-2.5 py-2"
                >
                    <button
                        type="button"
                        class="flex h-[26px] w-[26px] items-center justify-center rounded-[3px] border border-[var(--bp-line)] enabled:hover:border-[var(--bp-ink)] disabled:border-[var(--bp-hair)] disabled:text-[var(--bp-fainter)]"
                        aria-label="Previous month"
                        :disabled="all"
                        @click="goTo(shiftMonth(-1))"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >chevron_left</span
                        >
                    </button>
                    <span class="flex flex-col items-center">
                        <span
                            class="bp-mono text-[9.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                            >MONTH</span
                        >
                        <span class="text-[14.5px] font-semibold">{{
                            title
                        }}</span>
                        <button
                            v-if="month !== currentMonth || all"
                            type="button"
                            class="text-[11.5px] text-[var(--bp-muted)] underline underline-offset-2 hover:text-[var(--bp-ink)]"
                            @click="goTo(currentMonth)"
                        >
                            Back to this month
                        </button>
                        <button
                            v-if="!all"
                            type="button"
                            class="text-[11.5px] text-[var(--bp-muted)] underline underline-offset-2 hover:text-[var(--bp-ink)]"
                            @click="goTo('all')"
                        >
                            View all
                        </button>
                    </span>
                    <button
                        type="button"
                        class="flex h-[26px] w-[26px] items-center justify-center rounded-[3px] border border-[var(--bp-line)] enabled:hover:border-[var(--bp-ink)] disabled:border-[var(--bp-hair)] disabled:text-[var(--bp-fainter)]"
                        aria-label="Next month"
                        :disabled="all || month >= currentMonth"
                        @click="goTo(shiftMonth(1))"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >chevron_right</span
                        >
                    </button>
                </div>

                <form
                    class="border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                    aria-label="New entry"
                    @submit.prevent="submit"
                >
                    <div
                        class="bp-mono flex items-center justify-between border-b border-[var(--bp-ink)] px-4 py-2.5 text-[10.5px] tracking-[0.12em]"
                    >
                        <span>NEW ENTRY</span>
                        <span class="text-[var(--bp-faint)]">{{
                            kind.toUpperCase()
                        }}</span>
                    </div>
                    <div
                        class="grid grid-cols-3 border-b border-[var(--bp-line)]"
                        role="radiogroup"
                        aria-label="Entry type"
                    >
                        <button
                            v-for="option in kindOptions"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="kind === option.value"
                            class="py-[9px] text-center text-[13px]"
                            :class="
                                kind === option.value
                                    ? 'font-semibold text-[var(--bp-accent)] shadow-[inset_0_-2px_0_var(--bp-accent)]'
                                    : 'text-[var(--bp-muted)] hover:text-[var(--bp-ink)]'
                            "
                            @click="chooseKind(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <p
                        v-if="!hasMethods"
                        class="px-4 py-[18px] text-[13px] text-[var(--bp-muted)]"
                    >
                        {{
                            kind === 'transfer'
                                ? 'Add a second account on the Overview to move money between accounts.'
                                : kind === 'income'
                                  ? 'Add an account on the Overview to record income.'
                                  : 'Add an account on the Overview, or a card on Debts, to log what you spend.'
                        }}
                    </p>
                    <div v-else class="flex flex-col gap-4 px-4 py-[18px]">
                        <div ref="amountBox">
                            <label class="sr-only" for="entry-amount"
                                >Amount</label
                            >
                            <div
                                class="flex items-baseline gap-1.5 border-b-2 border-[var(--bp-ink)] pb-1"
                            >
                                <span class="text-[22px] text-[var(--bp-faint)]"
                                    >₱</span
                                >
                                <MoneyInput
                                    id="entry-amount"
                                    v-model="form.amount"
                                    :empty-value="null"
                                    placeholder="0.00"
                                    class="bp-mono w-full min-w-0 bg-transparent text-[28px] font-medium outline-none placeholder:text-[var(--bp-fainter)]"
                                />
                            </div>
                            <p
                                v-if="form.errors.amount"
                                :class="errorClass"
                                class="mt-1"
                            >
                                {{ form.errors.amount }}
                            </p>
                        </div>

                        <fieldset class="flex flex-col gap-1.5">
                            <legend class="bp-caption mb-1.5">
                                {{
                                    kind === 'transfer'
                                        ? 'From'
                                        : kind === 'income'
                                          ? 'Paid into'
                                          : 'Paid with'
                                }}
                            </legend>
                            <div
                                class="flex max-h-[232px] flex-col overflow-y-auto border-t border-[var(--bp-hair)]"
                            >
                                <label
                                    v-for="payer in payers"
                                    :key="payer.value"
                                    class="grid cursor-pointer grid-cols-[20px_minmax(0,1fr)_auto] items-center gap-2 border-b border-[var(--bp-hair)] py-[7px] text-[13.5px]"
                                >
                                    <input
                                        v-model="form.paidWith"
                                        type="radio"
                                        name="paid-with"
                                        :value="payer.value"
                                        class="sr-only"
                                    />
                                    <span
                                        :class="
                                            form.paidWith === payer.value
                                                ? 'msf text-[var(--bp-accent)]'
                                                : 'ms text-[var(--bp-faint)]'
                                        "
                                        style="font-size: 17px"
                                        aria-hidden="true"
                                        >{{
                                            form.paidWith === payer.value
                                                ? 'radio_button_checked'
                                                : 'radio_button_unchecked'
                                        }}</span
                                    >
                                    <span
                                        class="truncate"
                                        :class="{
                                            'font-semibold':
                                                form.paidWith === payer.value,
                                        }"
                                        >{{ payer.name }}</span
                                    >
                                    <span
                                        class="bp-mono text-[11.5px]"
                                        :class="
                                            form.paidWith === payer.value
                                                ? 'text-[var(--bp-muted)]'
                                                : 'text-[var(--bp-faint)]'
                                        "
                                        >{{ payer.detail }}</span
                                    >
                                </label>
                            </div>
                            <p v-if="methodError" :class="errorClass">
                                {{ methodError }}
                            </p>
                        </fieldset>

                        <div
                            v-if="guard"
                            class="border border-[var(--bp-ink)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.055)_0_1px,transparent_1px_8px)] px-3 py-2.5 text-[12.5px] leading-normal text-pretty"
                            role="status"
                        >
                            {{ guard.name }} carries
                            <span class="bp-mono">{{
                                formatPeso(guard.revolving)
                            }}</span>
                            at {{ guard.monthlyRate }}% a month. A new charge
                            adds to it.
                            <button
                                v-if="guard.suggestedAccount"
                                type="button"
                                class="font-semibold underline underline-offset-[3px]"
                                @click="
                                    form.paidWith = `account:${guard.suggestedAccount.id}`
                                "
                            >
                                Use {{ guard.suggestedAccount.name }}
                            </button>
                            <span
                                v-if="guard.suggestedAccount"
                                class="text-[var(--bp-muted)]"
                            >
                                ({{
                                    formatPeso(guard.suggestedAccount.balance)
                                }}
                                available)</span
                            >
                        </div>

                        <div v-if="kind === 'transfer'">
                            <label :class="rowClass">
                                <span class="shrink-0 text-[var(--bp-muted)]"
                                    >To</span
                                >
                                <select
                                    v-model="form.toAccount"
                                    :class="selectClass"
                                >
                                    <option value="" disabled>
                                        Choose an account
                                    </option>
                                    <option
                                        v-for="account in accounts"
                                        :key="`to${account.id}`"
                                        :value="`account:${account.id}`"
                                        :disabled="
                                            form.paidWith ===
                                            `account:${account.id}`
                                        "
                                    >
                                        {{ account.name }}
                                    </option>
                                </select>
                            </label>
                            <p
                                v-if="toAccountError"
                                :class="errorClass"
                                class="mt-1"
                            >
                                {{ toAccountError }}
                            </p>
                        </div>

                        <label
                            v-if="kind === 'income' && incomeSources.length"
                            :class="rowClass"
                        >
                            <span class="shrink-0 text-[var(--bp-muted)]"
                                >From</span
                            >
                            <select
                                v-model="incomeSourceId"
                                :class="selectClass"
                                @change="chooseSource"
                            >
                                <option value="">Something else</option>
                                <option
                                    v-for="source in incomeSources"
                                    :key="source.id"
                                    :value="source.id"
                                >
                                    {{ source.name }}
                                </option>
                            </select>
                        </label>

                        <template v-if="kind === 'expense'">
                            <div v-if="bills.length">
                                <label :class="rowClass">
                                    <span
                                        class="shrink-0 text-[var(--bp-muted)]"
                                        >Pays a bill</span
                                    >
                                    <select
                                        v-model="form.bill"
                                        :class="selectClass"
                                        @change="chooseBill"
                                    >
                                        <option value="">Not a bill</option>
                                        <option
                                            v-for="bill in bills"
                                            :key="bill.id"
                                            :value="String(bill.id)"
                                        >
                                            {{ bill.name }}
                                        </option>
                                    </select>
                                </label>
                                <p
                                    v-if="billError"
                                    :class="errorClass"
                                    class="mt-1"
                                >
                                    {{ billError }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <span class="bp-caption" id="category-label"
                                    >Category</span
                                >
                                <div
                                    class="flex flex-wrap gap-[5px]"
                                    role="radiogroup"
                                    aria-labelledby="category-label"
                                >
                                    <button
                                        v-for="category in transactionCategories"
                                        :key="category.value"
                                        type="button"
                                        role="radio"
                                        :aria-checked="
                                            form.category === category.value &&
                                            form.custom_category_id === null
                                        "
                                        :class="[
                                            chipClass,
                                            form.category === category.value &&
                                            form.custom_category_id === null
                                                ? chipOn
                                                : chipOff,
                                        ]"
                                        @click="chooseCategory(category.value)"
                                    >
                                        {{ category.label }}
                                    </button>
                                    <span
                                        v-for="custom in customCategories"
                                        :key="`custom-${custom.id}`"
                                        class="inline-flex items-center"
                                        :class="[
                                            chipClass,
                                            'px-0',
                                            form.custom_category_id ===
                                            custom.id
                                                ? chipOn
                                                : chipOff,
                                        ]"
                                    >
                                        <button
                                            type="button"
                                            role="radio"
                                            :aria-checked="
                                                form.custom_category_id ===
                                                custom.id
                                            "
                                            class="pr-1 pl-2.5"
                                            @click="
                                                chooseCustomCategory(custom.id)
                                            "
                                        >
                                            {{ custom.name }}
                                        </button>
                                        <button
                                            type="button"
                                            class="pr-2 pl-0.5 opacity-60 hover:opacity-100"
                                            :aria-label="`Remove ${custom.name}`"
                                            @click="removeCategory(custom.id)"
                                        >
                                            ×
                                        </button>
                                    </span>
                                    <button
                                        v-if="!addingCategory"
                                        type="button"
                                        :class="chipClass"
                                        class="border-dashed border-[var(--bp-faint)] text-[var(--bp-muted)] hover:text-[var(--bp-ink)]"
                                        @click="addingCategory = true"
                                    >
                                        + New
                                    </button>
                                </div>
                                <!-- Not a nested form: Enter here adds the category, not the entry. -->
                                <div
                                    v-if="addingCategory"
                                    class="mt-1 flex items-center gap-2"
                                >
                                    <input
                                        v-model="categoryForm.name"
                                        type="text"
                                        maxlength="30"
                                        placeholder="Category name"
                                        aria-label="New category name"
                                        autofocus
                                        @keydown.enter.prevent="addCategory"
                                        class="min-w-0 flex-1 border-b border-[var(--bp-line)] bg-transparent py-1 text-[13px] outline-none focus:border-[var(--bp-ink)]"
                                    />
                                    <button
                                        type="button"
                                        class="text-[13px] font-semibold text-[var(--bp-accent)]"
                                        :disabled="categoryForm.processing"
                                        @click="addCategory"
                                    >
                                        Add
                                    </button>
                                    <button
                                        type="button"
                                        class="text-[13px] text-[var(--bp-muted)]"
                                        @click="
                                            addingCategory = false;
                                            categoryForm.reset();
                                            categoryForm.clearErrors();
                                        "
                                    >
                                        Cancel
                                    </button>
                                </div>
                                <p
                                    v-if="form.errors.category"
                                    :class="errorClass"
                                >
                                    {{ form.errors.category }}
                                </p>
                                <p
                                    v-if="categoryForm.errors.name"
                                    :class="errorClass"
                                >
                                    {{ categoryForm.errors.name }}
                                </p>
                            </div>
                        </template>

                        <div>
                            <label :class="rowClass">
                                <span class="shrink-0 text-[var(--bp-muted)]"
                                    >Date</span
                                >
                                <input
                                    v-model="form.date"
                                    type="date"
                                    class="bp-mono bg-transparent text-right outline-none"
                                />
                            </label>
                            <p
                                v-if="form.errors.date"
                                :class="errorClass"
                                class="mt-1"
                            >
                                {{ form.errors.date }}
                            </p>
                        </div>

                        <input
                            v-model="form.note"
                            type="text"
                            maxlength="200"
                            placeholder="Note (optional)"
                            aria-label="Note"
                            class="border-b border-[var(--bp-line)] bg-transparent py-1.5 text-[13.5px] outline-none placeholder:text-[var(--bp-faint)] focus:border-[var(--bp-ink)]"
                        />

                        <button
                            type="submit"
                            class="flex items-center justify-center gap-1.5 rounded bg-[var(--bp-ink)] px-4 py-2.5 text-[13.5px] font-semibold text-[var(--bp-card)] disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            <span
                                class="ms"
                                style="font-size: 16px"
                                aria-hidden="true"
                                >add</span
                            >
                            {{
                                kind === 'transfer'
                                    ? 'Add transfer'
                                    : kind === 'income'
                                      ? 'Add income'
                                      : 'Add expense'
                            }}
                        </button>
                    </div>
                </form>

                <div v-if="categoryBars.length" class="mt-1">
                    <div
                        class="flex items-center justify-between border-b border-[var(--bp-ink)] pb-2"
                    >
                        <span class="text-[15px] font-semibold"
                            >By category</span
                        >
                        <span
                            class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                            aria-hidden="true"
                            >FIG. 1</span
                        >
                    </div>
                    <div
                        v-for="bar in categoryBars"
                        :key="bar.category"
                        class="border-b border-[var(--bp-hair)] py-2"
                    >
                        <div class="flex justify-between text-[13px]">
                            <span>{{ categoryLabel(bar.category) }}</span>
                            <span class="bp-mono text-[12px]">{{
                                formatPeso(bar.total)
                            }}</span>
                        </div>
                        <div
                            class="mt-[5px] h-1.5 bg-[var(--bp-paper)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
                            aria-hidden="true"
                        >
                            <div
                                class="box-border h-full border border-[var(--bp-ink)]"
                                :style="{
                                    width: bar.width + '%',
                                    background: bar.fill,
                                }"
                            ></div>
                        </div>
                    </div>
                </div>

                <InfoNote id="transactions-income" blueprint>
                    Income here is what actually arrived and adds to the
                    account's balance. The Overview income is only the forecast,
                    and a pay day you mark as received here is not counted in it
                    again.
                </InfoNote>
            </div>

            <div class="min-w-0 lg:order-1">
                <!-- Summary strip -->
                <div
                    class="mb-8 grid grid-cols-1 border border-[var(--bp-ink)] bg-[var(--bp-card)] sm:grid-cols-3"
                >
                    <div class="px-[18px] py-3.5">
                        <p class="bp-caption">Spent</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ formatPeso(total) }}
                        </p>
                    </div>
                    <div
                        class="border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:border-t-0 sm:border-l"
                    >
                        <p class="bp-caption">Received</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] text-[var(--bp-accent)] tabular-nums"
                        >
                            +{{ formatPeso(received) }}
                        </p>
                    </div>
                    <label
                        class="bp-grid-fine border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:border-t-0 sm:border-l"
                    >
                        <span class="bp-caption block">Account</span>
                        <span class="relative mt-1.5 flex items-center">
                            <select
                                :value="accountFilter ?? ''"
                                class="w-full appearance-none bg-transparent pr-6 text-[15px] font-medium outline-none disabled:opacity-100"
                                :disabled="accounts.length < 2"
                                @change="
                                    goTo(
                                        all ? 'all' : month,
                                        Number(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        ) || null,
                                    )
                                "
                            >
                                <option value="">All accounts</option>
                                <option
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                            <span
                                v-if="accounts.length > 1"
                                class="ms pointer-events-none absolute right-0 text-[var(--bp-muted)]"
                                style="font-size: 18px"
                                aria-hidden="true"
                                >expand_more</span
                            >
                        </span>
                    </label>
                </div>

                <!-- A. Entries -->
                <BlueprintSectionHeading letter="A" title="Entries">
                    <span
                        v-if="entryCount"
                        class="bp-mono hidden text-[11px] tracking-[0.1em] text-[var(--bp-faint)] sm:inline"
                        >NEWEST FIRST</span
                    >
                </BlueprintSectionHeading>
                <div class="mt-3.5 border-t border-[var(--bp-ink)]">
                    <p
                        v-if="entryCount === 0"
                        class="border-b border-[var(--bp-line)] py-10 text-center text-[14px] text-[var(--bp-muted)]"
                    >
                        Nothing logged in {{ title }}.
                    </p>
                    <section
                        v-for="day in days"
                        :key="day.date"
                        class="grid grid-cols-[64px_minmax(0,1fr)] border-b border-[var(--bp-line)] sm:grid-cols-[96px_minmax(0,1fr)]"
                        :aria-label="formatDay(day.date)"
                    >
                        <div class="flex flex-col gap-0.5 py-4">
                            <span
                                class="text-[26px] leading-none font-semibold tracking-[-0.02em] tabular-nums"
                                >{{ dayParts(day.date).day }}</span
                            >
                            <span
                                class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-muted)]"
                                >{{ dayParts(day.date).weekday }}</span
                            >
                            <span
                                v-if="daySpent(day.items) > 0"
                                class="bp-mono mt-2 text-[11px] text-[var(--bp-faint)]"
                                >{{ formatPeso(daySpent(day.items)) }}</span
                            >
                        </div>
                        <div class="flex flex-col py-1.5">
                            <template v-for="item in day.items" :key="item.key">
                                <div
                                    v-if="item.type === 'payment'"
                                    class="-mx-2 grid min-h-[54px] grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-3 px-2 sm:grid-cols-[40px_minmax(0,1fr)_auto]"
                                >
                                    <span
                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-dashed border-[var(--bp-faint)]"
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="ms text-[var(--bp-muted)]"
                                            style="font-size: 17px"
                                            >credit_card</span
                                        >
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate text-[14px] font-semibold"
                                            >Payment to
                                            {{ item.row.debtName }}</span
                                        >
                                        <span
                                            class="block text-[12px] text-[var(--bp-muted)]"
                                            >Debt payment<template
                                                v-if="item.row.paidWith"
                                            >
                                                ·
                                                {{
                                                    item.row.paidWith
                                                }}</template
                                            ></span
                                        >
                                    </span>
                                    <span class="flex items-center gap-3">
                                        <span
                                            class="bp-mono hidden rounded-sm border border-[var(--bp-line)] px-1.5 py-0.5 text-[10px] tracking-[0.08em] text-[var(--bp-muted)] sm:inline"
                                            >NOT COUNTED</span
                                        >
                                        <span
                                            class="bp-mono text-right text-[13.5px] text-[var(--bp-faint)] sm:min-w-[110px]"
                                            >{{
                                                formatPeso(item.row.amount)
                                            }}</span
                                        >
                                    </span>
                                </div>
                                <button
                                    v-else
                                    type="button"
                                    class="-mx-2 grid min-h-[54px] grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-3 rounded px-2 text-left hover:bg-[rgba(27,27,26,0.035)] sm:grid-cols-[40px_minmax(0,1fr)_auto]"
                                    @click="edit(item.row)"
                                >
                                    <span
                                        class="flex h-8 w-8 items-center justify-center rounded-full border"
                                        :class="
                                            item.row.kind === 'transfer'
                                                ? 'border-dashed border-[var(--bp-faint)] text-[var(--bp-muted)]'
                                                : 'border-[var(--bp-line)] bg-[var(--bp-card)]'
                                        "
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="ms"
                                            style="font-size: 17px"
                                            >{{ entryIcon(item.row) }}</span
                                        >
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate text-[14px] font-semibold"
                                            >{{ entryName(item.row) }}</span
                                        >
                                        <span
                                            class="block truncate text-[12px] text-[var(--bp-muted)]"
                                            >{{ entryMeta(item.row) }}</span
                                        >
                                    </span>
                                    <span class="flex items-center gap-3">
                                        <span
                                            v-if="item.row.onStatement"
                                            class="bp-mono hidden rounded-sm border border-[var(--bp-line)] px-1.5 py-0.5 text-[10px] tracking-[0.08em] text-[var(--bp-muted)] sm:inline"
                                            >ON STATEMENT</span
                                        >
                                        <span
                                            class="bp-mono text-right text-[13.5px] sm:min-w-[110px]"
                                            :class="{
                                                'font-semibold text-[var(--bp-accent)]':
                                                    item.row.kind === 'income',
                                                'text-[var(--bp-faint)]':
                                                    item.row.kind ===
                                                    'transfer',
                                                'font-semibold':
                                                    item.row.kind === 'expense',
                                            }"
                                            >{{
                                                item.row.kind === 'income'
                                                    ? '+'
                                                    : ''
                                            }}{{
                                                formatPeso(item.row.amount)
                                            }}</span
                                        >
                                    </span>
                                </button>
                            </template>
                        </div>
                    </section>
                </div>
                <p
                    v-if="entryCount > 0"
                    class="mt-3 flex items-center gap-2 text-[12.5px] text-[var(--bp-muted)]"
                >
                    <span
                        class="h-3.5 w-3.5 shrink-0 rounded-full border border-dashed border-[var(--bp-faint)]"
                        aria-hidden="true"
                    ></span>
                    Debt payments and transfers are shown for reference and are
                    not counted in Spent.
                </p>
            </div>
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock drawing="Money / Transactions" sheet="M-04" />
        </div>

        <StatementImportDialog v-model:open="importOpen" :cards="cards" />
        <TransactionDialog
            v-model:open="editOpen"
            :transaction="editing"
            :accounts="accounts"
            :cards="cards"
            :custom-categories="customCategories"
        />
    </div>
</template>
