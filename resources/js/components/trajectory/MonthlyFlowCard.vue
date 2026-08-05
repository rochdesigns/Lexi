<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney, formatPct } from '@/components/trajectory/format';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { MonthlyFlow } from '@/pages/Trajectory.vue';

const props = defineProps<{ monthlyFlow: MonthlyFlow }>();

const fixedWidth = computed(() => {
    const split = props.monthlyFlow.fixedVsVariable;

    if (!split) {
        return 0;
    }

    const total = split.fixed + split.variable;

    return total > 0 ? (split.fixed / total) * 100 : 0;
});
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">
            This month · {{ monthlyFlow.monthLabel ?? '' }}
        </div>

        <template v-if="monthlyFlow.available">
            <div style="display: flex; gap: 18px; flex-wrap: wrap; margin-top: 8px">
                <div>
                    <div style="font-size: 12px; color: #8a8172">In</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #2f7d5b">{{ formatMoney(monthlyFlow.income) }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #8a8172">Out</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #c0512c">{{ formatMoney(monthlyFlow.expenses) }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #8a8172">Net</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px"
                         :style="{ color: monthlyFlow.net >= 0 ? '#2f7d5b' : '#c0512c' }">{{ formatMoney(monthlyFlow.net) }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #8a8172">Budget used</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #211d17">{{ formatPct(monthlyFlow.budgetUsedPct) }}</div>
                </div>
            </div>

            <div style="margin-top: 16px">
                <div style="font-size: 12px; color: #8a8172">Fixed vs variable spending</div>
                <div style="display: flex; height: 12px; border-radius: 999px; overflow: hidden; background: #f0ebe0; margin-top: 6px">
                    <div :style="{ width: `${fixedWidth}%`, background: '#6b6355' }" />
                    <div :style="{ width: `${100 - fixedWidth}%`, background: '#d98a2b' }" />
                </div>
                <div style="font-size: 12.5px; color: #6b6355; margin-top: 6px">
                    Fixed {{ formatMoney(monthlyFlow.fixedVsVariable.fixed) }} ·
                    Variable {{ formatMoney(monthlyFlow.fixedVsVariable.variable) }}
                </div>
            </div>

            <div v-if="monthlyFlow.overBudget.top.length > 0" style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e0dacd">
                <div style="font-size: 12px; color: #8a8172">Furthest over budget</div>
                <div v-for="row in monthlyFlow.overBudget.top" :key="row.id"
                     style="display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 13.5px">
                    <span class="material-symbols-rounded" aria-hidden="true" :style="{ fontSize: '18px', color: row.color }">{{ row.icon }}</span>
                    <span style="color: #211d17; font-weight: 600">{{ row.name }}</span>
                    <span style="margin-left: auto; color: #c0512c; font-weight: 600">
                        +{{ formatMoney(row.overAmount) }} ({{ row.overPct.toFixed(0) }}%)
                    </span>
                </div>
                <div v-if="monthlyFlow.overBudget.remainingCount > 0" style="font-size: 12.5px; color: #8a8172; margin-top: 8px">
                    and {{ monthlyFlow.overBudget.remainingCount }} more over by {{ formatMoney(monthlyFlow.overBudget.remainingTotal) }} in total
                </div>
            </div>
        </template>
        <UnavailableNote v-else :reason="monthlyFlow.reason" />
    </section>
</template>
