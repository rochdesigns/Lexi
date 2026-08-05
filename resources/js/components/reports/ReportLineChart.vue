<script setup lang="ts">
import { CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';
import { Line } from 'vue-chartjs';

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale);

type Dataset = {
    label: string;
    data: (number | null)[];
    borderColor: string;
    borderDash?: number[];
    backgroundColor?: string;
    fill?: boolean;
    spanGaps?: boolean;
};

defineProps<{
    labels: string[];
    datasets: Dataset[];
    ariaLabel: string;
}>();

const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: true } },
    scales: { y: { beginAtZero: true } },
};
</script>

<template>
    <div style="height: 240px">
        <Line :data="{ labels, datasets }" :options="options" role="img" :aria-label="ariaLabel" />
    </div>
</template>
