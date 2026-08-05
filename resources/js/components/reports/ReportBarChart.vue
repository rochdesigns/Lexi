<script setup lang="ts">
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Title, Tooltip } from 'chart.js';
import { Bar } from 'vue-chartjs';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

type Dataset = {
    label?: string;
    data: number[];
    backgroundColor: string | string[];
    borderRadius?: number;
};

const props = defineProps<{
    labels: string[];
    datasets: Dataset[];
    showLegend?: boolean;
    ariaLabel: string;
}>();

const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: props.showLegend ?? false } },
    scales: { y: { beginAtZero: true } },
};
</script>

<template>
    <div style="height: 220px">
        <Bar :data="{ labels, datasets }" :options="options" role="img" :aria-label="ariaLabel" />
    </div>
</template>
