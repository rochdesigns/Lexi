<script setup lang="ts">
import { formatMoney, formatPct, formatSigned } from '@/components/trajectory/format';
import type { Vehicle } from '@/pages/Trajectory.vue';

defineProps<{
    vehicles: Vehicle[];
    costBasisComplete: boolean;
    totalCostBasis: number;
    gainLoss: number | null;
    gainLossPct: number | null;
}>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">By vehicle</div>

        <div v-if="!costBasisComplete" style="font-size: 12.5px; color: #d98a2b; margin-top: 6px">
            Add a cost basis to every investment account to see total gain.
        </div>

        <!-- Only a complete cost basis makes the total meaningful; a partial sum would
             read as the whole portfolio's basis and overstate the gain. -->
        <template v-else-if="gainLoss !== null">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 22px; margin-top: 6px"
                 :style="{ color: gainLoss >= 0 ? '#2f7d5b' : '#c0512c' }">
                {{ formatSigned(gainLoss) }}
                <span v-if="gainLossPct !== null" style="font-size: 15px; font-weight: 600">({{ formatPct(gainLossPct) }})</span>
            </div>
            <div style="font-size: 12px; color: #8a8172; margin-top: 2px">
                Unrealised, against {{ formatMoney(totalCostBasis) }} invested
            </div>
        </template>

        <div style="margin-top: 12px; overflow-x: auto">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 460px">
                <thead>
                    <tr style="color: #8a8172; text-align: left">
                        <th style="padding: 6px 8px; font-weight: 500">Account</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Value</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Cost basis</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Gain</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Share</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="vehicle in vehicles" :key="vehicle.id" style="border-top: 1px solid #e0dacd">
                        <td style="padding: 9px 8px; color: #211d17; font-weight: 600">
                            {{ vehicle.name }}
                            <span v-if="vehicle.institution" style="color: #8a8172; font-weight: 400"> · {{ vehicle.institution }}</span>
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #211d17">{{ formatMoney(vehicle.value) }}</td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">
                            {{ vehicle.costBasis === null ? '—' : formatMoney(vehicle.costBasis) }}
                        </td>
                        <td style="padding: 9px 8px; text-align: right"
                            :style="{ color: (vehicle.gainLoss ?? 0) >= 0 ? '#2f7d5b' : '#c0512c' }">
                            {{ vehicle.gainLoss === null ? '—' : formatSigned(vehicle.gainLoss) }}
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">{{ vehicle.allocationPct.toFixed(1) }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
