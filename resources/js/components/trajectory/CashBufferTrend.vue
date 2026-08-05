<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { CashBuffer } from '@/pages/Trajectory.vue';

const props = defineProps<{ cashBuffer: CashBuffer }>();

const labels = computed(() => props.cashBuffer.trend.map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Lowest cash balance',
        data: props.cashBuffer.trend.map(point => point.value),
        borderColor: '#3f7fb0',
        backgroundColor: 'rgba(63, 127, 176, 0.12)',
        fill: true,
    },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Cash buffer low point</div>
        <div v-if="cashBuffer.available" style="margin-top: 12px">
            <ReportLineChart :labels="labels" :datasets="datasets" />
        </div>
        <UnavailableNote v-else :reason="cashBuffer.reason" />
    </section>
</template>
