<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, update } from '@/routes/budget-cycle';

const PRESETS = [
    { label: 'Weekly', days: 7 },
    { label: 'Bi-weekly', days: 14 },
    { label: 'Monthly', days: 30 },
];

const props = defineProps<{
    cycleDays: number;
    cycleAnchor: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Budget cycle', href: edit() }],
    },
});

const isCustom = (days: number) => !PRESETS.some((p) => p.days === days);

const selectedPreset = ref<number | 'custom'>(isCustom(props.cycleDays) ? 'custom' : props.cycleDays);
const customDays = ref(isCustom(props.cycleDays) ? props.cycleDays : 30);

const effectiveDays = computed(() =>
    selectedPreset.value === 'custom' ? customDays.value : (selectedPreset.value as number),
);

const form = useForm({
    cycle_days: props.cycleDays,
    cycle_anchor: props.cycleAnchor,
});

watch(effectiveDays, (val) => {
    form.cycle_days = val;
});

function submit() {
    form.patch(update().url);
}

// Preview: compute current + next 2 cycles from the anchor + effective days
const previewCycles = computed(() => {
    const anchor = new Date(form.cycle_anchor + 'T00:00:00');
    const days = effectiveDays.value;
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const diffMs = today.getTime() - anchor.getTime();
    const diffDays = Math.round(diffMs / 86400000);
    const index = Math.floor(diffDays / days);

    const cycles = [];
    for (let i = index; i <= index + 2; i++) {
        const start = new Date(anchor);
        start.setDate(start.getDate() + i * days);
        const end = new Date(start);
        end.setDate(end.getDate() + days - 1);
        cycles.push({
            start: start.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
            end: end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
            isCurrent: i === index,
        });
    }
    return cycles;
});
</script>

<template>
    <Head title="Budget cycle settings" />

    <h1 class="sr-only">Budget cycle settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Budget cycle"
            description="Set how long each budget period lasts and when cycles begin"
        />

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Cycle length -->
            <div class="space-y-3">
                <Label>Cycle length</Label>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="preset in PRESETS"
                        :key="preset.days"
                        type="button"
                        @click="selectedPreset = preset.days"
                        :class="[
                            'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                            selectedPreset === preset.days
                                ? 'border-[var(--hab-accent)] bg-[var(--hab-accent)] text-white'
                                : 'border-border text-muted-foreground hover:border-[var(--hab-accent)] hover:text-foreground',
                        ]"
                    >
                        {{ preset.label }}
                    </button>

                    <button
                        type="button"
                        @click="selectedPreset = 'custom'"
                        :class="[
                            'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                            selectedPreset === 'custom'
                                ? 'border-[var(--hab-accent)] bg-[var(--hab-accent)] text-white'
                                : 'border-border text-muted-foreground hover:border-[var(--hab-accent)] hover:text-foreground',
                        ]"
                    >
                        Custom
                    </button>
                </div>

                <div v-if="selectedPreset === 'custom'" class="flex items-center gap-2">
                    <Input
                        type="number"
                        v-model.number="customDays"
                        min="1"
                        max="365"
                        class="w-24"
                    />
                    <span class="text-sm text-muted-foreground">days</span>
                </div>

                <InputError :message="form.errors.cycle_days" />
            </div>

            <!-- Anchor date -->
            <div class="space-y-2">
                <Label for="cycle_anchor">Cycle start date</Label>
                <p class="text-sm text-muted-foreground">
                    All cycles are counted forward from this date.
                </p>
                <Input
                    id="cycle_anchor"
                    type="date"
                    v-model="form.cycle_anchor"
                    class="w-44"
                />
                <InputError :message="form.errors.cycle_anchor" />
            </div>

            <!-- Preview -->
            <div class="space-y-2">
                <p class="text-sm font-medium">Preview</p>
                <div class="space-y-1.5">
                    <div
                        v-for="(c, i) in previewCycles"
                        :key="i"
                        :class="[
                            'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                            c.isCurrent ? 'border-[var(--hab-accent)]/40 bg-[var(--hab-accent)]/8' : 'border-border',
                        ]"
                    >
                        <span
                            v-if="c.isCurrent"
                            class="rounded-full bg-[var(--hab-accent)] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white"
                        >
                            current
                        </span>
                        <span v-else class="w-[54px]" />
                        <span :class="c.isCurrent ? 'font-medium' : 'text-muted-foreground'">
                            {{ c.start }} – {{ c.end }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing">Save</Button>
            </div>
        </form>
    </div>
</template>
