<script setup lang="ts">
import { ArcElement, Chart as ChartJS, DoughnutController, Legend, Title, Tooltip } from 'chart.js';
import { Doughnut } from 'vue-chartjs';

ChartJS.register(Title, Tooltip, Legend, ArcElement, DoughnutController);

defineProps<{
    labels: string[];
    data: number[];
    colors: string[];
}>();

const options = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: { legend: { display: true, position: 'right' as const } },
};
</script>

<template>
    <div style="height: 240px">
        <Doughnut :data="{ labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0 }] }" :options="options" />
    </div>
</template>
