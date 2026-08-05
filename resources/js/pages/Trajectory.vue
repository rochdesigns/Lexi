<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AllocationChart from '@/components/trajectory/AllocationChart.vue';
import CashBufferTrend from '@/components/trajectory/CashBufferTrend.vue';
import DebtFreeCard from '@/components/trajectory/DebtFreeCard.vue';
import DebtTable from '@/components/trajectory/DebtTable.vue';
import DtiCard from '@/components/trajectory/DtiCard.vue';
import EmergencyFundProgress from '@/components/trajectory/EmergencyFundProgress.vue';
import FireProgress from '@/components/trajectory/FireProgress.vue';
import InterestBleedingCard from '@/components/trajectory/InterestBleedingCard.vue';
import InvestmentContributions from '@/components/trajectory/InvestmentContributions.vue';
import InvestmentVehicleTable from '@/components/trajectory/InvestmentVehicleTable.vue';
import NetWorthCard from '@/components/trajectory/NetWorthCard.vue';
import PrincipalInterestTrend from '@/components/trajectory/PrincipalInterestTrend.vue';
import SavingsGoalsList from '@/components/trajectory/SavingsGoalsList.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';

export type TrendPoint = { month: string; label: string; value: number };

export type NetWorth = {
    available: boolean;
    reason?: string;
    current: number;
    accountTotal: number;
    debtTotal: number;
    trend: TrendPoint[];
    changeAmount?: number;
    changePct?: number | null;
};

export type DebtRow = {
    id: number; name: string; icon: string; color: string; bg: string;
    balance: number; perMonth: number;
    interestRate: number | null; interestRatePeriod: string;
    months: number | null; payoffDate: string | null;
};

export type SplitPoint = { month: string; label: string; principal: number; interest: number };

export type DebtToIncome = {
    ratio: number | null; monthlyDebtPayments: number; income: number; isEstimated: boolean;
    trend: { month: string; label: string; ratio: number | null }[];
};

export type DebtSummary = {
    available: boolean; reason?: string;
    totalRemaining: number; totalMonthly: number; extraMonthly: number;
    debts: DebtRow[];
    debtFreeMonths: number | null; debtFreeDate: string | null; onTrack: boolean;
    baselineDebtFreeMonths: number | null; monthsSaved: number | null;
    interestThisMonth: number; interestYearToDate: number;
    principalInterestTrend: SplitPoint[];
    debtToIncome: DebtToIncome;
};

export type EmergencyFund = {
    available: boolean; reason?: string;
    balance: number; avgMonthlyExpenses: number;
    monthsCovered: number | null; targetMonths: number; progressPct: number | null;
};

export type GoalRow = {
    id: number; name: string; icon: string; color: string; bg: string;
    saved: number; target: number; progressPct: number; perMonth: number;
    byDate: string | null; monthsRemaining: number | null; requiredMonthly: number | null; onTrack: boolean | null;
};

export type CashBuffer = { available: boolean; reason?: string; trend: TrendPoint[] };

export type SavingsSummary = {
    available: boolean; reason?: string;
    emergencyFund: EmergencyFund; goals: GoalRow[]; cashBuffer: CashBuffer;
};

export type Vehicle = {
    id: number; name: string; institution: string | null;
    value: number; costBasis: number | null; gainLoss: number | null; allocationPct: number;
};

export type Fire = {
    available: boolean; reason?: string;
    fiNumber: number | null; progressPct: number | null;
    expectedReturnRate: number; avgMonthlyContribution: number; yearsToFi: number | null;
};

export type InvestmentsSummary = {
    available: boolean; reason?: string;
    totalValue: number; totalCostBasis: number; costBasisComplete: boolean;
    gainLoss: number | null; gainLossPct: number | null;
    contributionsThisMonth: number; contributionTarget: number | null; contributionProgressPct: number | null;
    vehicles: Vehicle[]; fire: Fire;
};

defineProps<{
    netWorth: NetWorth;
    extraMonthly: number;
    debt: DebtSummary;
    savings: SavingsSummary;
    investments: InvestmentsSummary;
}>();
</script>

<template>
    <Head title="Trajectory" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">
        <div>
            <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Trajectory</h1>
            <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Are you actually getting ahead?</p>
        </div>

        <NetWorthCard :net-worth="netWorth" />

        <template v-if="debt.available">
            <div class="grid gap-3 lg:grid-cols-3">
                <DebtFreeCard :debt="debt" />
                <InterestBleedingCard :interest-this-month="debt.interestThisMonth" :interest-year-to-date="debt.interestYearToDate" />
                <DtiCard :dti="debt.debtToIncome" />
            </div>
            <DebtTable :debts="debt.debts" />
            <PrincipalInterestTrend :trend="debt.principalInterestTrend" />
        </template>
        <UnavailableNote v-else :reason="debt.reason" />

        <div class="grid gap-3 lg:grid-cols-2">
            <EmergencyFundProgress :emergency-fund="savings.emergencyFund" />
            <SavingsGoalsList :goals="savings.goals" />
        </div>
        <CashBufferTrend :cash-buffer="savings.cashBuffer" />

        <template v-if="investments.available">
            <div class="grid gap-3 lg:grid-cols-2">
                <InvestmentContributions
                    :contributions-this-month="investments.contributionsThisMonth"
                    :contribution-target="investments.contributionTarget"
                    :contribution-progress-pct="investments.contributionProgressPct"
                />
                <FireProgress :fire="investments.fire" :total-value="investments.totalValue" />
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <InvestmentVehicleTable :vehicles="investments.vehicles" :cost-basis-complete="investments.costBasisComplete" />
                <AllocationChart :vehicles="investments.vehicles" />
            </div>
        </template>
        <UnavailableNote v-else :reason="investments.reason" />
    </div>
</template>
