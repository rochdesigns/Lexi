<script setup lang="ts">
defineProps<{
    user: { name: string; firstName: string; theme: string };
    todayEvents: unknown[];
    todayTasks: unknown[];
    moneySummary: { safeToSpend: number; budget: number; spent: number };
    habits: unknown[];
    healthMetrics: unknown[];
}>();
</script>

<template>
    <div class="flex h-full flex-col">
        <slot />
    </div>
</template>
