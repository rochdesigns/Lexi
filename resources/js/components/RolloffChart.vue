<script setup lang="ts">
import { computed } from 'vue';
import { formatMonth, formatPeso, formatPesoShort } from '@/utils/money';

type Point = { month: string; total: number };

const props = defineProps<{ series: Point[] }>();

const max = computed(() => Math.max(...props.series.map((p) => p.total), 0));
const hasData = computed(() => max.value > 0);

function height(total: number): string {
    return max.value === 0 ? '0%' : `${(total / max.value) * 100}%`;
}

function monthLetter(month: string): string {
    return formatMonth(month).slice(0, 3);
}

function showLabel(index: number): boolean {
    return index % 3 === 0;
}
</script>

<template>
    <div>
        <div
            v-if="!hasData"
            class="flex h-40 items-center justify-center text-[13px]"
            style="color: var(--hab-muted)"
        >
            No installments yet. Add one to see when your monthly load drops.
        </div>
        <template v-else>
            <div
                class="mb-2 flex items-baseline justify-between text-[12px]"
                style="color: var(--hab-muted)"
            >
                <span>Peak {{ formatPeso(max) }} a month</span>
                <span>Now {{ formatPeso(series[0].total) }}</span>
            </div>
            <div
                class="flex h-40 items-end gap-[3px]"
                role="img"
                aria-label="Monthly installment obligations for the next 24 months"
            >
                <div
                    v-for="point in series"
                    :key="point.month"
                    class="group relative flex h-full flex-1 items-end"
                    :title="`${formatMonth(point.month)}: ${formatPeso(point.total)}`"
                >
                    <div
                        class="w-full rounded-t-[4px] transition-opacity group-hover:opacity-80"
                        :style="{
                            height: height(point.total),
                            minHeight: point.total > 0 ? '3px' : '0',
                            background: 'var(--hab-accent)',
                        }"
                    ></div>
                </div>
            </div>
            <div class="mt-1.5 flex gap-[3px]">
                <span
                    v-for="(point, i) in series"
                    :key="point.month"
                    class="flex-1 text-[10.5px] whitespace-nowrap"
                    style="color: var(--hab-faint)"
                >
                    {{ showLabel(i) ? monthLetter(point.month) : '' }}
                </span>
            </div>
            <p
                class="mt-2 text-[11.5px] tabular-nums"
                style="color: var(--hab-faint)"
            >
                Last month with payments:
                {{
                    formatMonth(
                        [...series].reverse().find((p) => p.total > 0)!.month,
                    )
                }}
                ({{
                    formatPesoShort(
                        [...series].reverse().find((p) => p.total > 0)!.total,
                    )
                }})
            </p>
        </template>
    </div>
</template>
