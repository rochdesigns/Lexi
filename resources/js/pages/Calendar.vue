<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/CalendarController';
import { store, update, destroy } from '@/actions/App/Http/Controllers/EventController';

type Occurrence = {
    id: number;
    title: string;
    date: string;
    time: string;
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

// ── state ──────────────────────────────────────────────────────────────────

const today = new Date();
const todayStr = today.toISOString().slice(0, 10);

const view = ref<'month' | 'week'>((localStorage.getItem('cal-view') as 'month' | 'week') ?? 'month');
const selectedDate = ref(todayStr);
const showModal = ref(false);
const editingEvent = ref<Occurrence | null>(null);

// Form state
const form = ref({
    title: '',
    date: todayStr,
    time: '09:00',
    tag: 'Work' as string,
    color: TAG_COLORS['Work'],
    recurrence_enabled: false,
    recurrence_type: 'weekly' as string,
    recurrence_interval: 1,
    recurrence_days: [] as string[],
    recurrence_ends_on: '',
});

watch(view, (v) => localStorage.setItem('cal-view', v));

// ── computed: month grid ──────────────────────────────────────────────────

const calendarTitle = computed(() => {
    const [year, month] = props.currentMonth.split('-').map(Number);
    return new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});

const gridCells = computed(() => {
    const [year, month] = props.currentMonth.split('-').map(Number);
    const firstDow = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const daysInMonth = new Date(year, month, 0).getDate();
    const eventDays = new Set(
        props.events
            .filter((e) => e.date.startsWith(props.currentMonth))
            .map((e) => Number(e.date.slice(8, 10)))
    );

    const cells = [];
    for (let i = 0; i < firstDow; i++) {
        cells.push({ blank: true, day: 0, dateStr: '', isToday: false, hasEvent: false, dotColor: '' });
    }
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${props.currentMonth}-${String(d).padStart(2, '0')}`;
        const isToday = dateStr === todayStr;
        cells.push({ blank: false, day: d, dateStr, isToday, hasEvent: eventDays.has(d), dotColor: isToday ? '#fff' : 'var(--hab-accent)' });
    }
    return cells;
});

// ── computed: agenda ──────────────────────────────────────────────────────

const agendaEvents = computed(() =>
    props.events.filter((e) => e.date === selectedDate.value).sort((a, b) => a.time.localeCompare(b.time))
);

const agendaLabel = computed(() => {
    const d = new Date(selectedDate.value + 'T00:00:00');
    const label = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }).toUpperCase();
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
        const dateStr = d.toISOString().slice(0, 10);
        return {
            dow: WEEKDAYS_FULL[i],
            dateNum: d.getDate(),
            dateStr,
            isToday: dateStr === todayStr,
            events: props.events.filter((e) => e.date === dateStr).sort((a, b) => a.time.localeCompare(b.time)),
        };
    });
});

const weekRangeLabel = computed(() => {
    const cols = weekColumns.value;
    const first = new Date(cols[0].dateStr + 'T00:00:00');
    const last = new Date(cols[6].dateStr + 'T00:00:00');
    const fmt = (d: Date) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    return `${fmt(first)} – ${fmt(last)}`;
});

// ── navigation ─────────────────────────────────────────────────────────────

function navigateMonth(delta: number) {
    const [year, month] = props.currentMonth.split('-').map(Number);
    const d = new Date(year, month - 1 + delta, 1);
    const newMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(calendarIndex({ month: newMonth }).url, {}, { preserveState: false });
}

function navigateWeek(delta: number) {
    const sel = new Date(selectedDate.value + 'T00:00:00');
    sel.setDate(sel.getDate() + delta * 7);
    const newDateStr = sel.toISOString().slice(0, 10);
    const newMonth = newDateStr.slice(0, 7);
    selectedDate.value = newDateStr;
    if (newMonth !== props.currentMonth) {
        router.get(calendarIndex({ month: newMonth }).url, {}, { preserveState: false });
    }
}

// ── modal ──────────────────────────────────────────────────────────────────

function openNew() {
    editingEvent.value = null;
    form.value = {
        title: '',
        date: selectedDate.value,
        time: '09:00',
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
    return { daily: 'days', weekly: 'weeks', monthly: 'months', yearly: 'years' }[form.value.recurrence_type] ?? 'days';
}

function submitForm() {
    const payload: Record<string, unknown> = {
        title: form.value.title,
        date: form.value.date,
        time: form.value.time,
        tag: form.value.tag,
        color: form.value.color,
    };

    if (form.value.recurrence_enabled) {
        payload.recurrence_type = form.value.recurrence_type;
        payload.recurrence_interval = form.value.recurrence_interval;
        payload.recurrence_days = form.value.recurrence_type === 'weekly' ? form.value.recurrence_days : null;
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
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        router.post(store().url, payload, {
            onSuccess: () => { showModal.value = false; },
        });
    }
}

function deleteEvent() {
    if (!editingEvent.value) { return; }
    router.delete(destroy(editingEvent.value).url, {
        onSuccess: () => { showModal.value = false; },
    });
}
</script>

<template>
    <Head title="Calendar" />

    <!-- Page header -->
    <div class="flex items-end gap-4 flex-wrap">
        <div>
            <h1 style="font-family:'Bricolage Grotesque';font-weight:700;font-size:28px;letter-spacing:-.02em;line-height:1">Calendar</h1>
            <p style="color:#8a8172;font-size:14px;margin-top:4px">Your month at a glance</p>
        </div>
        <div class="flex items-center gap-3 ml-auto">
            <!-- Month/Week toggle -->
            <div style="display:flex;gap:4px;background:#fffdf8;border:1px solid #e0dacd;border-radius:13px;padding:4px">
                <button
                    @click="view = 'month'"
                    :style="`padding:7px 15px;border-radius:11px;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'Instrument Sans';color:${view==='month'?'#211d17':'#8a8172'};background:${view==='month'?'#f1ede3':'transparent'}`"
                >Month</button>
                <button
                    @click="view = 'week'"
                    :style="`padding:7px 15px;border-radius:11px;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'Instrument Sans';color:${view==='week'?'#211d17':'#8a8172'};background:${view==='week'?'#f1ede3':'transparent'}`"
                >Week</button>
            </div>
            <!-- New event button -->
            <button
                @click="openNew"
                style="display:flex;align-items:center;gap:7px;background:#211d17;color:#fdfbf5;border:none;border-radius:12px;padding:0 16px;height:42px;font-family:'Instrument Sans';font-weight:600;font-size:13.5px;cursor:pointer"
            >
                <span class="msr" style="font-size:18px">add</span> New event
            </button>
        </div>
    </div>

    <!-- ── Month view ── -->
    <div v-if="view === 'month'" style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

        <!-- Month grid -->
        <div style="background:#fffdf8;border:1px solid #e0dacd;border-radius:24px;padding:22px 24px;box-shadow:0 8px 24px -20px rgba(60,48,20,.5)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                <div style="font-family:'Bricolage Grotesque';font-weight:700;font-size:18px">{{ calendarTitle }}</div>
                <div style="display:flex;gap:6px">
                    <button @click="navigateMonth(-1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                        <span class="msr" style="font-size:20px">chevron_left</span>
                    </button>
                    <button @click="navigateMonth(1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                        <span class="msr" style="font-size:20px">chevron_right</span>
                    </button>
                </div>
            </div>

            <!-- Weekday headers -->
            <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-bottom:6px">
                <div v-for="w in WEEKDAYS_SHORT" :key="w" style="text-align:center;font-size:11px;font-weight:600;color:#a49b8a">{{ w }}</div>
            </div>

            <!-- Day cells -->
            <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px">
                <template v-for="(cell, i) in gridCells" :key="i">
                    <div v-if="cell.blank" style="aspect-ratio:1" />
                    <button
                        v-else
                        @click="selectedDate = cell.dateStr"
                        :style="`aspect-ratio:1;display:flex;flex-direction:column;align-items:center;justify-content:center;border:none;border-radius:10px;cursor:pointer;font-size:13.5px;font-family:'Instrument Sans';
                            ${cell.isToday ? 'background:var(--hab-accent);color:#fff;font-weight:700'
                            : cell.dateStr === selectedDate ? 'background:#f1ede3;color:#211d17;font-weight:600'
                            : 'background:transparent;color:#4a4436'}`"
                    >
                        {{ cell.day }}
                        <span v-if="cell.hasEvent" :style="`width:5px;height:5px;border-radius:50%;background:${cell.dotColor};margin-top:3px`" />
                    </button>
                </template>
            </div>
        </div>

        <!-- Agenda panel -->
        <div style="background:#fffdf8;border:1px solid #e0dacd;border-radius:22px;padding:20px 20px 8px;box-shadow:0 8px 24px -20px rgba(60,48,20,.5)">
            <div style="font-family:'Bricolage Grotesque';font-weight:700;font-size:16px;margin-bottom:6px">Agenda</div>
            <div style="font-size:11.5px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:#a49b8a;margin-bottom:4px">
                {{ agendaLabel }}
            </div>

            <div v-if="agendaEvents.length === 0" style="padding:24px 0;text-align:center;color:#a49b8a;font-size:13.5px">
                No events
            </div>

            <div
                v-for="event in agendaEvents"
                :key="event.id"
                class="group/event"
                style="display:flex;align-items:center;gap:11px;padding:10px 0;border-top:1px solid #efe9dc"
            >
                <div :style="`width:9px;height:9px;border-radius:50%;background:${event.color};flex:none`" />
                <div style="min-width:0;flex:1">
                    <div style="font-weight:600;font-size:14px;color:#211d17">{{ event.title }}</div>
                    <div style="font-size:12px;color:#8a8172">{{ event.time }}</div>
                </div>
                <span :style="`font-size:11.5px;font-weight:600;color:${event.color};background:${event.bg};padding:2px 9px;border-radius:20px`">
                    {{ event.tag }}
                </span>
                <button
                    @click="openEdit(event)"
                    class="opacity-0 group-hover/event:opacity-100"
                    style="border:none;background:transparent;cursor:pointer;color:#8a8172;padding:2px;flex:none;display:flex;align-items:center"
                >
                    <span class="msr" style="font-size:16px">edit</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Week view ── -->
    <div v-else style="background:#fffdf8;border:1px solid #e0dacd;border-radius:24px;padding:22px 24px;box-shadow:0 8px 24px -20px rgba(60,48,20,.5)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <div style="font-family:'Bricolage Grotesque';font-weight:700;font-size:18px">{{ weekRangeLabel }}</div>
            <div style="display:flex;gap:6px">
                <button @click="navigateWeek(-1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                    <span class="msr" style="font-size:20px">chevron_left</span>
                </button>
                <button @click="navigateWeek(1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                    <span class="msr" style="font-size:20px">chevron_right</span>
                </button>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;align-items:start">
            <div v-for="col in weekColumns" :key="col.dateStr" style="display:flex;flex-direction:column;gap:8px;min-height:220px">
                <!-- Day header -->
                <div
                    :style="`display:flex;flex-direction:column;align-items:center;gap:1px;padding:8px 0;border-radius:10px;
                        ${col.isToday ? 'background:var(--hab-accent);color:#fff' : 'color:#4a4436'}`"
                >
                    <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;opacity:.75">{{ col.dow.slice(0, 3) }}</span>
                    <span style="font-family:'Bricolage Grotesque';font-weight:700;font-size:17px">{{ col.dateNum }}</span>
                </div>
                <!-- Event chips -->
                <div
                    v-for="event in col.events"
                    :key="event.id + event.date"
                    @click="openEdit(event)"
                    :style="`background:${event.bg};border-left:3px solid ${event.color};border-radius:8px;padding:7px 9px;cursor:pointer`"
                >
                    <div :style="`font-size:11px;color:${event.color};font-weight:600`">{{ event.time }}</div>
                    <div style="font-size:12.5px;font-weight:600;color:#211d17;line-height:1.2;margin-top:1px">{{ event.title }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── New / Edit event modal ── -->
    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent style="max-width:480px">
            <DialogHeader>
                <DialogTitle>{{ editingEvent ? 'Edit event' : 'New event' }}</DialogTitle>
            </DialogHeader>

            <div style="display:flex;flex-direction:column;gap:14px;padding-top:4px">

                <!-- Title -->
                <div>
                    <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Title</label>
                    <input
                        v-model="form.title"
                        type="text"
                        placeholder="Event title"
                        style="width:100%;padding:9px 12px;border:1px solid #e0dacd;border-radius:11px;font-family:'Instrument Sans';font-size:14px;color:#211d17;background:#fffdf8;outline:none"
                    />
                </div>

                <!-- Date + Time row -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Date</label>
                        <input
                            v-model="form.date"
                            type="date"
                            style="width:100%;padding:9px 12px;border:1px solid #e0dacd;border-radius:11px;font-family:'Instrument Sans';font-size:14px;color:#211d17;background:#fffdf8;outline:none"
                        />
                    </div>
                    <div>
                        <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Time</label>
                        <input
                            v-model="form.time"
                            type="time"
                            style="width:100%;padding:9px 12px;border:1px solid #e0dacd;border-radius:11px;font-family:'Instrument Sans';font-size:14px;color:#211d17;background:#fffdf8;outline:none"
                        />
                    </div>
                </div>

                <!-- Tag chips -->
                <div>
                    <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:6px">Tag</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button
                            v-for="tag in TAGS"
                            :key="tag"
                            @click="selectTag(tag)"
                            :style="`padding:5px 12px;border-radius:20px;border:none;cursor:pointer;font-family:'Instrument Sans';font-size:12.5px;font-weight:600;
                                ${form.tag === tag
                                    ? `background:${TAG_COLORS[tag]};color:#fff`
                                    : 'background:#f1ede3;color:#6b6355'}`"
                        >{{ tag }}</button>
                    </div>
                </div>

                <!-- Recurrence toggle -->
                <div style="display:flex;align-items:center;gap:10px">
                    <input
                        id="recurrence-toggle"
                        type="checkbox"
                        v-model="form.recurrence_enabled"
                        style="width:16px;height:16px;cursor:pointer;accent-color:var(--hab-accent)"
                    />
                    <label for="recurrence-toggle" style="font-size:13.5px;font-weight:600;color:#211d17;cursor:pointer">Repeat</label>
                </div>

                <!-- Recurrence options -->
                <div v-if="form.recurrence_enabled" style="background:#f9f6ef;border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:12px">

                    <!-- Type select -->
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Frequency</label>
                        <select
                            v-model="form.recurrence_type"
                            style="width:100%;padding:8px 12px;border:1px solid #e0dacd;border-radius:10px;font-family:'Instrument Sans';font-size:13.5px;color:#211d17;background:#fffdf8;outline:none"
                        >
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>

                    <!-- Interval -->
                    <div style="display:flex;align-items:center;gap:8px">
                        <label style="font-size:12px;font-weight:600;color:#6b6355;white-space:nowrap">Every</label>
                        <input
                            v-model.number="form.recurrence_interval"
                            type="number"
                            min="1"
                            max="99"
                            style="width:60px;padding:7px 10px;border:1px solid #e0dacd;border-radius:10px;font-family:'Instrument Sans';font-size:13.5px;color:#211d17;background:#fffdf8;outline:none;text-align:center"
                        />
                        <span style="font-size:13.5px;color:#6b6355">{{ recurrenceUnit() }}</span>
                    </div>

                    <!-- Weekday picker (weekly only) -->
                    <div v-if="form.recurrence_type === 'weekly'">
                        <label style="font-size:12px;font-weight:600;color:#6b6355;display:block;margin-bottom:6px">On</label>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <button
                                v-for="dow in DOW_LABELS"
                                :key="dow"
                                @click="toggleDay(dow)"
                                :style="`width:38px;height:38px;border-radius:50%;border:none;cursor:pointer;font-family:'Instrument Sans';font-size:12px;font-weight:600;
                                    ${form.recurrence_days.includes(dow)
                                        ? 'background:var(--hab-accent);color:#fff'
                                        : 'background:#fffdf8;color:#6b6355;border:1px solid #e0dacd'}`"
                            >{{ dow.slice(0, 2) }}</button>
                        </div>
                    </div>

                    <!-- Ends on -->
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Ends on <span style="font-weight:400">(optional)</span></label>
                        <input
                            v-model="form.recurrence_ends_on"
                            type="date"
                            style="width:100%;padding:8px 12px;border:1px solid #e0dacd;border-radius:10px;font-family:'Instrument Sans';font-size:13.5px;color:#211d17;background:#fffdf8;outline:none"
                        />
                    </div>
                </div>

                <!-- Actions -->
                <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:4px">
                    <button
                        v-if="editingEvent"
                        @click="deleteEvent"
                        style="padding:0 14px;height:38px;border:none;border-radius:10px;cursor:pointer;font-family:'Instrument Sans';font-weight:600;font-size:13px;background:#fbe2d6;color:#c0512c"
                    >Delete</button>
                    <button
                        @click="showModal = false"
                        style="padding:0 14px;height:38px;border:1px solid #e0dacd;border-radius:10px;cursor:pointer;font-family:'Instrument Sans';font-weight:600;font-size:13px;background:#fffdf8;color:#6b6355"
                    >Cancel</button>
                    <button
                        @click="submitForm"
                        style="padding:0 16px;height:38px;border:none;border-radius:10px;cursor:pointer;font-family:'Instrument Sans';font-weight:600;font-size:13px;background:#211d17;color:#fdfbf5"
                    >{{ editingEvent ? 'Save' : 'Create' }}</button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
