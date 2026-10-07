<script setup lang="ts">
/** A lettered section title (A, B, C…) with room on the right for a figure or action. */
defineProps<{ letter: string; title: string }>();
</script>

<template>
    <div class="flex items-center gap-2.5">
        <span
            class="bp-mono flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-[var(--bp-ink)] text-[11px] font-semibold"
            aria-hidden="true"
            >{{ letter }}</span
        >
        <h2 class="text-[22px] font-semibold tracking-[-0.015em]">
            {{ title }}
        </h2>
        <div class="ml-auto flex items-center gap-2">
            <slot />
        </div>
    </div>
</template>
