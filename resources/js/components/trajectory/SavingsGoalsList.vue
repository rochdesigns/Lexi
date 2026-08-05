<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { GoalRow } from '@/pages/Trajectory.vue';

defineProps<{ goals: GoalRow[] }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Savings goals</div>

        <UnavailableNote v-if="goals.length === 0" reason="No open savings goals right now." />

        <div v-else style="display: flex; flex-direction: column; gap: 14px; margin-top: 12px">
            <div v-for="goal in goals" :key="goal.id">
                <div style="display: flex; align-items: center; gap: 8px">
                    <span class="material-symbols-rounded" aria-hidden="true"
                          :style="{ fontSize: '18px', color: goal.color, background: goal.bg, borderRadius: '8px', padding: '4px' }">{{ goal.icon }}</span>
                    <span style="font-weight: 600; color: #211d17; font-size: 14px">{{ goal.name }}</span>
                    <span style="margin-left: auto; font-size: 13px; color: #6b6355">
                        {{ formatMoney(goal.saved) }} / {{ formatMoney(goal.target) }}
                    </span>
                </div>

                <div style="height: 8px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 8px">
                    <div :style="{ width: `${Math.min(100, goal.progressPct)}%`, height: '100%', background: goal.color }" />
                </div>

                <div style="font-size: 12.5px; margin-top: 6px" :style="{ color: goal.onTrack === false ? '#c0512c' : '#8a8172' }">
                    <template v-if="goal.requiredMonthly !== null">
                        Needs {{ formatMoney(goal.requiredMonthly) }}/mo for {{ goal.monthsRemaining }} month(s) ·
                        putting in {{ formatMoney(goal.perMonth) }}/mo
                    </template>
                    <template v-else>
                        No target date · putting in {{ formatMoney(goal.perMonth) }}/mo
                    </template>
                </div>
            </div>
        </div>
    </section>
</template>
