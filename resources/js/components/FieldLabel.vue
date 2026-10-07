<script setup lang="ts">
import { CircleHelp } from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineProps<{ hint: string }>();
</script>

<template>
    <div class="mb-1.5 flex items-center gap-1">
        <label
            class="bp-mono text-[10px] tracking-[0.12em] text-[var(--bp-muted)] uppercase"
        >
            <slot />
        </label>
        <TooltipProvider :delay-duration="100">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="rounded-full text-[var(--bp-muted)] outline-none hover:text-[var(--bp-ink)] focus-visible:ring-2 focus-visible:ring-[var(--bp-accent)]"
                        :aria-label="`About this field: ${hint}`"
                    >
                        <CircleHelp class="size-3.5" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-64">{{ hint }}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>
