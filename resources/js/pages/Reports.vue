<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index } from '@/actions/App/Http/Controllers/ReportController';
import ReportBarChart from '@/components/reports/ReportBarChart.vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';

type PeriodType = 'month' | 'quarter' | 'year';

type Period = {
    type: PeriodType;
    label: string;
    prevDate: string;
    nextDate: string | null;
    isCurrent: boolean;
};

type Overview = {
    income: number;
    expenses: number;
    net: number;
    budgetTotal: number;
    budgetUsedPct: number;
    projectedTotal: number | null;
};

type SpendingItem = {
    category: { id: number | null; name: string; icon: string; color: string };
    amount: number;
    pct: number;
};

type BudgetVsActualItem = {
    category: { id: number; name: string; icon: string; color: string };
    budgeted: number;
    actual: number;
    status: 'over' | 'under';
};

type CashFlowBucket = { label: string; income: number | null; expenses: number | null; net: number | null };
type CashFlow = { series: CashFlowBucket[]; trendLine: (number | null)[] | null; budgetLine: (number | null)[] | null };

const props = defineProps<{
    period: Period;
    overview: Overview;
    spending: SpendingItem[];
    budgetVsActual: BudgetVsActualItem[];
    cashFlow: CashFlow;
}>();

const periodOptions: { value: PeriodType; label: string }[] = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
];

type Tab = 'spending' | 'budget' | 'cashflow' | 'payoff';

const activeTab = ref<Tab>('spending');

const tabOptions: { value: Tab; label: string }[] = [
    { value: 'spending', label: 'Spending' },
    { value: 'budget', label: 'Budget vs Actual' },
    { value: 'cashflow', label: 'Cash Flow' },
    { value: 'payoff', label: 'Payoff' },
];

const hasCashFlowData = computed(() =>
    props.cashFlow.series.some(b => (b.income ?? 0) > 0 || (b.expenses ?? 0) > 0) || props.cashFlow.trendLine !== null,
);

function switchPeriod(type: PeriodType) {
    router.get(index({ query: { period: type } }).url, {}, { preserveScroll: true, preserveState: true, replace: true });
}

function navigateDate(date: string) {
    router.get(index({ query: { period: props.period.type, date } }).url, {}, { preserveScroll: true, preserveState: true, replace: true });
}

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? `-${formatted}` : formatted;
}
</script>

<template>
    <Head title="Reports" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Reports</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Where your money's been, and where it's headed</p>
            </div>

            <div style="margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                <div style="display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                    <button
                        v-for="opt in periodOptions"
                        :key="opt.value"
                        type="button"
                        @click="switchPeriod(opt.value)"
                        :style="{
                            padding: '0 14px',
                            height: '38px',
                            border: 'none',
                            fontSize: '13.5px',
                            fontWeight: 600,
                            cursor: 'pointer',
                            background: period.type === opt.value ? '#211d17' : 'transparent',
                            color: period.type === opt.value ? '#fdfbf5' : '#6b6355',
                        }"
                    >{{ opt.label }}</button>
                </div>

                <div style="display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                    <button type="button" @click="navigateDate(period.prevDate)"
                        style="width: 38px; height: 38px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                        <span class="material-symbols-rounded" style="font-size: 20px">chevron_left</span>
                    </button>
                    <span style="padding: 0 12px; font-size: 13.5px; font-weight: 600; color: #211d17; border-left: 1px solid #e0dacd; border-right: 1px solid #e0dacd; height: 38px; display: flex; align-items: center">
                        {{ period.label }}
                    </span>
                    <button type="button"
                        @click="period.nextDate && navigateDate(period.nextDate)"
                        :disabled="!period.nextDate"
                        :style="{
                            width: '38px', height: '38px', border: 'none', background: 'transparent',
                            display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#6b6355',
                            opacity: period.nextDate ? 1 : 0.35, cursor: period.nextDate ? 'pointer' : 'default',
                        }">
                        <span class="material-symbols-rounded" style="font-size: 20px">chevron_right</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Overview stat cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
                <div style="font-size: 12px; color: #8a8172">Income</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #2f7d5b; margin-top: 4px">{{ formatMoney(overview.income) }}</div>
            </div>
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
                <div style="font-size: 12px; color: #8a8172">Expenses</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #c0512c; margin-top: 4px">{{ formatMoney(overview.expenses) }}</div>
            </div>
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
                <div style="font-size: 12px; color: #8a8172">Net</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; margin-top: 4px" :style="{ color: overview.net >= 0 ? '#2f7d5b' : '#c0512c' }">{{ formatMoney(overview.net) }}</div>
            </div>
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
                <div style="font-size: 12px; color: #8a8172">Budget used</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; margin-top: 4px">{{ Math.round(overview.budgetUsedPct * 100) }}%</div>
            </div>
            <div v-if="overview.projectedTotal !== null" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
                <div style="font-size: 12px; color: #8a8172">Projected total</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; margin-top: 4px">{{ formatMoney(overview.projectedTotal) }}</div>
            </div>
        </div>

        <!-- Tab bar -->
        <div style="display: flex; gap: 6px; border-bottom: 1px solid #e0dacd; padding-bottom: 2px">
            <button
                v-for="tab in tabOptions"
                :key="tab.value"
                type="button"
                @click="activeTab = tab.value"
                :style="{
                    padding: '8px 14px',
                    border: 'none',
                    background: 'transparent',
                    fontSize: '13.5px',
                    fontWeight: 600,
                    cursor: 'pointer',
                    color: activeTab === tab.value ? '#211d17' : '#8a8172',
                    borderBottom: activeTab === tab.value ? '2px solid #211d17' : '2px solid transparent',
                }"
            >{{ tab.label }}</button>
        </div>

        <!-- Spending tab -->
        <div v-if="activeTab === 'spending'" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
            <div v-if="spending.length === 0" style="text-align: center; padding: 24px 0; color: #8a8172; font-size: 14px">
                No transactions in this period yet
            </div>
            <template v-else>
                <ReportBarChart
                    :labels="spending.map(s => s.category.name)"
                    :datasets="[{ data: spending.map(s => s.amount), backgroundColor: spending.map(s => s.category.color), borderRadius: 6 }]"
                />
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 16px">
                    <div v-for="item in spending" :key="item.category.id ?? 'uncategorized'" style="display: flex; align-items: center; gap: 10px">
                        <span class="material-symbols-rounded" style="font-size: 18px" :style="{ color: item.category.color }">{{ item.category.icon }}</span>
                        <span style="font-size: 13.5px; font-weight: 500; flex: 1">{{ item.category.name }}</span>
                        <span style="font-size: 12px; color: #8a8172">{{ Math.round(item.pct * 100) }}%</span>
                        <span style="font-size: 13.5px; font-weight: 700; width: 100px; text-align: right">{{ formatMoney(item.amount) }}</span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Budget vs Actual tab -->
        <div v-if="activeTab === 'budget'" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
            <div v-if="budgetVsActual.length === 0" style="text-align: center; padding: 24px 0; color: #8a8172; font-size: 14px">
                No budget categories yet
            </div>
            <template v-else>
                <ReportBarChart
                    :labels="budgetVsActual.map(b => b.category.name)"
                    :datasets="[
                        { label: 'Budgeted', data: budgetVsActual.map(b => b.budgeted), backgroundColor: '#c3d1c9', borderRadius: 6 },
                        { label: 'Actual', data: budgetVsActual.map(b => b.actual), backgroundColor: budgetVsActual.map(b => b.status === 'over' ? '#c0512c' : '#2f7d5b'), borderRadius: 6 },
                    ]"
                    :show-legend="true"
                />
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 16px">
                    <div v-for="item in budgetVsActual" :key="item.category.id" style="display: flex; align-items: center; gap: 10px">
                        <span class="material-symbols-rounded" style="font-size: 18px" :style="{ color: item.category.color }">{{ item.category.icon }}</span>
                        <span style="font-size: 13.5px; font-weight: 500; flex: 1">{{ item.category.name }}</span>
                        <span
                            style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px"
                            :style="item.status === 'over' ? { background: '#fbe2d6', color: '#c0512c' } : { background: '#dff3e6', color: '#2f7d5b' }"
                        >{{ item.status === 'over' ? 'Over' : 'Under' }}</span>
                        <span style="font-size: 13.5px; font-weight: 700; width: 140px; text-align: right">{{ formatMoney(item.actual) }} / {{ formatMoney(item.budgeted) }}</span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Cash Flow tab -->
        <div v-if="activeTab === 'cashflow'" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
            <div v-if="!hasCashFlowData" style="text-align: center; padding: 24px 0; color: #8a8172; font-size: 14px">
                No transactions in this period yet
            </div>
            <ReportLineChart
                v-else
                :labels="cashFlow.series.map(b => b.label)"
                :datasets="[
                    { label: 'Net', data: cashFlow.series.map(b => b.net), borderColor: '#211d17', backgroundColor: '#211d17' },
                    ...(cashFlow.trendLine ? [{ label: 'Trend projection', data: cashFlow.trendLine, borderColor: '#3f7fb0', borderDash: [6, 4], spanGaps: false }] : []),
                    ...(cashFlow.budgetLine ? [{ label: 'Budget projection', data: cashFlow.budgetLine, borderColor: '#2f7d5b', borderDash: [6, 4], spanGaps: false }] : []),
                ]"
            />
        </div>
    </div>
</template>
