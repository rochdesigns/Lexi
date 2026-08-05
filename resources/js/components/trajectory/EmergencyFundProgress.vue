<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { EmergencyFund } from '@/pages/Trajectory.vue';

defineProps<{ emergencyFund: EmergencyFund }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Emergency fund</div>

        <template v-if="emergencyFund.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #211d17; margin-top: 4px">
                {{ emergencyFund.monthsCovered === null ? '—' : `${emergencyFund.monthsCovered.toFixed(1)} months` }}
                <span style="font-size: 15px; font-weight: 500; color: #8a8172">of {{ emergencyFund.targetMonths }}</span>
            </div>

            <div style="height: 10px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 12px">
                <div :style="{
                    width: `${emergencyFund.progressPct ?? 0}%`,
                    height: '100%',
                    background: (emergencyFund.progressPct ?? 0) >= 100 ? '#2f7d5b' : '#d98a2b',
                }" />
            </div>

            <div style="font-size: 13px; color: #6b6355; margin-top: 10px">
                {{ formatMoney(emergencyFund.balance) }} saved · {{ formatMoney(emergencyFund.avgMonthlyExpenses) }}/mo average spend
            </div>
        </template>
        <UnavailableNote v-else :reason="emergencyFund.reason" />
    </section>
</template>
