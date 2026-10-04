<script setup lang="ts">
import { onMounted, ref } from 'vue';

/**
 * A short note on a card saying what its data is used for. It can be closed,
 * and stays closed in this browser. Browser storage can be unavailable, so
 * every read and write is guarded and the note simply shows again.
 */
const props = defineProps<{ id: string; title?: string }>();

const key = `lexi-note-${props.id}`;
const visible = ref(false);

onMounted(() => {
    try {
        visible.value = localStorage.getItem(key) !== 'closed';
    } catch {
        visible.value = true;
    }
});

function close(): void {
    visible.value = false;

    try {
        localStorage.setItem(key, 'closed');
    } catch {
        // Nothing to do: it will show again next time.
    }
}
</script>

<template>
    <div
        v-if="visible"
        class="mb-3 flex items-start gap-2.5 rounded-[12px] px-3.5 py-2.5 text-[12.5px]"
        style="
            background: color-mix(in srgb, var(--hab-accent) 9%, transparent);
            border: 1px solid
                color-mix(in srgb, var(--hab-accent) 24%, transparent);
            color: var(--hab-ink);
        "
        role="note"
    >
        <span
            class="material-symbols-rounded mt-px shrink-0"
            style="font-size: 17px; color: var(--hab-accent)"
            aria-hidden="true"
            >info</span
        >
        <p class="min-w-0 flex-1">
            <span v-if="title" class="font-semibold">{{ title }} </span>
            <slot />
        </p>
        <button
            type="button"
            class="shrink-0 rounded-full p-0.5 hover:bg-black/5"
            aria-label="Dismiss this note"
            @click="close"
        >
            <span
                class="material-symbols-rounded block"
                style="font-size: 16px; color: var(--hab-muted)"
                aria-hidden="true"
                >close</span
            >
        </button>
    </div>
</template>
