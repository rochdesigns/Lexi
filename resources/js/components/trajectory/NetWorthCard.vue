<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import { formatMoney, formatSigned } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { NetWorth } from '@/pages/Trajectory.vue';

const props = defineProps<{ netWorth: NetWorth }>();

const labels = computed(() => props.netWorth.trend.map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Net worth',
        data: props.netWorth.trend.map(point => point.value),
        borderColor: '#2f7d5b',
        backgroundColor: 'rgba(47, 125, 91, 0.12)',
        fill: true,
    },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap">
            <div>
                <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Net worth</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 30px; letter-spacing: -.02em; margin-top: 4px"
                     :style="{ color: netWorth.current < 0 ? '#c0512c' : '#211d17' }">
                    {{ formatMoney(netWorth.current) }}
                </div>
            </div>

            <div style="margin-left: auto; text-align: right">
                <div style="font-size: 12px; color: #8a8172">Assets {{ formatMoney(netWorth.accountTotal) }}</div>
                <div style="font-size: 12px; color: #8a8172; margin-top: 2px">Debts {{ formatMoney(netWorth.debtTotal) }}</div>
                <div v-if="netWorth.available && netWorth.changeAmount !== undefined"
                     style="font-size: 13.5px; font-weight: 600; margin-top: 6px"
                     :style="{ color: netWorth.changeAmount >= 0 ? '#2f7d5b' : '#c0512c' }">
                    {{ formatSigned(netWorth.changeAmount) }}
                    <span v-if="netWorth.changePct !== null && netWorth.changePct !== undefined" style="color: #8a8172; font-weight: 500">
                        ({{ netWorth.changePct.toFixed(1) }}%)
                    </span>
                </div>
            </div>
        </div>

        <div v-if="netWorth.available" style="margin-top: 14px">
            <ReportLineChart :labels="labels" :datasets="datasets" ariaLabel="Line chart of your net worth over the last 12 months" />
        </div>
        <UnavailableNote v-else :reason="netWorth.reason" />
    </section>
</template>
