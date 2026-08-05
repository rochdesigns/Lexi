<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NetWorthCard from '@/components/trajectory/NetWorthCard.vue';

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

defineProps<{
    netWorth: NetWorth;
    extraMonthly: number;
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
    </div>
</template>
