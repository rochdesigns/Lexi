<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { Fire } from '@/pages/Trajectory.vue';

defineProps<{ fire: Fire; totalValue: number }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Financial independence</div>

        <template v-if="fire.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #211d17; margin-top: 4px">
                {{ (fire.progressPct ?? 0).toFixed(1) }}%
                <span style="font-size: 15px; font-weight: 500; color: #8a8172">
                    of {{ formatMoney(fire.fiNumber ?? 0) }}
                </span>
            </div>

            <div style="height: 10px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 12px">
                <div :style="{ width: `${Math.min(100, fire.progressPct ?? 0)}%`, height: '100%', background: '#2f7d5b' }" />
            </div>

            <div style="font-size: 13px; color: #6b6355; margin-top: 10px">
                {{ formatMoney(totalValue) }} invested ·
                {{ fire.yearsToFi === null ? 'not reachable at the current pace' : `about ${fire.yearsToFi} year(s) away` }}
            </div>
            <div style="font-size: 12px; color: #8a8172; margin-top: 4px">
                Assuming {{ fire.expectedReturnRate }}% a year and {{ formatMoney(fire.avgMonthlyContribution) }}/mo.
            </div>
        </template>
        <UnavailableNote v-else :reason="fire.reason" />
    </section>
</template>
