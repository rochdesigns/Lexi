<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import { formatMoney, formatPct } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { SavingsRate } from '@/pages/Trajectory.vue';

const props = defineProps<{ savingsRate: SavingsRate }>();

const labels = computed(() => (props.savingsRate.trend ?? []).map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Savings rate %',
        data: (props.savingsRate.trend ?? []).map(point => point.rate),
        borderColor: '#2f7d5b',
        spanGaps: true,
    },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Savings rate</div>

        <template v-if="savingsRate.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; margin-top: 4px"
                 :style="{ color: (savingsRate.rate ?? 0) >= 0 ? '#2f7d5b' : '#c0512c' }">
                {{ formatPct(savingsRate.rate) }}
            </div>
            <div style="font-size: 13px; color: #6b6355; margin-top: 4px">
                Kept {{ formatMoney(savingsRate.net) }} of {{ formatMoney(savingsRate.income) }}
                · 6-month average {{ formatPct(savingsRate.averageRate) }}
            </div>
            <div style="margin-top: 12px">
                <ReportLineChart :labels="labels" :datasets="datasets" />
            </div>
        </template>
        <UnavailableNote v-else :reason="savingsRate.reason" />
    </section>
</template>
