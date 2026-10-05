<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import {
    destroy as destroyCategory,
    store as storeCategory,
} from '@/actions/App/Http/Controllers/Money/CustomCategoryController';
import { store } from '@/actions/App/Http/Controllers/Money/TransactionsController';
import InfoNote from '@/components/InfoNote.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import StatementImportDialog from '@/components/StatementImportDialog.vue';
import TransactionDialog from '@/components/TransactionDialog.vue';
import type { TransactionRow } from '@/components/TransactionDialog.vue';
import { Button } from '@/components/ui/button';
import { transactions as transactionsRoute } from '@/routes/money';
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
        incomeSourceId: number;
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
    cards: { id: number; name: string; guard: CardGuard | null }[];
    customCategories: { id: number; name: string }[];
    lastPaidWith: { accountId: number | null; debtId: number | null } | null;
}>();

/** An entry is spending or money received. Income goes into an account. */
const kind = ref<'expense' | 'income'>(props.prefill ? 'income' : 'expense');
const incomeSourceId = ref<number | ''>(props.prefill?.incomeSourceId ?? '');
const payDate = ref<string | null>(props.prefill?.date ?? null);

function defaultPaidWith(): string {
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
    date: props.prefill?.date ?? props.today,
    note: props.prefill?.name ?? '',
});

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

function chooseKind(next: 'expense' | 'income'): void {
    kind.value = next;

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

const hasMethods = computed(() =>
    kind.value === 'income'
        ? props.accounts.length > 0
        : props.accounts.length > 0 || props.cards.length > 0,
);

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
    form.transform(({ paidWith, category, ...data }) => {
        const account = paidWith.startsWith('account:')
            ? Number(paidWith.slice(8))
            : null;

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
            note: data.note || null,
            account_id: account,
            debt_id: paidWith.startsWith('card:')
                ? Number(paidWith.slice(5))
                : null,
        };
    }).post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'note', 'category', 'custom_category_id');
            incomeSourceId.value = '';
            payDate.value = null;
            focusAmount();
        },
    });
}

const title = computed(() => {
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

function goTo(month: string): void {
    router.get(
        transactionsRoute({ query: { month } }).url,
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

const cardStyle =
    'background: var(--hab-card-bg); border: 1px solid var(--hab-card-border);';
/** The server reports a missing or invalid payer under the column it checks. */
const methodError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.account_id ?? errors.debt_id;
});

const fieldClass =
    'w-full rounded-[11px] border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-3 py-2 text-[14px] text-[var(--hab-ink)] outline-none focus:border-[var(--hab-accent)]';
const errorClass = 'mt-1 text-[12px] text-red-600';
</script>

<template>
    <Head title="Transactions" />

    <div
        class="mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
    >
        <div class="mb-6">
            <h1
                class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                style="color: var(--hab-ink)"
            >
                Transactions
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                Log what you spend and what you receive. Everything else works
                without it.
            </p>
        </div>

        <form
            class="mb-6 rounded-[22px] p-5"
            :style="cardStyle"
            @submit.prevent="submit"
        >
            <InfoNote
                id="transactions-income"
                title="Income here is what actually arrived."
            >
                It goes into the account you pick and adds to its balance. The
                income you set up on the Overview is the forecast, and a pay day
                you mark as received here is not counted in it again.
            </InfoNote>

            <div
                class="mb-3 inline-flex rounded-full p-0.5"
                style="background: var(--hab-hairline)"
                role="radiogroup"
                aria-label="Entry type"
            >
                <button
                    v-for="option in ['expense', 'income'] as const"
                    :key="option"
                    type="button"
                    role="radio"
                    :aria-checked="kind === option"
                    class="rounded-full px-4 py-1 text-[13px] font-semibold"
                    :style="
                        kind === option
                            ? 'background: var(--hab-card-bg); color: var(--hab-ink)'
                            : 'background: transparent; color: var(--hab-muted)'
                    "
                    @click="chooseKind(option)"
                >
                    {{ option === 'expense' ? 'Expense' : 'Income' }}
                </button>
            </div>

            <p
                v-if="!hasMethods"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                {{
                    kind === 'income'
                        ? 'Add an account on the Overview to record income.'
                        : 'Add an account on the Overview, or a card on Debts, to log what you spend.'
                }}
            </p>
            <template v-else>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_1fr]">
                    <div ref="amountBox">
                        <label
                            class="mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]"
                            >Amount</label
                        >
                        <MoneyInput
                            v-model="form.amount"
                            :empty-value="null"
                            adorn
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.amount" :class="errorClass">
                            {{ form.errors.amount }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]"
                            >{{
                                kind === 'income' ? 'Paid into' : 'Paid with'
                            }}</label
                        >
                        <select v-model="form.paidWith" :class="fieldClass">
                            <optgroup v-if="accounts.length" label="Accounts">
                                <option
                                    v-for="account in accounts"
                                    :key="`a${account.id}`"
                                    :value="`account:${account.id}`"
                                >
                                    {{ account.name }}
                                </option>
                            </optgroup>
                            <optgroup
                                v-if="kind === 'expense' && cards.length"
                                label="Cards"
                            >
                                <option
                                    v-for="card in cards"
                                    :key="`c${card.id}`"
                                    :value="`card:${card.id}`"
                                >
                                    {{ card.name }}
                                </option>
                            </optgroup>
                        </select>
                        <p v-if="methodError" :class="errorClass">
                            {{ methodError }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]"
                            >Date</label
                        >
                        <input
                            v-model="form.date"
                            type="date"
                            :class="fieldClass"
                        />
                        <p v-if="form.errors.date" :class="errorClass">
                            {{ form.errors.date }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="kind === 'income' && incomeSources.length"
                    class="mt-3"
                >
                    <label
                        class="mb-1 block text-[12.5px] font-semibold text-[var(--hab-ink-secondary)]"
                        >From</label
                    >
                    <select
                        v-model="incomeSourceId"
                        :class="fieldClass"
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
                </div>

                <div
                    v-if="guard"
                    class="mt-3 rounded-[12px] px-4 py-3 text-[13.5px]"
                    style="
                        background: #fff4e0;
                        border: 1px solid #f2d9a6;
                        color: #7a5200;
                    "
                    role="status"
                >
                    {{ guard.name }} already carries
                    {{ formatPeso(guard.revolving) }}, which costs
                    {{ guard.monthlyRate }}% a month. A new charge adds to what
                    is charging interest.
                    <template v-if="guard.suggestedAccount">
                        <button
                            type="button"
                            class="ml-1 font-semibold underline"
                            @click="
                                form.paidWith = `account:${guard.suggestedAccount.id}`
                            "
                        >
                            Use {{ guard.suggestedAccount.name }} instead
                        </button>
                        ({{ formatPeso(guard.suggestedAccount.balance) }}
                        available).
                    </template>
                </div>

                <div
                    v-if="kind === 'expense'"
                    class="mt-3 flex flex-wrap gap-2"
                    role="radiogroup"
                    aria-label="Category"
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
                        class="rounded-full px-3.5 py-1.5 text-[13px] font-semibold"
                        :style="
                            form.category === category.value &&
                            form.custom_category_id === null
                                ? 'background: var(--hab-accent); color: #fff; border: 1px solid var(--hab-accent)'
                                : 'background: transparent; color: var(--hab-ink); border: 1px solid var(--hab-card-border)'
                        "
                        @click="chooseCategory(category.value)"
                    >
                        {{ category.label }}
                    </button>
                    <span
                        v-for="custom in customCategories"
                        :key="`custom-${custom.id}`"
                        class="inline-flex items-center rounded-full text-[13px] font-semibold"
                        :style="
                            form.custom_category_id === custom.id
                                ? 'background: var(--hab-accent); color: #fff; border: 1px solid var(--hab-accent)'
                                : 'background: transparent; color: var(--hab-ink); border: 1px solid var(--hab-card-border)'
                        "
                    >
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="
                                form.custom_category_id === custom.id
                            "
                            class="py-1.5 pr-1.5 pl-3.5"
                            @click="chooseCustomCategory(custom.id)"
                        >
                            {{ custom.name }}
                        </button>
                        <button
                            type="button"
                            class="py-1.5 pr-3 pl-1 opacity-60 hover:opacity-100"
                            :aria-label="`Remove ${custom.name}`"
                            @click="removeCategory(custom.id)"
                        >
                            ×
                        </button>
                    </span>
                    <button
                        v-if="!addingCategory"
                        type="button"
                        class="rounded-full px-3.5 py-1.5 text-[13px] font-semibold"
                        style="
                            background: transparent;
                            color: var(--hab-muted);
                            border: 1px dashed var(--hab-card-border);
                        "
                        @click="addingCategory = true"
                    >
                        + New
                    </button>
                    <form
                        v-else
                        class="inline-flex items-center gap-2"
                        @submit.prevent="addCategory"
                    >
                        <input
                            v-model="categoryForm.name"
                            type="text"
                            maxlength="30"
                            placeholder="Category name"
                            aria-label="New category name"
                            autofocus
                            class="w-36 rounded-full px-3.5 py-1.5 text-[13px]"
                            style="
                                background: transparent;
                                color: var(--hab-ink);
                                border: 1px solid var(--hab-card-border);
                            "
                        />
                        <button
                            type="submit"
                            class="text-[13px] font-semibold"
                            style="color: var(--hab-accent)"
                            :disabled="categoryForm.processing"
                        >
                            Add
                        </button>
                        <button
                            type="button"
                            class="text-[13px]"
                            style="color: var(--hab-muted)"
                            @click="
                                addingCategory = false;
                                categoryForm.reset();
                                categoryForm.clearErrors();
                            "
                        >
                            Cancel
                        </button>
                    </form>
                </div>
                <p
                    v-if="kind === 'expense' && form.errors.category"
                    :class="errorClass"
                >
                    {{ form.errors.category }}
                </p>
                <p v-if="categoryForm.errors.name" :class="errorClass">
                    {{ categoryForm.errors.name }}
                </p>

                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <input
                        v-model="form.note"
                        type="text"
                        maxlength="200"
                        placeholder="Note (optional)"
                        :class="fieldClass"
                    />
                    <Button type="submit" :disabled="form.processing">{{
                        kind === 'income' ? 'Add income' : 'Add expense'
                    }}</Button>
                </div>
            </template>
        </form>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Previous month"
                    @click="goTo(shiftMonth(-1))"
                    >‹</Button
                >
                <span
                    class="min-w-[130px] text-center text-[15px] font-semibold"
                    style="color: var(--hab-ink)"
                    >{{ title }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Next month"
                    :disabled="month >= currentMonth"
                    @click="goTo(shiftMonth(1))"
                    >›</Button
                >
                <Button
                    v-if="month !== currentMonth"
                    variant="ghost"
                    size="sm"
                    @click="goTo(currentMonth)"
                    >This month</Button
                >
            </div>
            <div class="flex items-center gap-3">
                <p class="text-[14px]" style="color: var(--hab-muted)">
                    Spent
                    <span
                        class="font-semibold tabular-nums"
                        style="color: var(--hab-ink)"
                        >{{ formatPeso(total) }}</span
                    >
                </p>
                <p
                    v-if="received > 0"
                    class="text-[14px]"
                    style="color: var(--hab-muted)"
                >
                    Received
                    <span
                        class="font-semibold tabular-nums"
                        style="color: var(--hab-accent)"
                        >{{ formatPeso(received) }}</span
                    >
                </p>
                <Button
                    v-if="cards.length > 0"
                    variant="outline"
                    size="sm"
                    @click="importOpen = true"
                    >Import statement</Button
                >
            </div>
        </div>

        <div v-if="categoryTotals.length > 0" class="mb-4 flex flex-wrap gap-2">
            <span
                v-for="item in categoryTotals"
                :key="item.category"
                class="rounded-full px-3 py-1 text-[12.5px]"
                style="background: var(--hab-hairline); color: var(--hab-ink)"
            >
                {{ categoryLabel(item.category) }} {{ formatPeso(item.total) }}
            </span>
        </div>

        <p
            v-if="transactions.length === 0 && debtPayments.length === 0"
            class="rounded-[22px] p-8 text-center text-[14px]"
            :style="cardStyle + 'color: var(--hab-muted)'"
        >
            Nothing logged in {{ title }}.
        </p>

        <div v-else class="flex flex-col gap-4">
            <section
                v-for="day in days"
                :key="day.date"
                class="rounded-[22px] p-5"
                :style="cardStyle"
            >
                <h2
                    class="mb-2 text-[13px] font-semibold"
                    style="color: var(--hab-muted)"
                >
                    {{ formatDay(day.date) }}
                </h2>
                <ul>
                    <li
                        v-for="(item, index) in day.items"
                        :key="item.key"
                        :style="
                            index > 0
                                ? 'border-top: 1px solid var(--hab-hairline)'
                                : ''
                        "
                    >
                        <div
                            v-if="item.type === 'payment'"
                            class="flex w-full items-center justify-between gap-3 py-2.5"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-semibold"
                                    style="color: var(--hab-ink)"
                                >
                                    Payment to {{ item.row.debtName }}
                                </span>
                                <span
                                    class="block text-[12px]"
                                    style="color: var(--hab-muted)"
                                >
                                    Debt payment · {{ item.row.paidWith }}
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-[14px] font-semibold tabular-nums"
                                style="color: var(--hab-ink)"
                                >{{ formatPeso(item.row.amount) }}</span
                            >
                        </div>
                        <button
                            v-else
                            type="button"
                            class="flex w-full items-center justify-between gap-3 py-2.5 text-left"
                            @click="edit(item.row)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-semibold"
                                    style="color: var(--hab-ink)"
                                >
                                    {{
                                        item.row.note ||
                                        (item.row.kind === 'income'
                                            ? 'Income'
                                            : (item.row.categoryName ??
                                              categoryLabel(item.row.category)))
                                    }}
                                </span>
                                <span
                                    class="block text-[12px]"
                                    style="color: var(--hab-muted)"
                                >
                                    {{
                                        item.row.kind === 'income'
                                            ? 'Income'
                                            : (item.row.categoryName ??
                                              categoryLabel(item.row.category))
                                    }}
                                    ·
                                    {{ item.row.paidWith
                                    }}<template v-if="item.row.onStatement">
                                        · on statement</template
                                    >
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-[14px] font-semibold tabular-nums"
                                :style="{
                                    color:
                                        item.row.kind === 'income'
                                            ? 'var(--hab-accent)'
                                            : 'var(--hab-ink)',
                                }"
                                >{{ item.row.kind === 'income' ? '+' : ''
                                }}{{ formatPeso(item.row.amount) }}</span
                            >
                        </button>
                    </li>
                </ul>
            </section>
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
