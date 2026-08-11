<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, update } from '@/routes/budget-cycle';

const FIXED_PRESETS = [
    { label: 'Weekly', days: 7 },
    { label: 'Bi-weekly', days: 14 },
];

const props = defineProps<{
    cycleDays: number;
    cycleAnchor: string;
    cycleType: 'fixed_days' | 'monthly';
    monthlyStartDay: number | null;
    suggestedMonthlyStartDay: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Budget cycle', href: edit() }],
    },
});

const isFixedPreset = (days: number) =>
    FIXED_PRESETS.some((p) => p.days === days);

type Selection = 'monthly' | 'custom' | number;

const selectedPreset = ref<Selection>(
    props.cycleType === 'monthly'
        ? 'monthly'
        : isFixedPreset(props.cycleDays)
          ? props.cycleDays
          : 'custom',
);
const customDays = ref(
    props.cycleType === 'fixed_days' && !isFixedPreset(props.cycleDays)
        ? props.cycleDays
        : 30,
);
const monthlyStartDay = ref(
    props.monthlyStartDay ?? props.suggestedMonthlyStartDay,
);

const form = useForm({
    cycle_type: props.cycleType,
    cycle_days: props.cycleDays,
    cycle_anchor: props.cycleAnchor,
    monthly_start_day: monthlyStartDay.value,
});

watch(selectedPreset, (val) => {
    if (val === 'monthly') {
        form.cycle_type = 'monthly';
        form.monthly_start_day = monthlyStartDay.value;
    } else {
        form.cycle_type = 'fixed_days';
        form.cycle_days = val === 'custom' ? customDays.value : val;
    }
});

watch(customDays, (val) => {
    if (selectedPreset.value === 'custom') {
        form.cycle_days = val;
    }
});

watch(monthlyStartDay, (val) => {
    if (selectedPreset.value === 'monthly') {
        form.monthly_start_day = val;
    }
});

function submit() {
    form.patch(update().url);
}

// Preview: mirrors App\Services\BudgetCycle's math in JS for the settings-page
// preview only (the server is always the source of truth once saved).
function daysInMonth(year: number, month: number): number {
    return new Date(year, month, 0).getDate();
}

const previewCycles = computed(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (selectedPreset.value === 'monthly') {
        const startDay = monthlyStartDay.value;
        const cycles = [];
        let year = today.getFullYear();
        let month = today.getMonth() + 1;

        let day = Math.min(startDay, daysInMonth(year, month));
        let start = new Date(year, month - 1, day);

        if (today < start) {
            month -= 1;

            if (month < 1) {
                month = 12;
                year -= 1;
            }

            day = Math.min(startDay, daysInMonth(year, month));
            start = new Date(year, month - 1, day);
        }

        for (let i = 0; i < 3; i++) {
            let nextMonth = month + 1;
            let nextYear = year;

            if (nextMonth > 12) {
                nextMonth = 1;
                nextYear += 1;
            }

            const nextDay = Math.min(
                startDay,
                daysInMonth(nextYear, nextMonth),
            );
            const nextStart = new Date(nextYear, nextMonth - 1, nextDay);
            const end = new Date(nextStart);
            end.setDate(end.getDate() - 1);

            cycles.push({
                start: start.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                }),
                end: end.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                }),
                isCurrent: i === 0,
            });

            year = nextYear;
            month = nextMonth;
            start = nextStart;
        }

        return cycles;
    }

    const anchor = new Date(form.cycle_anchor + 'T00:00:00');
    const days =
        selectedPreset.value === 'custom'
            ? customDays.value
            : (selectedPreset.value as number);

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
            start: start.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            }),
            end: end.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            }),
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
            <div class="space-y-3">
                <Label>Cycle length</Label>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="preset in FIXED_PRESETS"
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
                        @click="selectedPreset = 'monthly'"
                        :class="[
                            'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                            selectedPreset === 'monthly'
                                ? 'border-[var(--hab-accent)] bg-[var(--hab-accent)] text-white'
                                : 'border-border text-muted-foreground hover:border-[var(--hab-accent)] hover:text-foreground',
                        ]"
                    >
                        Monthly
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

                <div
                    v-if="selectedPreset === 'custom'"
                    class="flex items-center gap-2"
                >
                    <Input
                        type="number"
                        v-model.number="customDays"
                        min="1"
                        max="365"
                        class="w-24"
                    />
                    <span class="text-sm text-muted-foreground">days</span>
                </div>

                <div
                    v-if="selectedPreset === 'monthly'"
                    class="flex items-center gap-2"
                >
                    <span class="text-sm text-muted-foreground"
                        >Start each cycle on day</span
                    >
                    <Input
                        type="number"
                        v-model.number="monthlyStartDay"
                        min="1"
                        max="31"
                        class="w-20"
                    />
                    <span class="text-sm text-muted-foreground"
                        >of the month</span
                    >
                </div>

                <InputError :message="form.errors.cycle_days" />
                <InputError :message="form.errors.monthly_start_day" />
            </div>

            <div v-if="selectedPreset !== 'monthly'" class="space-y-2">
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

            <div class="space-y-2">
                <p class="text-sm font-medium">Preview</p>
                <div class="space-y-1.5">
                    <div
                        v-for="(c, i) in previewCycles"
                        :key="i"
                        :class="[
                            'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                            c.isCurrent
                                ? 'border-[var(--hab-accent)]/40 bg-[var(--hab-accent)]/8'
                                : 'border-border',
                        ]"
                    >
                        <span
                            v-if="c.isCurrent"
                            class="rounded-full bg-[var(--hab-accent)] px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-white uppercase"
                        >
                            current
                        </span>
                        <span v-else class="w-[54px]" />
                        <span
                            :class="
                                c.isCurrent
                                    ? 'font-medium'
                                    : 'text-muted-foreground'
                            "
                        >
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
