<script setup lang="ts">
import { computed } from 'vue';
import ReportBarChart from '@/components/reports/ReportBarChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { SplitPoint } from '@/pages/Trajectory.vue';

const props = defineProps<{ trend: SplitPoint[] }>();

const hasData = computed(() => props.trend.some(point => point.principal > 0 || point.interest > 0));
const labels = computed(() => props.trend.map(point => point.label));

const datasets = computed(() => [
    { label: 'Principal', data: props.trend.map(p => p.principal), backgroundColor: '#2f7d5b', borderRadius: 4 },
    { label: 'Interest', data: props.trend.map(p => p.interest), backgroundColor: '#c0512c', borderRadius: 4 },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Principal vs interest</div>
        <div v-if="hasData" style="margin-top: 12px">
            <ReportBarChart :labels="labels" :datasets="datasets" :show-legend="true" />
        </div>
        <UnavailableNote v-else reason="No debt payments recorded yet — make a payment to start the split." />
    </section>
</template>
