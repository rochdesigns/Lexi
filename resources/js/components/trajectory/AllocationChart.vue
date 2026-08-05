<script setup lang="ts">
import { computed } from 'vue';
import ReportDonutChart from '@/components/reports/ReportDonutChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { Vehicle } from '@/pages/Trajectory.vue';

const props = defineProps<{ vehicles: Vehicle[] }>();

const palette = ['#3f7fb0', '#2f7d5b', '#a85fc0', '#d98a2b', '#c0512c', '#6b6355'];

const labels = computed(() => props.vehicles.map(vehicle => vehicle.name));
const data = computed(() => props.vehicles.map(vehicle => vehicle.value));
const colors = computed(() => props.vehicles.map((_, index) => palette[index % palette.length]));
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Allocation</div>
        <div v-if="vehicles.length > 0" style="margin-top: 12px">
            <ReportDonutChart :labels="labels" :data="data" :colors="colors" />
        </div>
        <UnavailableNote v-else reason="No investment accounts to allocate." />
    </section>
</template>
