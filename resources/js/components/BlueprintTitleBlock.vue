<script setup lang="ts">
/** The drawing's title block, closing a page: app, drawing name, sheet code and today's date. */
defineProps<{ drawing: string; sheet: string }>();

const now = new Date();
const today = [
    String(now.getDate()).padStart(2, '0'),
    String(now.getMonth() + 1).padStart(2, '0'),
    now.getFullYear(),
].join('.');
</script>

<template>
    <div class="mt-14 flex justify-end" aria-hidden="true">
        <div
            class="bp-mono grid grid-cols-[auto_auto_auto_auto] border border-[var(--bp-ink)] bg-[var(--bp-card)]"
        >
            <div
                class="px-3.5 py-2 text-[13px] font-semibold tracking-[0.04em]"
            >
                LEXI
            </div>
            <div class="border-l border-[var(--bp-ink)] px-3.5 py-2">
                <div
                    class="text-[9px] tracking-[0.12em] text-[var(--bp-faint)]"
                >
                    DRAWING
                </div>
                <div class="text-[11.5px] uppercase">{{ drawing }}</div>
            </div>
            <div class="border-l border-[var(--bp-ink)] px-3.5 py-2">
                <div
                    class="text-[9px] tracking-[0.12em] text-[var(--bp-faint)]"
                >
                    SHEET
                </div>
                <div class="text-[11.5px]">{{ sheet }}</div>
            </div>
            <div class="border-l border-[var(--bp-ink)] px-3.5 py-2">
                <div
                    class="text-[9px] tracking-[0.12em] text-[var(--bp-faint)]"
                >
                    DATE
                </div>
                <div class="text-[11.5px]">{{ today }}</div>
            </div>
        </div>
    </div>
</template>
