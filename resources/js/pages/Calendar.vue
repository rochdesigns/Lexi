<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import {
    ref,
    computed,
    watch,
    onMounted,
    onBeforeUnmount,
    nextTick,
} from 'vue';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/CalendarController';
import {
    store,
    update,
    destroy,
} from '@/actions/App/Http/Controllers/EventController';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formField, formLabel, sheetWidth } from '@/utils/blueprint';

type Occurrence = {
    id: number;
    title: string;
    date: string;
    time: string;
    end_time: string | null;
    tag: string;
    color: string;
    bg: string;
    is_recurring: boolean;
    recurrence_type: string | null;
};

const props = defineProps<{
    events: Occurrence[];
    currentMonth: string;
}>();

// ── constants ──────────────────────────────────────────────────────────────

const TAG_COLORS: Record<string, string> = {
    Work: '#3f7fb0',
    Personal: '#a85fc0',
    Health: '#2f7d5b',
    Family: '#d98a2b',
    Other: '#6b6355',
};

const TAGS = ['Work', 'Personal', 'Health', 'Family', 'Other'] as const;
const WEEKDAYS_SHORT = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const WEEKDAYS_FULL = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const DOW_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ── helpers ────────────────────────────────────────────────────────────────

function toLocalDateStr(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function eventTop(time: string): number {
    const [h, m] = time.split(':').map(Number);

    return h * 60 + m;
}

function eventHeight(time: string, endTime: string | null): number {
    if (!endTime) {
        return 52;
    }

    const top = eventTop(time);
    const bottom = eventTop(endTime);

    return Math.max(bottom - top, 30);
}

// ── state ──────────────────────────────────────────────────────────────────

const today = new Date();
const todayStr = toLocalDateStr(today);

/** Browser storage can be unavailable, so the view falls back to the week. */
function storedView(): 'month' | 'week' {
    try {
        return localStorage.getItem('cal-view') === 'month' ? 'month' : 'week';
    } catch {
        return 'week';
    }
}

const view = ref<'month' | 'week'>(storedView());
const urlDate = new URLSearchParams(window.location.search).get('date');
const selectedDate = ref(urlDate ?? todayStr);
const showModal = ref(false);
const editingEvent = ref<Occurrence | null>(null);
const timeGridRef = ref<HTMLElement | null>(null);

onMounted(() => {
    if (view.value === 'week' && timeGridRef.value) {
        timeGridRef.value.scrollTop = 7 * 60;
    }
});

// Form state
const form = ref({
    title: '',
    date: todayStr,
    time: '09:00',
    end_time: '10:00',
    tag: 'Work' as string,
    color: TAG_COLORS['Work'],
    recurrence_enabled: false,
    recurrence_type: 'weekly' as string,
    recurrence_interval: 1,
    recurrence_days: [] as string[],
    recurrence_ends_on: '',
});

watch(view, async (v) => {
    try {
        localStorage.setItem('cal-view', v);
    } catch {
        // Nothing to do: the choice lasts until the page is left.
    }

    if (v === 'week') {
        await nextTick();

        if (timeGridRef.value) {
            timeGridRef.value.scrollTop = 7 * 60;
        }
    }
});

// ── computed: month grid ──────────────────────────────────────────────────

const calendarTitle = computed(() => {
    const [year, month] = props.currentMonth.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    });
});

const gridCells = computed(() => {
    const [year, month] = props.currentMonth.split('-').map(Number);
    const firstDow = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const daysInMonth = new Date(year, month, 0).getDate();
    const eventDays = new Set(
        props.events
            .filter((e) => e.date.startsWith(props.currentMonth))
            .map((e) => Number(e.date.slice(8, 10))),
    );

    const cells = [];

    for (let i = 0; i < firstDow; i++) {
        cells.push({
            blank: true,
            day: 0,
            dateStr: '',
            isToday: false,
            hasEvent: false,
            dotColor: '',
        });
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${props.currentMonth}-${String(d).padStart(2, '0')}`;
        const isToday = dateStr === todayStr;
        cells.push({
            blank: false,
            day: d,
            dateStr,
            isToday,
            hasEvent: eventDays.has(d),
            dotColor: isToday ? '#fff' : 'var(--hab-accent)',
        });
    }

    return cells;
});

// ── computed: agenda ──────────────────────────────────────────────────────

const agendaEvents = computed(() =>
    props.events
        .filter((e) => e.date === selectedDate.value)
        .sort((a, b) => a.time.localeCompare(b.time)),
);

const agendaLabel = computed(() => {
    const d = new Date(selectedDate.value + 'T00:00:00');
    const label = d
        .toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        })
        .toUpperCase();
    const prefix = selectedDate.value === todayStr ? 'TODAY · ' : '';

    return prefix + label;
});

// ── computed: week view ───────────────────────────────────────────────────

const weekColumns = computed(() => {
    const sel = new Date(selectedDate.value + 'T00:00:00');
    const dow = sel.getDay(); // 0=Sun
    const sundayOfWeek = new Date(sel);
    sundayOfWeek.setDate(sel.getDate() - dow);

    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(sundayOfWeek);
        d.setDate(sundayOfWeek.getDate() + i);
        const dateStr = toLocalDateStr(d);

        return {
            dow: WEEKDAYS_FULL[i],
            dateNum: d.getDate(),
            dateStr,
            isToday: dateStr === todayStr,
            events: props.events
                .filter((e) => e.date === dateStr)
                .sort((a, b) => a.time.localeCompare(b.time)),
        };
    });
});

const weekRangeLabel = computed(() => {
    const cols = weekColumns.value;
    const first = new Date(cols[0].dateStr + 'T00:00:00');
    const last = new Date(cols[6].dateStr + 'T00:00:00');
    const fmt = (d: Date) =>
        d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

    return `${fmt(first)} – ${fmt(last)}`;
});

// ── blueprint drawing ─────────────────────────────────────────────────────

/** Event fills by tag: Work takes the accent, the rest stay quiet paper tones. */
const TAG_FILLS: Record<string, { bg: string; bd: string }> = {
    Work: { bg: 'var(--bp-tint)', bd: 'var(--bp-accent-line)' },
    Personal: { bg: '#f4ede4', bd: '#d6c3ab' },
    Health: { bg: '#e9f0ea', bd: '#b3c9b7' },
    Family: { bg: '#f6ecd9', bd: '#dcc08a' },
    Other: { bg: 'var(--bp-sidebar)', bd: 'var(--bp-line)' },
};

function tagFill(tag: string): { bg: string; bd: string } {
    return TAG_FILLS[tag] ?? TAG_FILLS.Other;
}

function shortTime(time: string): string {
    return time.slice(0, 5);
}

function timeRange(event: Occurrence): string {
    return event.end_time
        ? `${shortTime(event.time)}–${shortTime(event.end_time)}`
        : shortTime(event.time);
}

function recurrenceText(event: Occurrence): string {
    if (!event.is_recurring || !event.recurrence_type) {
        return '';
    }

    return ` · ${event.recurrence_type}`;
}

/** Minutes since midnight, ticking each minute, for the line across today. */
const nowMinutes = ref(today.getHours() * 60 + today.getMinutes());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => {
        const now = new Date();
        nowMinutes.value = now.getHours() * 60 + now.getMinutes();
    }, 60_000);
});
onBeforeUnmount(() => clearInterval(clock));

/** ISO week number of the week shown (taken from its Monday). */
const weekNumber = computed(() => {
    const monday = new Date(weekColumns.value[1].dateStr + 'T00:00:00');
    const thursday = new Date(monday);
    thursday.setDate(monday.getDate() + 3);
    const firstThursday = new Date(thursday.getFullYear(), 0, 4);

    return (
        1 +
        Math.round(
            ((thursday.getTime() - firstThursday.getTime()) / 86_400_000 -
                3 +
                ((firstThursday.getDay() + 6) % 7)) /
                7,
        )
    );
});

const dimension = computed(() => {
    if (view.value === 'month') {
        const count = props.events.filter((event) =>
            event.date.startsWith(props.currentMonth),
        ).length;

        return `${calendarTitle.value} · ${count} ${count === 1 ? 'event' : 'events'}`;
    }

    const cols = weekColumns.value;
    const fmt = (dateStr: string) => {
        const d = new Date(dateStr + 'T00:00:00');

        return `${d.toLocaleDateString('en-US', { month: 'short' })} ${String(d.getDate()).padStart(2, '0')}`;
    };

    return `Week ${weekNumber.value} · ${fmt(cols[0].dateStr)} — ${fmt(cols[6].dateStr)}`;
});

const MONTH_WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

/** The big month grid: each day with its events, earliest first. */
const monthCells = computed(() =>
    gridCells.value.map((cell) => ({
        ...cell,
        events: cell.blank
            ? []
            : props.events
                  .filter((event) => event.date === cell.dateStr)
                  .sort((a, b) => a.time.localeCompare(b.time)),
    })),
);

const viewOptions = [
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
] as const;

// ── navigation ─────────────────────────────────────────────────────────────

function navigateMonth(delta: number) {
    const [year, month] = props.currentMonth.split('-').map(Number);
    const d = new Date(year, month - 1 + delta, 1);
    const newMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(
        calendarIndex({ query: { month: newMonth } }).url,
        {},
        { preserveState: false },
    );
}

function navigateWeek(delta: number) {
    const sel = new Date(selectedDate.value + 'T00:00:00');
    sel.setDate(sel.getDate() + delta * 7);
    const newDateStr = toLocalDateStr(sel);
    const newMonth = newDateStr.slice(0, 7);
    selectedDate.value = newDateStr;

    if (newMonth !== props.currentMonth) {
        router.get(
            calendarIndex({ query: { month: newMonth, date: newDateStr } }).url,
            {},
            { preserveState: false },
        );
    }
}

function goToToday() {
    const todayMonth = todayStr.slice(0, 7);
    selectedDate.value = todayStr;

    if (todayMonth !== props.currentMonth) {
        const query =
            view.value === 'week'
                ? { month: todayMonth, date: todayStr }
                : { month: todayMonth };
        router.get(calendarIndex({ query }).url, {}, { preserveState: false });
    }
}

// ── modal ──────────────────────────────────────────────────────────────────

function openNew() {
    editingEvent.value = null;
    form.value = {
        title: '',
        date: selectedDate.value,
        time: '09:00',
        end_time: '10:00',
        tag: 'Work',
        color: TAG_COLORS['Work'],
        recurrence_enabled: false,
        recurrence_type: 'weekly',
        recurrence_interval: 1,
        recurrence_days: [],
        recurrence_ends_on: '',
    };
    showModal.value = true;
}

function openEdit(event: Occurrence) {
    editingEvent.value = event;
    form.value = {
        title: event.title,
        date: event.date,
        time: event.time,
        end_time: event.end_time ?? '',
        tag: event.tag,
        color: event.color,
        recurrence_enabled: event.is_recurring,
        recurrence_type: event.recurrence_type ?? 'weekly',
        recurrence_interval: 1,
        recurrence_days: [],
        recurrence_ends_on: '',
    };
    showModal.value = true;
}

function selectTag(tag: string) {
    form.value.tag = tag;
    form.value.color = TAG_COLORS[tag] ?? '#6b6355';
}

function toggleDay(dow: string) {
    const idx = form.value.recurrence_days.indexOf(dow);

    if (idx === -1) {
        form.value.recurrence_days.push(dow);
    } else {
        form.value.recurrence_days.splice(idx, 1);
    }
}

function recurrenceUnit() {
    return (
        { daily: 'days', weekly: 'weeks', monthly: 'months', yearly: 'years' }[
            form.value.recurrence_type
        ] ?? 'days'
    );
}

function submitForm() {
    const payload: Record<string, FormDataConvertible> = {
        title: form.value.title,
        date: form.value.date,
        time: form.value.time,
        end_time: form.value.end_time || null,
        tag: form.value.tag,
        color: form.value.color,
    };

    if (form.value.recurrence_enabled) {
        payload.recurrence_type = form.value.recurrence_type;
        payload.recurrence_interval = form.value.recurrence_interval;
        payload.recurrence_days =
            form.value.recurrence_type === 'weekly'
                ? form.value.recurrence_days
                : null;
        payload.recurrence_ends_on = form.value.recurrence_ends_on || null;
    } else {
        // Explicitly clear recurrence when toggling off during edit
        payload.recurrence_type = null;
        payload.recurrence_interval = 1;
        payload.recurrence_days = null;
        payload.recurrence_ends_on = null;
    }

    if (editingEvent.value) {
        router.patch(update(editingEvent.value).url, payload, {
            onSuccess: () => {
                showModal.value = false;
            },
        });
    } else {
        router.post(store().url, payload, {
            onSuccess: () => {
                showModal.value = false;
            },
        });
    }
}

function deleteEvent() {
    if (!editingEvent.value) {
        return;
    }

    router.delete(destroy(editingEvent.value).url, {
        onSuccess: () => {
            showModal.value = false;
        },
    });
}
</script>

<template>
    <div class="bp pb-16">
        <Head title="Calendar" />

        <Teleport defer to="#page-actions">
            <button
                type="button"
                class="bp flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
                @click="openNew"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >add</span
                >
                <span class="hidden sm:inline">New event</span>
            </button>
        </Teleport>

        <BlueprintPageHead
            raised
            icon="calendar_month"
            code="D-02"
            section="Day"
            title="Calendar"
            :subtitle="
                view === 'week'
                    ? 'Your week at a glance'
                    : 'Your month at a glance'
            "
            :dimension="dimension"
        />

        <div
            :class="sheetWidth.wide"
            class="grid grid-cols-1 items-start gap-9 lg:grid-cols-[minmax(0,1fr)_300px]"
        >
            <div class="min-w-0">
                <!-- Toolbar -->
                <div class="mb-3.5 flex flex-wrap items-center gap-2.5">
                    <button
                        type="button"
                        class="flex h-7 w-7 items-center justify-center rounded-[3px] border border-[var(--bp-line)] bg-[var(--bp-card)] hover:border-[var(--bp-ink)]"
                        :aria-label="
                            view === 'week' ? 'Previous week' : 'Previous month'
                        "
                        @click="
                            view === 'week'
                                ? navigateWeek(-1)
                                : navigateMonth(-1)
                        "
                    >
                        <span
                            class="ms"
                            style="font-size: 18px"
                            aria-hidden="true"
                            >chevron_left</span
                        >
                    </button>
                    <h2
                        class="min-w-[150px] text-[18px] font-semibold tracking-[-0.01em]"
                    >
                        {{ view === 'week' ? weekRangeLabel : calendarTitle }}
                    </h2>
                    <button
                        type="button"
                        class="flex h-7 w-7 items-center justify-center rounded-[3px] border border-[var(--bp-line)] bg-[var(--bp-card)] hover:border-[var(--bp-ink)]"
                        :aria-label="
                            view === 'week' ? 'Next week' : 'Next month'
                        "
                        @click="
                            view === 'week' ? navigateWeek(1) : navigateMonth(1)
                        "
                    >
                        <span
                            class="ms"
                            style="font-size: 18px"
                            aria-hidden="true"
                            >chevron_right</span
                        >
                    </button>
                    <button
                        type="button"
                        class="rounded-[3px] border border-[var(--bp-line)] px-2.5 py-1 text-[12.5px] hover:border-[var(--bp-ink)]"
                        @click="goToToday"
                    >
                        Today
                    </button>
                    <div
                        class="ml-auto flex overflow-hidden rounded-[3px] border border-[var(--bp-ink)]"
                        role="radiogroup"
                        aria-label="Calendar view"
                    >
                        <button
                            v-for="option in viewOptions"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="view === option.value"
                            class="px-3.5 py-1 text-[13px]"
                            :class="
                                view === option.value
                                    ? 'bg-[var(--bp-ink)] font-semibold text-[var(--bp-card)]'
                                    : 'text-[var(--bp-ink-2)] hover:bg-[var(--bp-hover)]'
                            "
                            @click="view = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <!-- ── Week view ── -->
                <div
                    v-if="view === 'week'"
                    class="overflow-x-auto border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                >
                    <div
                        ref="timeGridRef"
                        class="max-h-[616px] min-w-[640px] overflow-y-auto"
                    >
                        <div
                            class="sticky top-0 z-10 grid grid-cols-[56px_repeat(7,minmax(0,1fr))] border-b border-[var(--bp-ink)] bg-[var(--bp-card)]"
                        >
                            <span></span>
                            <button
                                v-for="col in weekColumns"
                                :key="col.dateStr"
                                type="button"
                                class="flex items-baseline gap-1.5 border-l border-[var(--bp-hair)] px-2 pt-2 pb-[7px] text-left hover:bg-[var(--bp-paper)]"
                                :aria-pressed="selectedDate === col.dateStr"
                                @click="selectedDate = col.dateStr"
                            >
                                <span
                                    class="bp-mono text-[10px] tracking-[0.12em] text-[var(--bp-muted)] uppercase"
                                    >{{ col.dow }}</span
                                >
                                <span
                                    class="text-[16px] font-semibold"
                                    :class="{
                                        'bg-[var(--bp-accent)] px-[5px] text-[var(--bp-card)]':
                                            col.isToday,
                                        'underline decoration-2 underline-offset-4':
                                            !col.isToday &&
                                            selectedDate === col.dateStr,
                                    }"
                                    >{{ col.dateNum }}</span
                                >
                            </button>
                        </div>

                        <div
                            class="grid h-[1440px] grid-cols-[56px_repeat(7,minmax(0,1fr))] bg-[linear-gradient(var(--bp-hair)_1px,transparent_1px)] bg-[length:100%_60px]"
                        >
                            <div class="relative" aria-hidden="true">
                                <span
                                    v-for="hour in 23"
                                    :key="hour"
                                    class="bp-mono absolute right-2 -translate-y-1/2 bg-[var(--bp-card)] px-0.5 text-[10px] text-[var(--bp-faint)]"
                                    :style="{ top: `${hour * 60}px` }"
                                    >{{
                                        String(hour).padStart(2, '0')
                                    }}:00</span
                                >
                            </div>
                            <div
                                v-for="col in weekColumns"
                                :key="col.dateStr"
                                class="relative border-l border-[var(--bp-hair)]"
                                :class="{
                                    'bg-[color-mix(in_oklch,var(--bp-accent)_4%,transparent)]':
                                        col.isToday,
                                }"
                            >
                                <button
                                    v-for="event in col.events"
                                    :key="event.id + event.date"
                                    type="button"
                                    class="absolute inset-x-[3px] z-[1] box-border overflow-hidden border text-left hover:border-[var(--bp-ink)]"
                                    :class="
                                        eventHeight(
                                            event.time,
                                            event.end_time,
                                        ) >= 45
                                            ? 'px-1.5 py-1'
                                            : 'px-1.5 py-0.5'
                                    "
                                    :style="{
                                        top: `${eventTop(event.time) + 1}px`,
                                        height: `${eventHeight(event.time, event.end_time) - 2}px`,
                                        background: tagFill(event.tag).bg,
                                        borderColor: tagFill(event.tag).bd,
                                    }"
                                    :title="`${event.title}, ${timeRange(event)}`"
                                    @click="openEdit(event)"
                                >
                                    <span
                                        class="block truncate text-[11.5px] leading-tight font-semibold"
                                        >{{ event.title }}</span
                                    >
                                    <span
                                        v-if="
                                            eventHeight(
                                                event.time,
                                                event.end_time,
                                            ) >= 45
                                        "
                                        class="bp-mono block text-[9.5px] whitespace-nowrap text-[var(--bp-muted)]"
                                        >{{ timeRange(event) }}</span
                                    >
                                </button>
                                <div
                                    v-if="col.isToday"
                                    class="pointer-events-none absolute right-0 -left-1 z-[2] h-[1.5px] bg-[var(--bp-accent)]"
                                    :style="{ top: `${nowMinutes}px` }"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="absolute -top-[3.5px] left-0 h-2 w-2 rotate-45 bg-[var(--bp-accent)]"
                                    ></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Month view ── -->
                <div
                    v-else
                    class="border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                >
                    <div
                        class="grid grid-cols-7 border-b border-[var(--bp-ink)]"
                        aria-hidden="true"
                    >
                        <span
                            v-for="weekday in MONTH_WEEKDAYS"
                            :key="weekday"
                            class="bp-mono px-1 py-[7px] text-[9px] tracking-[0.12em] text-[var(--bp-muted)] sm:px-2 sm:text-[10px]"
                            >{{ weekday }}</span
                        >
                    </div>
                    <div class="grid grid-cols-7">
                        <template
                            v-for="(cell, i) in monthCells"
                            :key="cell.dateStr || `blank-${i}`"
                        >
                            <div
                                v-if="cell.blank"
                                class="min-h-16 border-r border-b border-[var(--bp-hair)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.04)_0_1px,transparent_1px_7px)] sm:min-h-[104px]"
                                aria-hidden="true"
                            ></div>
                            <button
                                v-else
                                type="button"
                                class="flex min-h-16 min-w-0 flex-col gap-[3px] border-r border-b border-[var(--bp-hair)] p-1 text-left hover:bg-[var(--bp-paper)] sm:min-h-[104px] sm:p-1.5"
                                :class="{
                                    'opacity-50': cell.dateStr < todayStr,
                                    'shadow-[inset_0_0_0_1.5px_var(--bp-accent)]':
                                        selectedDate === cell.dateStr,
                                }"
                                :aria-pressed="selectedDate === cell.dateStr"
                                :aria-label="`${cell.dateStr}${cell.events.length ? `, ${cell.events.length} ${cell.events.length === 1 ? 'event' : 'events'}` : ''}`"
                                @click="selectedDate = cell.dateStr"
                            >
                                <span
                                    v-if="cell.isToday"
                                    class="bp-mono flex h-5 min-w-5 items-center justify-center self-start bg-[var(--bp-accent)] px-1 text-[11px] font-semibold text-[var(--bp-card)]"
                                    >{{ cell.day }}</span
                                >
                                <span
                                    v-else
                                    class="bp-mono text-[11px] font-medium"
                                    >{{ cell.day }}</span
                                >
                                <span
                                    v-for="event in cell.events.slice(0, 2)"
                                    :key="event.id + event.date"
                                    class="hidden truncate border px-[5px] py-px text-[10.5px] leading-normal sm:block"
                                    :style="{
                                        background: tagFill(event.tag).bg,
                                        borderColor: tagFill(event.tag).bd,
                                    }"
                                    aria-hidden="true"
                                    >{{ shortTime(event.time) }}
                                    {{ event.title }}</span
                                >
                                <span
                                    v-if="cell.events.length > 2"
                                    class="hidden text-[10.5px] text-[var(--bp-muted)] sm:block"
                                    aria-hidden="true"
                                    >+{{ cell.events.length - 2 }} more</span
                                >
                                <span
                                    v-if="cell.events.length > 0"
                                    class="mt-auto flex gap-0.5 sm:hidden"
                                    aria-hidden="true"
                                >
                                    <span
                                        v-for="event in cell.events.slice(0, 3)"
                                        :key="`dot-${event.id}`"
                                        class="h-1.5 w-1.5 rotate-45 border"
                                        :style="{
                                            background: tagFill(event.tag).bg,
                                            borderColor: tagFill(event.tag).bd,
                                        }"
                                    ></span>
                                </span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="flex flex-col gap-3.5 lg:sticky lg:top-[76px]">
                <div class="border border-[var(--bp-ink)] bg-[var(--bp-card)]">
                    <div
                        class="flex items-center justify-between border-b border-[var(--bp-ink)] px-3 py-2.5"
                    >
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center rounded-[3px] hover:bg-[var(--bp-hover)]"
                            aria-label="Previous month"
                            @click="navigateMonth(-1)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >chevron_left</span
                            >
                        </button>
                        <span
                            class="bp-mono text-[11px] font-semibold tracking-[0.12em] uppercase"
                            >{{ calendarTitle }}</span
                        >
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center rounded-[3px] hover:bg-[var(--bp-hover)]"
                            aria-label="Next month"
                            @click="navigateMonth(1)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >chevron_right</span
                            >
                        </button>
                    </div>
                    <div
                        class="grid grid-cols-7 gap-y-0.5 px-2.5 pt-2 pb-2.5 text-center"
                    >
                        <span
                            v-for="(w, i) in WEEKDAYS_SHORT"
                            :key="`w${i}`"
                            class="bp-mono py-1 text-[9.5px] text-[var(--bp-faint)]"
                            aria-hidden="true"
                            >{{ w }}</span
                        >
                        <template v-for="(cell, i) in gridCells" :key="i">
                            <span v-if="cell.blank"></span>
                            <button
                                v-else
                                type="button"
                                class="flex h-8 flex-col items-center justify-center gap-0.5"
                                :aria-pressed="selectedDate === cell.dateStr"
                                :aria-label="cell.dateStr"
                                @click="selectedDate = cell.dateStr"
                            >
                                <span
                                    class="bp-mono box-border flex h-5 w-6 items-center justify-center text-[11.5px]"
                                    :class="
                                        cell.isToday
                                            ? 'bg-[var(--bp-accent)] font-semibold text-[var(--bp-card)]'
                                            : selectedDate === cell.dateStr
                                              ? 'border border-[var(--bp-ink)] font-semibold'
                                              : cell.dateStr < todayStr
                                                ? 'text-[var(--bp-faint)]'
                                                : ''
                                    "
                                    >{{ cell.day }}</span
                                >
                                <span
                                    class="h-1 w-1"
                                    :class="
                                        cell.hasEvent
                                            ? 'bg-[var(--bp-accent)]'
                                            : 'bg-transparent'
                                    "
                                    aria-hidden="true"
                                ></span>
                            </button>
                        </template>
                    </div>
                </div>

                <section aria-live="polite">
                    <div
                        class="flex items-center justify-between border-b border-[var(--bp-ink)] pb-2"
                    >
                        <h2
                            class="bp-mono text-[11px] font-semibold tracking-[0.12em]"
                        >
                            {{ agendaLabel }}
                        </h2>
                        <span
                            v-if="agendaEvents.length"
                            class="bp-mono text-[10.5px] text-[var(--bp-faint)]"
                            >{{ agendaEvents.length }}
                            {{
                                agendaEvents.length === 1 ? 'EVENT' : 'EVENTS'
                            }}</span
                        >
                    </div>
                    <p
                        v-if="agendaEvents.length === 0"
                        class="my-3 text-[13px] text-[var(--bp-muted)]"
                    >
                        Nothing scheduled.
                    </p>
                    <button
                        v-for="event in agendaEvents"
                        :key="event.id"
                        type="button"
                        class="grid w-full grid-cols-[84px_10px_minmax(0,1fr)] items-center gap-2.5 border-b border-[var(--bp-hair)] py-2.5 text-left hover:bg-[var(--bp-hover)]"
                        @click="openEdit(event)"
                    >
                        <span
                            class="bp-mono text-[11px] text-[var(--bp-ink-2)]"
                            >{{ timeRange(event) }}</span
                        >
                        <span
                            class="box-border h-2.5 w-2.5 border"
                            :style="{
                                background: tagFill(event.tag).bg,
                                borderColor: tagFill(event.tag).bd,
                            }"
                            aria-hidden="true"
                        ></span>
                        <span class="min-w-0">
                            <span
                                class="block truncate text-[13.5px] font-semibold"
                                >{{ event.title }}</span
                            >
                            <span
                                class="block text-[11.5px] text-[var(--bp-muted)]"
                                >{{ event.tag
                                }}{{ recurrenceText(event) }}</span
                            >
                        </span>
                    </button>
                </section>

                <div
                    class="flex flex-wrap gap-x-3.5 gap-y-2 border border-[var(--bp-line)] px-3.5 py-3 text-[12px] text-[var(--bp-ink-2)]"
                >
                    <span
                        v-for="tag in TAGS"
                        :key="tag"
                        class="flex items-center gap-1.5"
                        ><span
                            class="h-2.5 w-3.5 border"
                            :style="{
                                background: tagFill(tag).bg,
                                borderColor: tagFill(tag).bd,
                            }"
                        ></span
                        >{{ tag }}</span
                    >
                </div>
            </div>
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock drawing="Day / Calendar" sheet="D-02" />
        </div>

        <!-- ── New / Edit event modal ── -->
        <Dialog :open="showModal" @update:open="showModal = $event">
            <DialogContent style="max-width: 480px">
                <DialogHeader>
                    <p class="bp-caption">
                        {{ editingEvent ? 'Event' : 'New event' }} · D-02
                    </p>
                    <DialogTitle>{{
                        editingEvent ? 'Edit event' : 'New event'
                    }}</DialogTitle>
                </DialogHeader>
                <form
                    class="flex flex-col gap-3.5"
                    @submit.prevent="submitForm"
                >
                    <div>
                        <label for="event-title" :class="formLabel"
                            >Title</label
                        >
                        <input
                            id="event-title"
                            v-model="form.title"
                            type="text"
                            placeholder="Event title"
                            :class="formField"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                        <div>
                            <label for="event-date" :class="formLabel"
                                >Date</label
                            >
                            <input
                                id="event-date"
                                v-model="form.date"
                                type="date"
                                :class="[formField, 'bp-mono']"
                            />
                        </div>
                        <div>
                            <label for="event-start" :class="formLabel"
                                >Start</label
                            >
                            <input
                                id="event-start"
                                v-model="form.time"
                                type="time"
                                :class="[formField, 'bp-mono']"
                            />
                        </div>
                        <div>
                            <label for="event-end" :class="formLabel"
                                >End</label
                            >
                            <input
                                id="event-end"
                                v-model="form.end_time"
                                type="time"
                                :class="[formField, 'bp-mono']"
                            />
                        </div>
                    </div>

                    <div>
                        <span id="event-tag-label" :class="formLabel">Tag</span>
                        <div
                            class="flex flex-wrap gap-1.5"
                            role="radiogroup"
                            aria-labelledby="event-tag-label"
                        >
                            <button
                                v-for="tag in TAGS"
                                :key="tag"
                                type="button"
                                role="radio"
                                :aria-checked="form.tag === tag"
                                class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-[12.5px]"
                                :class="
                                    form.tag === tag
                                        ? 'border-[var(--bp-ink)] bg-[var(--bp-ink)] font-semibold text-[var(--bp-card)]'
                                        : 'border-[var(--bp-line)] text-[var(--bp-ink-2)] hover:border-[var(--bp-ink)]'
                                "
                                @click="selectTag(tag)"
                            >
                                <span
                                    class="h-2 w-2.5 border"
                                    :style="{
                                        background: tagFill(tag).bg,
                                        borderColor: tagFill(tag).bd,
                                    }"
                                    aria-hidden="true"
                                ></span>
                                {{ tag }}
                            </button>
                        </div>
                    </div>

                    <label
                        class="flex cursor-pointer items-center gap-2.5 text-[13.5px] font-semibold"
                    >
                        <input
                            v-model="form.recurrence_enabled"
                            type="checkbox"
                            class="peer sr-only"
                        />
                        <span
                            :class="
                                form.recurrence_enabled
                                    ? 'msf text-[var(--bp-accent)]'
                                    : 'ms text-[var(--bp-muted)]'
                            "
                            class="rounded-sm peer-focus-visible:outline-2 peer-focus-visible:outline-[var(--bp-accent)]"
                            style="font-size: 20px"
                            aria-hidden="true"
                            >{{
                                form.recurrence_enabled
                                    ? 'check_box'
                                    : 'check_box_outline_blank'
                            }}</span
                        >
                        Repeat
                    </label>

                    <div
                        v-if="form.recurrence_enabled"
                        class="bp-grid-fine flex flex-col gap-3 border border-[var(--bp-line)] p-3.5"
                    >
                        <div>
                            <label for="event-frequency" :class="formLabel"
                                >Frequency</label
                            >
                            <select
                                id="event-frequency"
                                v-model="form.recurrence_type"
                                :class="formField"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <label
                                for="event-interval"
                                :class="formLabel"
                                class="mb-0"
                                >Every</label
                            >
                            <input
                                id="event-interval"
                                v-model.number="form.recurrence_interval"
                                type="number"
                                min="1"
                                max="99"
                                :class="[formField, 'bp-mono w-16 text-center']"
                            />
                            <span
                                class="text-[13.5px] text-[var(--bp-muted)]"
                                >{{ recurrenceUnit() }}</span
                            >
                        </div>
                        <div v-if="form.recurrence_type === 'weekly'">
                            <span id="event-days-label" :class="formLabel"
                                >On</span
                            >
                            <div
                                class="flex flex-wrap gap-[5px]"
                                role="group"
                                aria-labelledby="event-days-label"
                            >
                                <button
                                    v-for="dow in DOW_LABELS"
                                    :key="dow"
                                    type="button"
                                    :aria-pressed="
                                        form.recurrence_days.includes(dow)
                                    "
                                    :aria-label="dow"
                                    class="bp-mono flex h-9 w-9 items-center justify-center border text-[11.5px]"
                                    :class="
                                        form.recurrence_days.includes(dow)
                                            ? 'border-[var(--bp-ink)] bg-[var(--bp-ink)] font-semibold text-[var(--bp-card)]'
                                            : 'border-[var(--bp-line)] bg-[var(--bp-card)] text-[var(--bp-ink-2)] hover:border-[var(--bp-ink)]'
                                    "
                                    @click="toggleDay(dow)"
                                >
                                    {{ dow.slice(0, 2).toUpperCase() }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label for="event-ends" :class="formLabel"
                                >Ends on (optional)</label
                            >
                            <input
                                id="event-ends"
                                v-model="form.recurrence_ends_on"
                                type="date"
                                :class="[formField, 'bp-mono']"
                            />
                        </div>
                    </div>

                    <div
                        class="-mx-6 mt-1 -mb-6 flex items-center gap-2 border-t border-[var(--bp-hair)] px-6 py-4"
                    >
                        <Button
                            v-if="editingEvent"
                            type="button"
                            variant="ghost"
                            class="text-[#a3311f]"
                            @click="deleteEvent"
                            >Delete</Button
                        >
                        <div class="ml-auto flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                @click="showModal = false"
                                >Cancel</Button
                            >
                            <Button type="submit">{{
                                editingEvent ? 'Save' : 'Create'
                            }}</Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
