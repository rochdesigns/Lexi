<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';
import type { DebtRow } from '@/pages/Trajectory.vue';

defineProps<{ debts: DebtRow[] }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Highest interest first</div>

        <div style="margin-top: 12px; overflow-x: auto">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 520px">
                <thead>
                    <tr style="color: #8a8172; text-align: left">
                        <th style="padding: 6px 8px; font-weight: 500">Debt</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Rate</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Balance</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Monthly</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Paid off</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in debts" :key="row.id" style="border-top: 1px solid #e0dacd">
                        <td style="padding: 9px 8px">
                            <span style="display: inline-flex; align-items: center; gap: 8px">
                                <span class="material-symbols-rounded" aria-hidden="true"
                                      :style="{ fontSize: '18px', color: row.color, background: row.bg, borderRadius: '8px', padding: '4px' }">{{ row.icon }}</span>
                                <span style="color: #211d17; font-weight: 600">{{ row.name }}</span>
                            </span>
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">
                            {{ row.interestRate === null ? '—' : `${row.interestRate}% ${row.interestRatePeriod}` }}
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #211d17">{{ formatMoney(row.balance) }}</td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">{{ formatMoney(row.perMonth) }}</td>
                        <td style="padding: 9px 8px; text-align: right"
                            :style="{ color: row.payoffDate ? '#2f7d5b' : '#c0512c' }">
                            {{ row.payoffDate ?? 'Never at this rate' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
