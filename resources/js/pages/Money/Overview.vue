<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { update as updateRecovery } from '@/actions/App/Http/Controllers/Money/RecoveryController';
import AccountDialog from '@/components/AccountDialog.vue';
import type { AccountRow } from '@/components/AccountDialog.vue';
import BillDialog from '@/components/BillDialog.vue';
import type { BillRow } from '@/components/BillDialog.vue';
import IncomeSourceDialog from '@/components/IncomeSourceDialog.vue';
import type { IncomeSourceRow } from '@/components/IncomeSourceDialog.vue';
import InfoNote from '@/components/InfoNote.vue';
import { Button } from '@/components/ui/button';
import { transactions } from '@/routes/money';
import { formatDay, formatPeso, monthNames } from '@/utils/money';

type Upcoming = {
    kind: 'debt' | 'bill' | 'income';
    name: string;
    amount: number;
    date: string;
    autopay: boolean;
    sourceId: number | null;
    received: boolean;
};

type Shortfall = {
    kind: 'debt' | 'bill';
    name: string;
    amount: number;
    date: string;
    short: number;
};

type CarryAlert = {
    debtId: number;
    name: string;
    monthsCarried: number;
    balance: number;
};

type Recovery = {
    carryover: number;
    months: number | null;
    extra: number;
    options: { months: number; extra: number }[];
};

const props = defineProps<{
    numbers: {
        income: number;
        expenses: number;
        setAside: number;
        debtPayment: number;
        bufferContribution: number;
        safeToSpend: number;
        perDay: number;
    };
    carryAlerts: CarryAlert[];
    recovery: Recovery | null;
    cashAvailable: number;
    shortfalls: Shortfall[];
    accounts: AccountRow[];
    upcoming: Upcoming[];
    incomeSources: IncomeSourceRow[];
    bills: BillRow[];
}>();

function setRecovery(months: number | null): void {
    router.patch(updateRecovery().url, { months }, { preserveScroll: true });
}

const editingAccount = ref<AccountRow | null>(null);
const accountOpen = ref(false);
const editingIncome = ref<IncomeSourceRow | null>(null);
const incomeOpen = ref(false);
const editingBill = ref<BillRow | null>(null);
const billOpen = ref(false);

function openAccount(account: AccountRow | null): void {
    editingAccount.value = account;
    accountOpen.value = true;
}

const accountTypeLabel: Record<string, string> = {
    cash: 'Cash',
    bank: 'Bank',
    ewallet: 'E-wallet',
};

function openIncome(source: IncomeSourceRow | null): void {
    editingIncome.value = source;
    incomeOpen.value = true;
}

function openBill(bill: BillRow | null): void {
    editingBill.value = bill;
    billOpen.value = true;
}

const tiles = computed(() => [
    { label: 'Income', value: props.numbers.income, sign: '+' },
    { label: 'Bills', value: props.numbers.expenses, sign: '−' },
    {
        label: 'Set aside for yearly bills and goals',
        value: props.numbers.setAside,
        sign: '−',
    },
    { label: 'Debt payments', value: props.numbers.debtPayment, sign: '−' },
    { label: 'Buffer', value: props.numbers.bufferContribution, sign: '−' },
]);

const isShort = computed(() => props.numbers.safeToSpend < 0);

const kindLabel = { debt: 'Debt', bill: 'Bill', income: 'Payday' } as const;

function scheduleText(source: IncomeSourceRow): string {
    return source.schedule === 'semi_monthly'
        ? `Days ${source.payDay1} and ${source.payDay2}`
        : `Day ${source.payDay1}`;
}

function billText(bill: BillRow): string {
    return bill.frequency === 'annual'
        ? `Yearly, ${monthNames[(bill.dueMonth ?? 1) - 1]} ${bill.dueDay}`
        : `Monthly, day ${bill.dueDay}`;
}

const cardStyle =
    'background: var(--hab-card-bg); border: 1px solid var(--hab-card-border);';
</script>

<template>
    <Head title="Money" />

    <div
        class="mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
    >
        <div class="mb-6">
            <h1
                class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                style="color: var(--hab-ink)"
            >
                Overview
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                What is left to spend this month, after everything that is
                already spoken for
            </p>
        </div>

        <div
            v-if="isShort"
            class="mb-6 rounded-[16px] px-5 py-4 text-[14px]"
            style="
                background: #fdecea;
                border: 1px solid #f5c6c0;
                color: #8f2a1f;
            "
            role="alert"
        >
            Your bills, savings and debt payments come to
            {{ formatPeso(-numbers.safeToSpend) }} more than your income this
            month.
        </div>

        <div
            v-if="shortfalls.length > 0"
            class="mb-6 rounded-[16px] px-5 py-4 text-[14px]"
            style="
                background: #fdecea;
                border: 1px solid #f5c6c0;
                color: #8f2a1f;
            "
            role="alert"
        >
            <p class="mb-1 font-semibold">
                You may run short in the next 30 days
            </p>
            <ul class="flex flex-col gap-0.5">
                <li
                    v-for="item in shortfalls"
                    :key="`${item.name}-${item.date}`"
                >
                    {{ formatPeso(item.short) }} short for {{ item.name }} due
                    {{ formatDay(item.date) }}
                </li>
            </ul>
            <p class="mt-2 text-[12.5px]">
                This uses the balances of accounts you can spend and the paydays
                you have entered.
            </p>
        </div>

        <div
            v-for="alert in carryAlerts"
            :key="alert.debtId"
            class="mb-6 rounded-[16px] px-5 py-4 text-[14px]"
            style="
                background: #fff4e0;
                border: 1px solid #f2d9a6;
                color: #7a5200;
            "
            role="alert"
        >
            <p class="mb-1 font-semibold">
                {{ alert.name }} has carried a balance for
                {{ alert.monthsCarried }} of the last 3 months
            </p>
            <p>
                {{ formatPeso(alert.balance) }} is still charging interest.
                Consider calling the bank to ask about a balance conversion or
                restructuring it into fixed installments.
            </p>
        </div>

        <div class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <p class="text-[12px]" style="color: var(--hab-muted)">
                Safe to spend this month
            </p>
            <p
                class="font-['Bricolage_Grotesque'] text-[44px] leading-tight font-extrabold tabular-nums"
                :style="{ color: isShort ? '#c0392b' : 'var(--hab-ink)' }"
            >
                {{ formatPeso(numbers.safeToSpend) }}
            </p>
            <p class="mt-1 text-[13px]" style="color: var(--hab-muted)">
                About {{ formatPeso(numbers.perDay) }} a day for the rest of the
                month
            </p>
            <InfoNote id="overview-safe">
                Your forecast income, less bills, the yearly set-aside, debt
                payments, goals and any recovery payment. It looks at what is
                planned for the month, not at your account balances. On hand,
                below, shows those.
            </InfoNote>
        </div>

        <div v-if="recovery" class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <div class="mb-1 flex items-center justify-between gap-3">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Recovery mode
                </h2>
                <Button
                    v-if="recovery.months !== null"
                    variant="outline"
                    size="sm"
                    @click="setRecovery(null)"
                >
                    Turn off
                </Button>
            </div>
            <p
                v-if="recovery.months !== null"
                class="text-[14px]"
                style="color: var(--hab-ink)"
            >
                Setting aside {{ formatPeso(recovery.extra) }} a month on top of
                your minimums, so the
                {{ formatPeso(recovery.carryover) }} carried over on your cards
                clears in {{ recovery.months }} months. It is already taken off
                safe to spend.
            </p>
            <template v-else>
                <p class="mb-3 text-[14px]" style="color: var(--hab-muted)">
                    {{ formatPeso(recovery.carryover) }} is carried over on your
                    cards and charging interest. Pick how fast to clear it and
                    the extra comes off safe to spend.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="option in recovery.options"
                        :key="option.months"
                        variant="outline"
                        @click="setRecovery(option.months)"
                    >
                        {{ option.months }} months,
                        {{ formatPeso(option.extra) }} more a month
                    </Button>
                </div>
            </template>
        </div>

        <div class="mb-6 rounded-[22px] p-5" :style="cardStyle">
            <p class="text-[12px]" style="color: var(--hab-muted)">
                On hand right now
            </p>
            <p
                class="font-['Bricolage_Grotesque'] text-[26px] font-extrabold tabular-nums"
                style="color: var(--hab-ink)"
            >
                {{ formatPeso(cashAvailable) }}
            </p>
            <p class="mt-1 text-[12px]" style="color: var(--hab-muted)">
                Balances of the accounts you can spend, as you last updated them
            </p>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="rounded-[22px] p-5"
                :style="cardStyle"
            >
                <p class="text-[12px]" style="color: var(--hab-muted)">
                    {{ tile.label }}
                </p>
                <p
                    class="font-['Bricolage_Grotesque'] text-[22px] font-extrabold tabular-nums"
                    style="color: var(--hab-ink)"
                >
                    {{ tile.sign }} {{ formatPeso(tile.value) }}
                </p>
            </div>
        </div>

        <div class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <div class="mb-3 flex items-center justify-between">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Accounts
                </h2>
                <Button variant="outline" size="sm" @click="openAccount(null)"
                    >Add account</Button
                >
            </div>
            <InfoNote id="overview-accounts">
                On hand is the total of the accounts you can spend. It is the
                starting point for the shortfall warnings and the calendar's
                gaps. Only spending and income you log on Transactions change
                these balances, or you editing them here.
            </InfoNote>
            <p
                v-if="accounts.length === 0"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                Add where your money sits to see what is on hand.
            </p>
            <ul v-else>
                <li v-for="account in accounts" :key="account.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-[10px] py-2 text-left hover:bg-black/[0.03]"
                        @click="openAccount(account)"
                    >
                        <span class="min-w-0">
                            <span
                                class="block truncate text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                                >{{ account.name }}</span
                            >
                            <span
                                class="block text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                {{ accountTypeLabel[account.type]
                                }}<template v-if="!account.isSpendable">
                                    · kept aside, not counted</template
                                >
                            </span>
                        </span>
                        <span
                            class="shrink-0 text-[14px] tabular-nums"
                            :style="{
                                color: account.isSpendable
                                    ? 'var(--hab-ink)'
                                    : 'var(--hab-muted)',
                            }"
                            >{{ formatPeso(account.balance) }}</span
                        >
                    </button>
                </li>
            </ul>
        </div>

        <div class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <h2
                class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                style="color: var(--hab-ink)"
            >
                Coming up in the next 14 days
            </h2>
            <InfoNote id="overview-upcoming">
                Paydays, bills and debt dues from your income, bills and debts.
                A pay day you mark as received stops counting in the forecast,
                since the money is already in your balance.
            </InfoNote>
            <p
                v-if="upcoming.length === 0"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                Nothing due or paid in the next two weeks.
            </p>
            <ul v-else class="flex flex-col">
                <li
                    v-for="(item, index) in upcoming"
                    :key="`${item.kind}-${item.name}-${item.date}-${index}`"
                    class="flex items-center justify-between gap-3 py-2.5"
                    :style="
                        index > 0
                            ? 'border-top: 1px solid var(--hab-hairline)'
                            : ''
                    "
                >
                    <div class="min-w-0">
                        <p
                            class="truncate text-[14px] font-semibold"
                            style="color: var(--hab-ink)"
                        >
                            {{ item.name }}
                        </p>
                        <p class="text-[12px]" style="color: var(--hab-muted)">
                            {{ formatDay(item.date) }} ·
                            {{ kindLabel[item.kind]
                            }}<template v-if="item.autopay">
                                · automatic</template
                            >
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span
                            v-if="item.kind === 'income' && item.received"
                            class="rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold"
                            style="
                                background: color-mix(
                                    in srgb,
                                    var(--hab-accent) 16%,
                                    transparent
                                );
                                color: var(--hab-accent);
                            "
                            >Received</span
                        >
                        <Link
                            v-else-if="item.kind === 'income' && item.sourceId"
                            :href="
                                transactions({
                                    query: {
                                        income: item.sourceId,
                                        date: item.date,
                                    },
                                }).url
                            "
                            class="rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold underline"
                            style="color: var(--hab-ink)"
                            >Received?</Link
                        >
                        <p
                            class="text-[14px] font-semibold tabular-nums"
                            :style="{
                                color:
                                    item.kind === 'income'
                                        ? 'var(--hab-accent)'
                                        : 'var(--hab-ink)',
                            }"
                        >
                            {{ item.kind === 'income' ? '+' : ''
                            }}{{ formatPeso(item.amount) }}
                        </p>
                    </div>
                </li>
            </ul>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-[22px] p-6" :style="cardStyle">
                <div class="mb-3 flex items-center justify-between">
                    <h2
                        class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                        style="color: var(--hab-ink)"
                    >
                        Income
                    </h2>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="openIncome(null)"
                        >Add income</Button
                    >
                </div>
                <InfoNote id="overview-income" title="Forecast only.">
                    These amounts feed safe to spend, the calendar's paydays and
                    the shortfall warnings. They do not change any account
                    balance. When pay arrives, press Received on its pay day
                    under Coming up, or log it on Transactions.
                </InfoNote>
                <p
                    v-if="incomeSources.length === 0"
                    class="text-[14px]"
                    style="color: var(--hab-muted)"
                >
                    Add your salary or other income to see what is safe to
                    spend.
                </p>
                <ul v-else>
                    <li v-for="source in incomeSources" :key="source.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 rounded-[10px] py-2 text-left hover:bg-black/[0.03]"
                            @click="openIncome(source)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-semibold"
                                    style="color: var(--hab-ink)"
                                    >{{ source.name }}</span
                                >
                                <span
                                    class="block text-[12px]"
                                    style="color: var(--hab-muted)"
                                    >{{ scheduleText(source) }}</span
                                >
                            </span>
                            <span
                                class="shrink-0 text-[14px] tabular-nums"
                                style="color: var(--hab-ink)"
                                >{{ formatPeso(source.amount) }}</span
                            >
                        </button>
                    </li>
                </ul>
            </div>

            <div class="rounded-[22px] p-6" :style="cardStyle">
                <div class="mb-3 flex items-center justify-between">
                    <h2
                        class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                        style="color: var(--hab-ink)"
                    >
                        Bills
                    </h2>
                    <Button variant="outline" size="sm" @click="openBill(null)"
                        >Add bill</Button
                    >
                </div>
                <InfoNote id="overview-bills">
                    Used for the Bills number in safe to spend, and for due
                    dates on the calendar and in the shortfall warnings. Yearly
                    bills are set aside at a twelfth each month. Paying one does
                    not change a balance until you log it as an expense.
                </InfoNote>
                <p
                    v-if="bills.length === 0"
                    class="text-[14px]"
                    style="color: var(--hab-muted)"
                >
                    Add rent, utilities and subscriptions so they come off what
                    is safe to spend.
                </p>
                <ul v-else>
                    <li v-for="bill in bills" :key="bill.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 rounded-[10px] py-2 text-left hover:bg-black/[0.03]"
                            @click="openBill(bill)"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[14px] font-semibold"
                                    style="color: var(--hab-ink)"
                                    >{{ bill.name }}</span
                                >
                                <span
                                    class="block text-[12px]"
                                    style="color: var(--hab-muted)"
                                    >{{ billText(bill) }}</span
                                >
                            </span>
                            <span
                                class="shrink-0 text-[14px] tabular-nums"
                                style="color: var(--hab-ink)"
                                >{{ formatPeso(bill.amount) }}</span
                            >
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <AccountDialog v-model:open="accountOpen" :account="editingAccount" />
        <IncomeSourceDialog v-model:open="incomeOpen" :source="editingIncome" />
        <BillDialog v-model:open="billOpen" :bill="editingBill" />
    </div>
</template>
