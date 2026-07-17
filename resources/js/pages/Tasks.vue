<script setup lang="ts">
defineProps<{
    groups: {
        key: 'today' | 'week' | 'someday';
        label: string;
        tasks: { id: number; label: string; group: string; done: boolean }[];
        leftCount: number;
    }[];
}>();
</script>

<template>
    <div>
        <slot />
    </div>
</template>
