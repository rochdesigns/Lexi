<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { pay as payRecurringBill } from '@/actions/App/Http/Controllers/RecurringBillController';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

type Cycle = { start: string; end: string; label: string };

type UpcomingBill = {
    id: string;
    name: string;
    icon: string;
    color: string;
    bg: string;
    amount: number;
    dueLabel: string;
    daysUntil: number;
    type: 'set_aside' | 'recurring';
    budget_category_id?: number | null;
    category?: { id: number; name: string; color: string } | null;
};

type CategoryOverBudget = {
    id: number;
    name: string;
    icon: string;
    color: string;
    limit_amount: number;
    spent: number;
};

type SpendingAccount = { id: number; name: string; type: string };

const props = defineProps<{
    cycle: Cycle;
    incomeReceived: number;
    everydayExpenses: number;
    setAsidesHeld: number;
    debtPaid: number;
    leftForDailyExpenses: number;
    upcomingBills: UpcomingBill[];
    categoriesOverBudget: CategoryOverBudget[];
    debtFreeMonths: number | null;
    debtFreeDate: string | null;
    debtOnTrack: boolean;
    totalDebtRemaining: number;
    totalBillsPerCycle: number;
}>();

const page = usePage<{ spendingAccounts: SpendingAccount[] }>();
const spendingAccounts = computed(() => page.props.spendingAccounts ?? []);

// ── Headline status ──────────────────────────────────────────────────────
// Single source of truth so the status badge and the headline figure's own
// color can never disagree: both derive from this one boolean, never from
// an independent ratio (spent/budget, category count, etc.).
const isOverForCycle = computed(() => props.leftForDailyExpenses < 0);
const remainderStatus = computed(() =>
    isOverForCycle.value ? { label: 'Over for this cycle', color: '#c0512c' } : { label: 'On track', color: '#2f7d5b' },
);

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

// ── This cycle's breakdown (donut) ──────────────────────────────────────
type BreakdownItem = { key: string; label: string; value: number; color: string };
type DonutSegment = BreakdownItem & { start: number; end: number; percent: number };

const breakdownItems = computed<BreakdownItem[]>(() => [
    { key: 'debt', label: 'Debt', value: props.debtPaid, color: '#2a78d6' },
    { key: 'monthlyBills', label: 'Monthly bills', value: props.totalBillsPerCycle, color: '#eb6834' },
    { key: 'annualBills', label: 'Annual bills (set asides)', value: props.setAsidesHeld, color: '#1baf7a' },
    { key: 'everyday', label: 'Everyday expenses', value: props.everydayExpenses, color: '#eda100' },
]);

const breakdownTotal = computed(() => breakdownItems.value.reduce((sum, item) => sum + item.value, 0));

const donutSegments = computed<DonutSegment[]>(() => {
    const total = breakdownTotal.value;

    if (total <= 0) {
        return [];
    }

    const items = breakdownItems.value.filter((item) => item.value > 0);
    const gapDeg = items.length > 1 ? 3 : 0;
    const usable = 360 - gapDeg * items.length;

    let angle = 0;

    return items.map((item) => {
        const span = Math.min((item.value / total) * usable, 359.99);
        const start = angle;
        const end = start + span;
        angle = end + gapDeg;

        return { ...item, start, end, percent: (item.value / total) * 100 };
    });
});

const breakdownAriaLabel = computed(() =>
    `Breakdown of ${formatMoney(breakdownTotal.value)} this cycle: ${breakdownItems.value
        .map((item) => `${item.label} ${formatMoney(item.value)}`)
        .join(', ')}`,
);

function polarToCartesian(cx: number, cy: number, r: number, angleDeg: number) {
    const rad = ((angleDeg - 90) * Math.PI) / 180;

    return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
}

function arcPath(cx: number, cy: number, r: number, start: number, end: number): string {
    const startPoint = polarToCartesian(cx, cy, r, end);
    const endPoint = polarToCartesian(cx, cy, r, start);
    const largeArcFlag = end - start <= 180 ? 0 : 1;

    return `M ${startPoint.x} ${startPoint.y} A ${r} ${r} 0 ${largeArcFlag} 0 ${endPoint.x} ${endPoint.y}`;
}

function percentOf(value: number): number {
    return breakdownTotal.value > 0 ? Math.round((value / breakdownTotal.value) * 100) : 0;
}

// ── Mark paid (inline, recurring bills only) ────────────────────────────────

const payingBillId = ref<number | null>(null);
const payForm = useForm({
    amount: 0,
    merchant: '',
    account_id: 0,
    budget_category_id: null as number | null,
    transacted_at: '',
});

function today(): string {
    return new Date().toLocaleDateString('en-CA');
}

function openPayRow(bill: UpcomingBill): void {
    payingBillId.value = Number(bill.id.replace('rb-', ''));
    payForm.clearErrors();
    payForm.amount = bill.amount;
    payForm.merchant = bill.name;
    payForm.account_id = spendingAccounts.value[0]?.id ?? 0;
    payForm.budget_category_id = bill.budget_category_id ?? null;
    payForm.transacted_at = today();
}

function cancelPayRow(): void {
    payingBillId.value = null;
    payForm.clearErrors();
}

function submitPayRow(bill: UpcomingBill): void {
    payForm.post(payRecurringBill(Number(bill.id.replace('rb-', ''))).url, {
        preserveScroll: true,
        onSuccess: () => {
            payingBillId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Money" />

    <TooltipProvider :delay-duration="400">
    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5">
            <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                Money
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">{{ cycle.label }}</p>
        </div>

        <!-- Headline: left for daily expenses -->
        <div
            class="mb-5 rounded-[22px] px-6 py-7 sm:px-8"
            style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)"
        >
            <div class="flex items-center justify-between gap-3">
                <p class="text-[13.5px] font-medium text-white/72">Left for daily expenses</p>
                <span
                    class="inline-flex shrink-0 items-center rounded-full px-3 py-1 text-[12.5px] font-semibold text-white"
                    :style="{ background: remainderStatus.color }"
                >
                    {{ remainderStatus.label }}
                </span>
            </div>
            <p
                class="mt-2 font-['Bricolage_Grotesque'] text-[48px] font-extrabold leading-none"
                :style="{ color: isOverForCycle ? '#ffd27a' : '#fff' }"
            >
                {{ formatMoney(leftForDailyExpenses) }}
            </p>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_360px] items-start">

            <!-- ── Left column ── -->
            <div class="flex flex-col gap-5">

                <!-- Income / Everyday expenses -->
                <div class="grid grid-cols-2 gap-5">
                    <div
                        class="rounded-[22px] p-5"
                        style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                    >
                        <p class="text-[13px]" style="color: var(--hab-muted)">Income received</p>
                        <p class="mt-1.5 font-['Bricolage_Grotesque'] text-[26px] font-bold" style="color: var(--hab-ink)">
                            {{ formatMoney(incomeReceived) }}
                        </p>
                    </div>
                    <div
                        class="rounded-[22px] p-5"
                        style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                    >
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <p class="inline-flex items-center gap-1 text-[13px]" style="color: var(--hab-muted); cursor: help">
                                    Everyday expenses
                                    <span class="material-symbols-rounded" style="font-size: 14px">info</span>
                                </p>
                            </TooltipTrigger>
                            <TooltipContent side="bottom" class="max-w-[220px] text-center">
                                All expense transactions logged this cycle, excluding debt payments (those are counted separately under Debt paid).
                            </TooltipContent>
                        </Tooltip>
                        <p class="mt-1.5 font-['Bricolage_Grotesque'] text-[26px] font-bold" style="color: var(--hab-ink)">
                            {{ formatMoney(everydayExpenses) }}
                        </p>
                    </div>
                </div>

                <!-- Bills due soon -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                            Bills due soon
                        </h2>
                        <span class="text-[13px] font-semibold" style="color: var(--hab-muted)">
                            {{ formatMoney(totalBillsPerCycle) }} <span class="font-normal">/ cycle</span>
                        </span>
                    </div>

                    <div v-if="upcomingBills.length === 0" class="py-4 text-center text-[13px]" style="color: var(--hab-muted)">
                        Nothing due in the next 7 days
                    </div>

                    <div v-for="(bill, i) in upcomingBills" :key="bill.id">
                        <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px]"
                                :style="{ background: bill.bg }"
                            >
                                <span class="material-symbols-rounded" :style="{ fontSize: '20px', color: bill.color }">
                                    {{ bill.icon }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-[14px] font-medium" style="color: var(--hab-ink)">{{ bill.name }}</div>
                                <div class="text-[12px]" :style="{ color: bill.daysUntil <= 1 ? '#c0512c' : 'var(--hab-muted)' }">
                                    {{ bill.dueLabel }}
                                </div>
                            </div>
                            <div class="text-right font-['Bricolage_Grotesque'] text-[15px] font-bold" style="color: var(--hab-ink)">
                                {{ formatMoney(bill.amount) }}
                            </div>

                            <template v-if="bill.type === 'recurring'">
                                <button
                                    v-if="payingBillId !== Number(bill.id.replace('rb-', ''))"
                                    type="button"
                                    class="ml-2 shrink-0 rounded-full px-3 py-1.5 text-[12px] font-semibold"
                                    style="background: var(--hab-chip-fill); color: var(--hab-accent)"
                                    @click="openPayRow(bill)"
                                >
                                    Mark paid
                                </button>
                            </template>
                            <Link
                                v-else
                                href="/set-asides"
                                class="ml-2 shrink-0 rounded-full px-3 py-1.5 text-[12px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-accent)"
                            >
                                View
                            </Link>
                        </div>

                        <!-- Inline lightweight mark-paid form -->
                        <div
                            v-if="bill.type === 'recurring' && payingBillId === Number(bill.id.replace('rb-', ''))"
                            class="mt-2 flex flex-wrap items-center gap-2 rounded-[12px] p-3"
                            style="background: var(--hab-chip-fill)"
                        >
                            <select
                                v-model.number="payForm.account_id"
                                class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                                style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)"
                            >
                                <option v-for="acct in spendingAccounts" :key="acct.id" :value="acct.id">{{ acct.name }}</option>
                            </select>
                            <input
                                v-model="payForm.transacted_at"
                                type="date"
                                class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                                style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)"
                            />
                            <button
                                type="button"
                                class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                                style="background: var(--hab-accent)"
                                :disabled="payForm.processing"
                                @click="submitPayRow(bill)"
                            >
                                Confirm
                            </button>
                            <button
                                type="button"
                                class="rounded-full px-3 py-1.5 text-[12px] font-medium"
                                style="color: var(--hab-muted)"
                                :disabled="payForm.processing"
                                @click="cancelPayRow"
                            >
                                Cancel
                            </button>
                            <div v-if="payForm.errors.account_id" class="w-full text-[12px]" style="color: #c0512c">
                                {{ payForm.errors.account_id }}
                            </div>
                            <div v-if="payForm.errors.amount" class="w-full text-[12px]" style="color: #c0512c">
                                {{ payForm.errors.amount }}
                            </div>
                            <div v-if="payForm.errors.transacted_at" class="w-full text-[12px]" style="color: #c0512c">
                                {{ payForm.errors.transacted_at }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Categories over budget -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                        Categories over budget
                    </h2>

                    <div v-if="categoriesOverBudget.length === 0" class="py-4 text-center text-[13px]" style="color: var(--hab-muted)">
                        Every category is within its limit
                    </div>

                    <Link
                        v-for="(cat, i) in categoriesOverBudget"
                        :key="cat.id"
                        :href="`/settings/categories?edit=${cat.id}`"
                        class="block"
                    >
                        <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>
                        <div class="flex items-center gap-3 py-1">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px]"
                                :style="{ background: cat.color + '22' }"
                            >
                                <span class="material-symbols-rounded" :style="{ fontSize: '20px', color: cat.color }">
                                    {{ cat.icon }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-[14px] font-medium" style="color: var(--hab-ink)">{{ cat.name }}</div>
                                <div class="text-[12px]" style="color: #c0512c">
                                    {{ formatMoney(cat.spent) }} of {{ formatMoney(cat.limit_amount) }}
                                </div>
                            </div>
                            <span class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)">chevron_right</span>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ── Right column ── -->
            <div class="flex flex-col gap-5">

                <!-- Debt paid this cycle -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <h2 class="mb-3 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">
                        Debt paid this cycle
                    </h2>
                    <p class="font-['Bricolage_Grotesque'] text-[26px] font-bold" style="color: var(--hab-ink)">
                        {{ formatMoney(debtPaid) }}
                    </p>

                    <div class="mt-3 border-t pt-3" style="border-color: var(--hab-hairline)">
                        <template v-if="totalDebtRemaining <= 0">
                            <p class="text-[13px]" style="color: var(--hab-muted)">No outstanding debt.</p>
                        </template>
                        <template v-else-if="debtOnTrack && debtFreeDate">
                            <p class="text-[13px]" style="color: var(--hab-muted)">
                                Debt-free by <span class="font-semibold" style="color: var(--hab-ink)">{{ debtFreeDate }}</span>
                                <span v-if="debtFreeMonths !== null"> ({{ debtFreeMonths }} mo)</span>
                            </p>
                        </template>
                        <template v-else>
                            <p class="text-[13px]" style="color: #c0512c">
                                Not on track — current payments don't cover accruing interest.
                            </p>
                        </template>
                    </div>
                </div>

                <!-- Set aside this cycle -->
                <Link
                    href="/set-asides"
                    class="block rounded-[22px] p-5 transition-opacity hover:opacity-90"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">
                            Set aside this cycle
                        </h2>
                        <span class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)">chevron_right</span>
                    </div>
                    <p class="font-['Bricolage_Grotesque'] text-[26px] font-bold" style="color: var(--hab-ink)">
                        {{ formatMoney(setAsidesHeld) }}
                    </p>
                </Link>

                <!-- Where this cycle's money goes -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                        Where this cycle's money goes
                    </h2>

                    <div v-if="breakdownTotal <= 0" class="py-4 text-center text-[13px]" style="color: var(--hab-muted)">
                        Nothing tracked yet this cycle
                    </div>

                    <div v-else class="flex flex-col items-center gap-5">
                        <div class="relative shrink-0" style="width: 160px; height: 160px">
                            <svg viewBox="0 0 160 160" width="160" height="160" role="img" :aria-label="breakdownAriaLabel">
                                <path
                                    v-for="seg in donutSegments"
                                    :key="seg.key"
                                    :d="arcPath(80, 80, 64, seg.start, seg.end)"
                                    fill="none"
                                    :stroke="seg.color"
                                    stroke-width="24"
                                    stroke-linecap="butt"
                                >
                                    <title>{{ seg.label }}: {{ formatMoney(seg.value) }} ({{ Math.round(seg.percent) }}%)</title>
                                </path>
                            </svg>
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                <p class="font-['Bricolage_Grotesque'] text-[19px] font-bold leading-none" style="color: var(--hab-ink)">
                                    {{ formatMoney(breakdownTotal) }}
                                </p>
                                <p class="mt-1.5 text-[11px]" style="color: var(--hab-muted)">this cycle</p>
                            </div>
                        </div>

                        <div class="w-full">
                            <div v-for="(item, i) in breakdownItems" :key="item.key">
                                <div v-if="i > 0" class="h-px" style="background: var(--hab-hairline)"></div>
                                <div class="flex items-center gap-3 py-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: item.color }"></span>
                                    <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium" style="color: var(--hab-ink)">
                                        {{ item.label }}
                                    </span>
                                    <span class="shrink-0 text-[13px] font-semibold" style="color: var(--hab-ink)">
                                        {{ formatMoney(item.value) }}
                                    </span>
                                    <span class="w-9 shrink-0 text-right text-[12px]" style="color: var(--hab-muted)">
                                        {{ percentOf(item.value) }}%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </TooltipProvider>
</template>
