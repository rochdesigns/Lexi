<script setup lang="ts">
defineProps<{ reason?: string }>();
</script>

<template>
    <div style="display: flex; align-items: center; gap: 8px; color: #8a8172; font-size: 13.5px; padding: 10px 0">
        <span class="material-symbols-rounded" style="font-size: 18px">info</span>
        <span>{{ reason ?? 'Not enough data yet.' }}</span>
    </div>
</template>
