<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';

defineProps<{ interestThisMonth: number; interestYearToDate: number }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Interest bleeding out</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #c0512c; margin-top: 4px">
            {{ formatMoney(interestThisMonth) }}
        </div>
        <div style="font-size: 13px; color: #6b6355; margin-top: 4px">this month</div>
        <div style="font-size: 13px; color: #8a8172; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0dacd">
            {{ formatMoney(interestYearToDate) }} so far this year
        </div>
    </section>
</template>
