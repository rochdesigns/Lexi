<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/components/trajectory/format';

const props = defineProps<{
    contributionsThisMonth: number;
    contributionTarget: number | null;
    contributionProgressPct: number | null;
}>();

const met = computed(() => (props.contributionProgressPct ?? 0) >= 100);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Invested this month</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #211d17; margin-top: 4px">
            {{ formatMoney(contributionsThisMonth) }}
        </div>

        <template v-if="contributionTarget !== null">
            <div style="height: 10px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 12px">
                <div :style="{
                    width: `${Math.min(100, contributionProgressPct ?? 0)}%`,
                    height: '100%',
                    background: met ? '#2f7d5b' : '#d98a2b',
                }" />
            </div>
            <div style="font-size: 13px; color: #6b6355; margin-top: 8px">
                Target {{ formatMoney(contributionTarget) }}/mo
            </div>
        </template>
        <div v-else style="font-size: 13px; color: #8a8172; margin-top: 8px">
            Set a monthly investing target in settings to track this.
        </div>
    </section>
</template>
