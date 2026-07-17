<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const pageName = computed(() => page.component.replace('Stub/', ''));
</script>

<template>
    <div class="flex h-64 flex-col items-center justify-center gap-2">
        <p class="text-[var(--hab-muted)] text-sm">Coming soon</p>
    </div>
</template>
