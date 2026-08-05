<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import { formatMoney, formatSigned } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { SignatureMetric } from '@/pages/Trajectory.vue';

const props = defineProps<{ signature: SignatureMetric }>();

const labels = computed(() => (props.signature.trend ?? []).map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Investment gain minus debt interest',
        data: (props.signature.trend ?? []).map(point => point.value),
        borderColor: '#211d17',
        backgroundColor: 'rgba(33, 29, 23, 0.08)',
        fill: true,
    },
]);

const winning = computed(() => (props.signature.currentValue ?? 0) > 0);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">
            Is your money outrunning your debt?
        </div>

        <template v-if="signature.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 30px; letter-spacing: -.02em; margin-top: 4px"
                 :style="{ color: winning ? '#2f7d5b' : '#c0512c' }">
                {{ formatSigned(signature.currentValue ?? 0) }}
            </div>
            <div style="font-size: 13.5px; color: #6b6355; margin-top: 4px">
                {{ winning
                    ? 'Your investments earned more this month than your debt cost you.'
                    : 'Your debt cost you more this month than your investments earned.' }}
            </div>
            <div v-if="signature.crossedZeroLabel" style="display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 4px 10px; border-radius: 999px; background: rgba(47, 125, 91, 0.12)">
                <span class="material-symbols-rounded" aria-hidden="true" style="font-size: 16px; color: #2f7d5b">celebration</span>
                <span style="font-size: 12.5px; color: #2f7d5b; font-weight: 600">First pulled ahead in {{ signature.crossedZeroLabel }}</span>
            </div>

            <div style="margin-top: 14px">
                <ReportLineChart :labels="labels" :datasets="datasets" ariaLabel="Line chart of investment gains minus debt interest, over the last 12 months" />
            </div>

            <div v-if="signature.trend.length > 0" style="font-size: 12px; color: #8a8172; margin-top: 8px">
                Latest month: {{ formatMoney(signature.trend[signature.trend.length - 1].investmentGain) }} gained,
                {{ formatMoney(signature.trend[signature.trend.length - 1].debtInterest) }} paid in interest.
            </div>
        </template>
        <UnavailableNote v-else :reason="signature.reason" />
    </section>
</template>
