<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney, formatPct } from '@/components/trajectory/format';
import type { DebtToIncome } from '@/pages/Trajectory.vue';

const props = defineProps<{ dti: DebtToIncome }>();

const tone = computed(() => {
    if (props.dti.ratio === null) {
        return '#8a8172';
    }

    if (props.dti.ratio <= 20) {
        return '#2f7d5b';
    }

    if (props.dti.ratio <= 36) {
        return '#d98a2b';
    }

    return '#c0512c';
});
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Debt to income</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; margin-top: 4px" :style="{ color: tone }">
            {{ formatPct(dti.ratio) }}
        </div>
        <div style="font-size: 13px; color: #6b6355; margin-top: 4px">
            {{ formatMoney(dti.monthlyDebtPayments) }} of {{ formatMoney(dti.income) }}
            <span v-if="dti.isEstimated" style="color: #8a8172">(scheduled, no payments logged yet)</span>
        </div>

        <div style="display: flex; align-items: flex-end; gap: 6px; height: 46px; margin-top: 14px">
            <div v-for="point in dti.trend" :key="point.month" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px">
                <div :style="{
                    width: '100%',
                    height: `${Math.min(100, point.ratio ?? 0)}%`,
                    minHeight: '2px',
                    background: point.ratio === null ? '#e0dacd' : tone,
                    borderRadius: '4px 4px 0 0',
                }" />
            </div>
        </div>
        <div style="display: flex; gap: 6px; margin-top: 4px">
            <div v-for="point in dti.trend" :key="`${point.month}-label`" style="flex: 1; text-align: center; font-size: 10.5px; color: #8a8172">
                {{ point.label.slice(0, 3) }}
            </div>
        </div>
    </section>
</template>
