<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { index } from '@/actions/App/Http/Controllers/FinanceTrajectoryController';
import { formatMoney } from '@/components/trajectory/format';
import type { DebtSummary } from '@/pages/Trajectory.vue';

const props = defineProps<{ debt: DebtSummary }>();

const extra = ref<string>(props.debt.extraMonthly ? String(props.debt.extraMonthly) : '');

watch(() => props.debt.extraMonthly, value => {
    extra.value = value ? String(value) : '';
});

function applyExtra() {
    const parsed = Number(extra.value);
    const value = Number.isFinite(parsed) && parsed > 0 ? parsed : 0;

    router.get(index({ query: { extra_monthly: value } }).url, {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Debt free</div>

        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; margin-top: 4px"
             :style="{ color: debt.onTrack ? '#211d17' : '#c0512c' }">
            {{ debt.debtFreeDate ?? 'Not on this plan' }}
        </div>

        <div v-if="!debt.onTrack" style="font-size: 13px; color: #c0512c; margin-top: 4px">
            At least one debt's payment doesn't cover its own interest.
        </div>

        <div v-else-if="debt.monthsSaved !== null && debt.monthsSaved > 0" style="font-size: 13px; color: #2f7d5b; margin-top: 4px">
            {{ debt.monthsSaved }} month(s) sooner than the baseline plan.
        </div>

        <!-- A computed-but-zero saving is a real answer, not the absence of one:
             without this the input silently looks like it did nothing. -->
        <div v-else-if="debt.monthsSaved === 0" style="font-size: 13px; color: #6b6355; margin-top: 4px">
            That extra payment doesn't move the payoff date yet — try a larger amount.
        </div>

        <div style="display: flex; align-items: center; gap: 8px; margin-top: 14px; flex-wrap: wrap">
            <label for="extra-monthly" style="font-size: 13px; color: #6b6355">What if I paid extra each month?</label>
            <input
                id="extra-monthly"
                v-model="extra"
                type="number"
                min="0"
                step="100"
                inputmode="decimal"
                @keyup.enter="applyExtra"
                style="width: 130px; height: 36px; padding: 0 10px; border: 1px solid #e0dacd; border-radius: 10px; background: #fdfbf5; font-size: 14px; color: #211d17"
            />
            <button
                type="button"
                @click="applyExtra"
                style="height: 36px; padding: 0 16px; border: none; border-radius: 10px; background: #211d17; color: #fdfbf5; font-size: 13.5px; font-weight: 600; cursor: pointer"
            >Apply</button>
        </div>

        <div style="font-size: 12px; color: #8a8172; margin-top: 10px">
            Remaining {{ formatMoney(debt.totalRemaining) }} · Scheduled {{ formatMoney(debt.totalMonthly) }}/mo
        </div>
    </section>
</template>
