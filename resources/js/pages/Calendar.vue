<script setup lang="ts">
defineProps<{
    events: { id: number; title: string; date: string; time: string | null; tag: string | null; color: string | null; bg: string; is_recurring: boolean; recurrence_type: string | null }[];
    currentMonth: string;
}>();
</script>

<template>
    <div class="flex h-64 flex-col items-center justify-center gap-2">
        <p class="text-[var(--hab-muted)] text-sm">Calendar — coming soon</p>
    </div>
</template>
