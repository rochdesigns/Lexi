<script setup lang="ts">
import { sheetWidth } from '@/utils/blueprint';

/**
 * The top of a redesigned page, drawn like a sheet header: a grid band with a
 * dimension line naming the page's headline figure, then an icon tile that
 * overlaps the band, the sheet code, the title and a one-line subtitle.
 */
const { tall = false, raised = false } = defineProps<{
    /** A taller band. */
    tall?: boolean;
    /** Draw the dimension line mid-band instead of near its foot. */
    raised?: boolean;
    icon: string;
    code: string;
    section: string;
    title: string;
    subtitle?: string;
    dimension?: string | null;
}>();
</script>

<template>
    <div>
        <div
            class="bp-grid-paper relative border-y border-[var(--bp-line)]"
            :class="tall ? 'h-[190px]' : 'h-[150px]'"
        >
            <!-- A page can draw its own figure in the band instead of a dimension line. -->
            <slot name="band" />
            <div
                v-if="dimension"
                class="absolute inset-x-0 flex items-center"
                :class="[
                    sheetWidth.wide,
                    raised ? 'bottom-14' : 'bottom-[22px]',
                ]"
                aria-hidden="true"
            >
                <span class="h-3 w-px rotate-45 bg-[var(--bp-accent)]"></span>
                <span class="h-px flex-1 bg-[var(--bp-accent)]"></span>
                <span
                    class="bp-mono bg-[var(--bp-paper)] px-2.5 text-center text-[10.5px] tracking-[0.12em] text-[var(--bp-accent)] uppercase"
                    >{{ dimension }}</span
                >
                <span class="h-px flex-1 bg-[var(--bp-accent)]"></span>
                <span class="h-3 w-px rotate-45 bg-[var(--bp-accent)]"></span>
            </div>
            <span
                class="bp-mono absolute top-3.5 right-6 text-[10.5px] tracking-[0.12em] text-[var(--bp-accent)]"
                aria-hidden="true"
                >{{ code }}</span
            >
        </div>

        <div :class="sheetWidth.wide">
            <div
                class="relative -mt-[38px] flex h-[76px] w-[76px] items-center justify-center rounded-md border border-[var(--bp-line)] bg-[var(--bp-card)]"
                aria-hidden="true"
            >
                <span class="bp-duo text-[44px]">
                    <span
                        class="text-[var(--bp-soft)]"
                        style="
                            font-variation-settings:
                                'FILL' 1,
                                'wght' 300;
                        "
                        >{{ icon }}</span
                    >
                    <span
                        class="text-[var(--bp-ink)]"
                        style="
                            font-variation-settings:
                                'FILL' 0,
                                'wght' 300;
                        "
                        >{{ icon }}</span
                    >
                </span>
            </div>
            <p
                class="bp-mono mt-[22px] text-[11px] tracking-[0.16em] text-[var(--bp-accent)] uppercase"
            >
                {{ code }} · {{ section }}
            </p>
            <h1
                class="mt-1.5 mb-1.5 text-[36px] leading-[1.1] font-semibold tracking-[-0.03em] sm:text-[44px]"
            >
                {{ title }}
            </h1>
            <p v-if="subtitle" class="text-[15px] text-[var(--bp-muted)]">
                {{ subtitle }}
            </p>
            <!-- Optional property list under the title (period, totals, modes). -->
            <slot />
            <div class="my-[26px] h-px bg-[var(--bp-line)]"></div>
        </div>
    </div>
</template>
