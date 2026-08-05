<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DebtFreeCard from '@/components/trajectory/DebtFreeCard.vue';
import DebtTable from '@/components/trajectory/DebtTable.vue';
import DtiCard from '@/components/trajectory/DtiCard.vue';
import InterestBleedingCard from '@/components/trajectory/InterestBleedingCard.vue';
import NetWorthCard from '@/components/trajectory/NetWorthCard.vue';
import PrincipalInterestTrend from '@/components/trajectory/PrincipalInterestTrend.vue';
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

defineProps<{
    netWorth: NetWorth;
    extraMonthly: number;
    debt: DebtSummary;
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
    </div>
</template>
