<script setup lang="ts">
import { computed } from 'vue';

/** One category's living expenses this month against its limit. */
export type LimitRow = {
    category: string;
    customCategoryId: number | null;
    categoryName: string | null;
    limit: number | null;
    spent: number;
    left: number | null;
    pace: number | null;
    status: 'none' | 'under' | 'close' | 'over';
    aheadOfPace: boolean;
};

/**
 * Spent against a limit, with a thin tick where an even pace would be by today.
 * Close fills in the accent; over fills solid ink, in keeping with the
 * blueprint's one-accent palette.
 */
const props = defineProps<{
    limit: number;
    spent: number;
    pace: number;
    status: 'under' | 'close' | 'over';
    label: string;
}>();

const percent = computed(() =>
    Math.min(100, Math.round((props.spent / props.limit) * 100)),
);
const pacePercent = computed(() =>
    Math.min(100, (props.pace / props.limit) * 100),
);
</script>

<template>
    <span
        class="relative block h-2 border border-[var(--bp-line)] bg-[var(--bp-paper)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
        role="progressbar"
        :aria-valuenow="percent"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="label"
    >
        <span
            class="hab-progress-fill block h-full"
            :class="
                status === 'over'
                    ? 'bg-[var(--bp-ink)]'
                    : status === 'close'
                      ? 'bg-[var(--bp-accent)]'
                      : 'bg-[var(--bp-soft)]'
            "
            :style="{ width: percent + '%' }"
        ></span>
        <span
            class="absolute -top-[3px] -bottom-[3px] w-px bg-[var(--bp-ink)]"
            :style="{ left: pacePercent + '%' }"
            aria-hidden="true"
        ></span>
    </span>
</template>
