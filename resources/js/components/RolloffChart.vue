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
    return formatMonth(month).slice(0, 3).toUpperCase();
}

/** Each month the total falls, and by how much, ending with the last month that has a payment. */
const drops = computed(() => {
    const points = props.series;
    const result: { when: string; text: string }[] = [];

    for (let index = 1; index < points.length; index++) {
        const fall = points[index - 1].total - points[index].total;

        if (fall > 0 && points[index].total > 0) {
            result.push({
                when: formatMonth(points[index].month).toUpperCase(),
                text: `drops by ${formatPesoShort(fall)}`,
            });
        }
    }

    const last = [...points].reverse().find((point) => point.total > 0);

    if (last) {
        result.push({
            when: formatMonth(last.month).toUpperCase(),
            text: `last payment, ${formatPesoShort(last.total)}`,
        });
    }

    return result;
});

function showLabel(index: number): boolean {
    return index % 3 === 0;
}
</script>

<template>
    <div
        class="border border-[var(--bp-line)] bg-[var(--bp-card)] px-[22px] pt-5 pb-4"
    >
        <div
            v-if="!hasData"
            class="flex h-40 items-center justify-center text-[13px] text-[var(--bp-muted)]"
        >
            No installments yet. Add one to see when your monthly load drops.
        </div>
        <template v-else>
            <div
                class="bp-mono flex justify-between text-[11px] text-[var(--bp-muted)]"
            >
                <span>PEAK {{ formatPesoShort(max) }} / MO</span>
                <span>NOW {{ formatPesoShort(series[0].total) }}</span>
            </div>
            <div
                class="mt-2.5 flex h-40 items-end gap-[3px] border-b border-[var(--bp-ink)] bg-[linear-gradient(var(--bp-hair)_1px,transparent_1px)] bg-[length:100%_40px]"
                role="img"
                :aria-label="`Monthly installment obligations for the next ${series.length} months, from ${formatPeso(series[0].total)} now`"
            >
                <div
                    v-for="(point, index) in series"
                    :key="point.month"
                    class="flex h-full flex-1 items-end"
                    :title="`${formatMonth(point.month)}: ${formatPeso(point.total)}`"
                >
                    <div
                        class="box-border w-full"
                        :class="
                            index === 0
                                ? 'bg-[var(--bp-accent)]'
                                : 'border border-b-0 border-[var(--bp-accent)] bg-[var(--bp-tint)]'
                        "
                        :style="{
                            height: height(point.total),
                            minHeight: point.total > 0 ? '3px' : '0',
                        }"
                    ></div>
                </div>
            </div>
            <div class="mt-1.5 flex gap-[3px]" aria-hidden="true">
                <span
                    v-for="(point, i) in series"
                    :key="point.month"
                    class="bp-mono flex-1 text-[10px] whitespace-nowrap text-[var(--bp-faint)]"
                >
                    {{ showLabel(i) ? monthLetter(point.month) : '' }}
                </span>
            </div>
            <div
                class="mt-3 flex flex-wrap gap-x-6 gap-y-2 border-t border-[var(--bp-hair)] pt-2.5 text-[12.5px] text-[var(--bp-ink-2)]"
            >
                <span
                    v-for="drop in drops"
                    :key="drop.when + drop.text"
                    class="flex items-center gap-1.5"
                >
                    <span class="bp-mono text-[11px] text-[var(--bp-accent)]">{{
                        drop.when
                    }}</span
                    >{{ drop.text }}
                </span>
            </div>
        </template>
    </div>
</template>
