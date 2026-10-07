<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    store as storeVisionBoardItem,
    update as updateVisionBoardItem,
    destroy as destroyVisionBoardItem,
} from '@/actions/App/Http/Controllers/VisionBoardItemController';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { LexiTheme } from '@/types';
import { formError, formField, formLabel, sheetWidth } from '@/utils/blueprint';
import { formatDay, formatPeso } from '@/utils/money';

type TodayEvent = {
    id: number;
    title: string;
    time: string;
    end_time: string | null;
    tag: string;
    color: string;
};
type TodayTask = {
    id: number;
    label: string;
    section_id: number;
    done: boolean;
};
type Habit = {
    id: number;
    name: string;
    icon: string;
    color: string;
    pct: number;
    streak: number;
};
type HealthMetric = {
    name: string;
    value: string;
    sub: string;
    pct: number;
    color: string;
    icon: string;
};
type VisionBoardItem = { id: number; imageUrl: string; caption: string };

const props = defineProps<{
    user: { name: string; firstName: string; theme: LexiTheme };
    todayEvents: TodayEvent[];
    todayTasks: TodayTask[];
    habits: Habit[];
    healthMetrics: HealthMetric[];
    visionBoardItems: VisionBoardItem[];
    moneySummary: {
        safeToSpend: number;
        perDay: number;
        dueSoonCount: number;
        dueSoon: { date: string; name: string; amount: number }[];
    } | null;
}>();

// Optimistic task toggle
const localTasks = ref(props.todayTasks.map((t) => ({ ...t })));

const undoneCount = computed(
    () => localTasks.value.filter((t) => !t.done).length,
);

function toggleTask(task: (typeof localTasks.value)[0]): void {
    const prev = task.done;
    task.done = !prev;
    router.patch(
        `/tasks/${task.id}`,
        { done: task.done },
        {
            preserveScroll: true,
            onError: () => {
                task.done = prev; // revert on error
            },
        },
    );
}

// The clock ticks every second: it moves the sun on the cover, the time
// beside it, and the "now" line in the schedule.
const now = ref(new Date());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => (now.value = new Date()), 1000);
});
onBeforeUnmount(() => clearInterval(clock));

const greeting = computed(() => {
    const hour = now.value.getHours();

    if (hour >= 5 && hour < 12) {
        return { text: 'Good morning', icon: 'wb_sunny' };
    }

    if (hour >= 12 && hour < 17) {
        return { text: 'Good afternoon', icon: 'light_mode' };
    }

    if (hour >= 17 && hour < 21) {
        return { text: 'Good evening', icon: 'wb_twilight' };
    }

    return { text: 'Good night', icon: 'bedtime' };
});

const heroSubtitle = computed(() => {
    const events = props.todayEvents.length;
    const tasks = undoneCount.value;

    return `${events} ${events === 1 ? 'event' : 'events'}, ${tasks} ${tasks === 1 ? 'task' : 'tasks'} to go.`;
});

/** "TUESDAY 06 OCTOBER 2026" beside the sheet code. */
const longDate = computed(() => {
    const date = now.value;

    return `${date.toLocaleDateString('en-US', { weekday: 'long' })} ${String(date.getDate()).padStart(2, '0')} ${date.toLocaleDateString('en-US', { month: 'long' })} ${date.getFullYear()}`;
});

const nowTime = computed(
    () =>
        `${String(now.value.getHours()).padStart(2, '0')}:${String(now.value.getMinutes()).padStart(2, '0')}`,
);

/** The cover's clock, with seconds. */
const nowClock = computed(
    () => `${nowTime.value}:${String(now.value.getSeconds()).padStart(2, '0')}`,
);

/** What sits on the arc: rising sun, full sun, afternoon sun, setting sun, then the moon. */
const skyIcon = computed(() => {
    const hour = now.value.getHours();

    if (hour >= 5 && hour < 8) {
        return { icon: 'wb_twilight', label: 'Sunrise' };
    }

    if (hour >= 8 && hour < 12) {
        return { icon: 'wb_sunny', label: 'Morning sun' };
    }

    if (hour >= 12 && hour < 17) {
        return { icon: 'light_mode', label: 'Afternoon sun' };
    }

    if (hour >= 17 && hour < 20) {
        return { icon: 'wb_twilight', label: 'Sunset' };
    }

    return { icon: 'bedtime', label: 'Night' };
});

/** The ruler runs from 06:00 to 20:00, a tick each hour and a label every third. */
const sunHours = Array.from({ length: 15 }, (_, index) => {
    const hour = 6 + index;

    return {
        hour,
        major: hour % 3 === 0,
        label: hour % 3 === 0 ? String(hour).padStart(2, '0') : '',
    };
});

const arcHeight = 80;

/** Where the sun sits: across the ruler by the time, up the arc to match. */
const sun = computed(() => {
    const hours =
        now.value.getHours() +
        now.value.getMinutes() / 60 +
        now.value.getSeconds() / 3600;
    const across = Math.min(1, Math.max(0, (hours - 6) / 14));
    const centred = 2 * across - 1;

    return {
        left: across * 100,
        rise: arcHeight * Math.sqrt(Math.max(0, 1 - centred * centred)),
    };
});

function shortTime(time: string): string {
    return time.slice(0, 5);
}

/** The day's events with a "now" marker slotted in before the first one still to come. */
const schedule = computed(() => {
    const current = nowTime.value;
    const rows: (
        { type: 'event'; event: TodayEvent } | { type: 'now'; key: string }
    )[] = [];
    let placed = false;

    for (const event of props.todayEvents) {
        if (!placed && shortTime(event.time) > current) {
            rows.push({ type: 'now', key: 'now' });
            placed = true;
        }

        rows.push({ type: 'event', event });
    }

    if (!placed && rows.length > 0) {
        rows.push({ type: 'now', key: 'now' });
    }

    return rows;
});

/** The header's quick add: a shortcut to where each kind of entry is made. */
const quickAdds = [
    { label: 'Add a task', icon: 'check_circle', href: '/tasks' },
    { label: 'Add an event', icon: 'calendar_month', href: '/calendar' },
    {
        label: 'Log spending or income',
        icon: 'receipt_long',
        href: '/money/transactions',
    },
];

const tagIcons: Record<string, string> = {
    Work: 'groups',
    Personal: 'person',
    Health: 'self_improvement',
};

function eventLength(event: TodayEvent): string {
    if (!event.end_time) {
        return 'No end time';
    }

    const [startHour, startMinute] = event.time.split(':').map(Number);
    const [endHour, endMinute] = event.end_time.split(':').map(Number);
    const minutes = endHour * 60 + endMinute - (startHour * 60 + startMinute);

    if (minutes <= 0) {
        return '';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return [hours ? `${hours} h` : '', rest ? `${rest} min` : '']
        .filter(Boolean)
        .join(' ');
}

const daysLeftInMonth = computed(() => {
    const date = now.value;

    return (
        new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate() -
        date.getDate() +
        1
    );
});

// Vision board
const visionModalOpen = ref(false);
const editingVisionItem = ref<VisionBoardItem | null>(null);
const visionImagePreview = ref<string | null>(null);

const visionForm = useForm<{ image: File | null; caption: string }>({
    image: null,
    caption: '',
});

// Tracks whether the current preview URL is a blob we created (and must
// revoke) versus a real server URL (imageUrl) that must be left alone.
let visionImagePreviewIsBlob = false;

function setVisionPreview(url: string | null, isBlob: boolean): void {
    if (visionImagePreviewIsBlob && visionImagePreview.value) {
        URL.revokeObjectURL(visionImagePreview.value);
    }

    visionImagePreview.value = url;
    visionImagePreviewIsBlob = isBlob;
}

const emptyVisionSlots = computed(() =>
    Math.max(0, 9 - props.visionBoardItems.length),
);

// Full-image lightbox
const visionLightboxOpen = ref(false);
const viewingVisionItem = ref<VisionBoardItem | null>(null);

function openVisionLightbox(item: VisionBoardItem): void {
    viewingVisionItem.value = item;
    visionLightboxOpen.value = true;
}

function openAddVisionItem(): void {
    editingVisionItem.value = null;
    visionForm.reset();
    visionForm.clearErrors();
    setVisionPreview(null, false);
    cancelDeleteVisionItem();
    visionModalOpen.value = true;
}

function openEditVisionItem(item: VisionBoardItem): void {
    editingVisionItem.value = item;
    visionForm.reset();
    visionForm.caption = item.caption;
    visionForm.clearErrors();
    setVisionPreview(item.imageUrl, false);
    cancelDeleteVisionItem();
    visionModalOpen.value = true;
}

function onVisionImageChange(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    visionForm.image = file;

    if (file) {
        setVisionPreview(URL.createObjectURL(file), true);
    }
}

function submitVisionForm(): void {
    if (editingVisionItem.value) {
        const itemId = editingVisionItem.value.id;
        visionForm.transform((data) => ({ ...data, _method: 'patch' }));
        visionForm.post(updateVisionBoardItem(itemId).url, {
            preserveScroll: true,
            onSuccess: () => {
                visionModalOpen.value = false;
            },
        });
    } else {
        visionForm.transform((data) => data);
        visionForm.post(storeVisionBoardItem().url, {
            preserveScroll: true,
            onSuccess: () => {
                visionModalOpen.value = false;
                visionForm.reset();
            },
        });
    }
}

// ── Two-step delete ────────────────────────────────────────────────────────────

const visionDeleteArmed = ref(false);
let visionDeleteTimer: ReturnType<typeof setTimeout> | null = null;

function startDeleteVisionItem(): void {
    if (!editingVisionItem.value) {
        return;
    }

    if (visionDeleteArmed.value) {
        if (visionDeleteTimer) {
            clearTimeout(visionDeleteTimer);
        }

        visionDeleteArmed.value = false;
        router.delete(destroyVisionBoardItem(editingVisionItem.value.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                visionModalOpen.value = false;
            },
        });
    } else {
        if (visionDeleteTimer) {
            clearTimeout(visionDeleteTimer);
        }

        visionDeleteArmed.value = true;
        visionDeleteTimer = setTimeout(() => {
            visionDeleteArmed.value = false;
        }, 3000);
    }
}

function cancelDeleteVisionItem(): void {
    if (visionDeleteTimer) {
        clearTimeout(visionDeleteTimer);
    }

    visionDeleteArmed.value = false;
}

// Reset the armed delete state whenever the modal closes or reopens.
watch(visionModalOpen, (open) => {
    if (!open) {
        cancelDeleteVisionItem();
    }
});
</script>

<template>
    <Head title="Today" />

    <Teleport defer to="#page-actions">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="bp flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
                >
                    <span class="ms" style="font-size: 16px" aria-hidden="true"
                        >add</span
                    >
                    <span class="hidden sm:inline">Quick add</span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                class="bp w-56 rounded-none border-[var(--bp-ink)] bg-[var(--bp-card)] p-1"
            >
                <DropdownMenuItem
                    v-for="item in quickAdds"
                    :key="item.href"
                    class="flex cursor-pointer items-center gap-2.5 rounded-[3px] px-2.5 py-2 text-[13.5px] focus:bg-[var(--bp-hover)] focus:text-[var(--bp-ink)]"
                    @select="router.visit(item.href)"
                >
                    <span
                        class="ms text-[var(--bp-muted)]"
                        style="font-size: 17px"
                        aria-hidden="true"
                        >{{ item.icon }}</span
                    >
                    {{ item.label }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            :icon="greeting.icon"
            code="D-01"
            :section="longDate"
            :title="`${greeting.text}, ${user.firstName}`"
            :subtitle="heroSubtitle"
        >
            <template #band>
                <div
                    class="absolute inset-x-0 bottom-14"
                    :class="sheetWidth.wide"
                    aria-hidden="true"
                >
                    <div class="relative" :style="{ height: `${arcHeight}px` }">
                        <div
                            class="absolute inset-0 rounded-[50%_50%_0_0/100%_100%_0_0] border border-b-0 border-dashed border-[var(--bp-accent)] opacity-55"
                        ></div>
                        <div
                            class="absolute inset-x-0 bottom-0 h-px bg-[var(--bp-accent)]"
                        ></div>
                        <div
                            class="absolute inset-x-0 bottom-0 flex items-end justify-between"
                        >
                            <div
                                v-for="tick in sunHours"
                                :key="tick.hour"
                                class="relative w-px bg-[var(--bp-accent)]"
                                :class="tick.major ? 'h-2.5' : 'h-[5px]'"
                            >
                                <span
                                    v-if="tick.label"
                                    class="bp-mono absolute top-full left-1/2 mt-[3px] -translate-x-1/2 text-[9px] whitespace-nowrap text-[var(--bp-accent)]"
                                    >{{ tick.label }}</span
                                >
                            </div>
                        </div>
                        <div
                            class="absolute bottom-0 w-px bg-[var(--bp-accent)] transition-[left,height] duration-1000 ease-linear"
                            :style="{
                                left: `${sun.left}%`,
                                height: `${sun.rise}px`,
                            }"
                        ></div>
                        <div
                            class="absolute flex h-[26px] w-[26px] -translate-x-1/2 translate-y-1/2 items-center justify-center rounded-full bg-[var(--bp-accent)] text-[var(--bp-card)] shadow-[0_0_0_5px_var(--bp-tint)] transition-[left,bottom] duration-1000 ease-linear"
                            :style="{
                                left: `${sun.left}%`,
                                bottom: `${sun.rise}px`,
                            }"
                            :title="skyIcon.label"
                        >
                            <span class="msf" style="font-size: 17px">{{
                                skyIcon.icon
                            }}</span>
                        </div>
                        <span
                            class="bp-mono absolute bg-[var(--bp-paper)] px-1 text-[10.5px] tracking-[0.12em] whitespace-nowrap text-[var(--bp-accent)]"
                            :class="sun.left > 80 ? '-translate-x-full' : ''"
                            :style="{
                                left:
                                    sun.left > 80
                                        ? `calc(${sun.left}% - 20px)`
                                        : `calc(${sun.left}% + 20px)`,
                                bottom: `${sun.rise + 4}px`,
                            }"
                            >NOW
                            <span class="tabular-nums">{{
                                nowClock
                            }}</span></span
                        >
                    </div>
                </div>
            </template>
        </BlueprintPageHead>

        <div
            :class="sheetWidth.wide"
            class="grid grid-cols-1 items-start gap-10 lg:grid-cols-[minmax(0,1fr)_340px]"
        >
            <div class="min-w-0">
                <!-- A. Schedule -->
                <BlueprintSectionHeading letter="A" title="Today's schedule">
                    <Link
                        href="/calendar"
                        class="text-[13px] font-medium underline underline-offset-[3px]"
                        >Calendar →</Link
                    >
                </BlueprintSectionHeading>
                <p
                    v-if="todayEvents.length === 0"
                    class="mt-3.5 border-y border-[var(--bp-hair)] py-6 text-center text-[13.5px] text-[var(--bp-muted)]"
                >
                    Nothing scheduled today.
                </p>
                <div v-else class="mt-3.5 flex flex-col">
                    <template
                        v-for="row in schedule"
                        :key="row.type === 'now' ? row.key : row.event.id"
                    >
                        <div
                            v-if="row.type === 'now'"
                            class="grid h-[22px] grid-cols-[88px_32px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[132px_40px_minmax(0,1fr)]"
                        >
                            <span
                                class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-accent)]"
                                >NOW {{ nowTime }}</span
                            >
                            <div class="flex justify-center" aria-hidden="true">
                                <span
                                    class="h-[9px] w-[9px] rotate-45 bg-[var(--bp-accent)]"
                                ></span>
                            </div>
                            <div
                                class="h-px bg-[var(--bp-accent)]"
                                aria-hidden="true"
                            ></div>
                        </div>
                        <div
                            v-else
                            class="grid min-h-[58px] grid-cols-[88px_32px_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[132px_40px_minmax(0,1fr)_auto]"
                        >
                            <span
                                class="bp-mono text-[12px] text-[var(--bp-ink-2)]"
                                >{{ shortTime(row.event.time)
                                }}<template v-if="row.event.end_time">
                                    –
                                    {{
                                        shortTime(row.event.end_time)
                                    }}</template
                                ></span
                            >
                            <div
                                class="relative flex items-center justify-center self-stretch"
                                aria-hidden="true"
                            >
                                <div
                                    class="absolute inset-y-0 left-1/2 w-px bg-[var(--bp-line)]"
                                ></div>
                                <div
                                    class="relative flex h-8 w-8 items-center justify-center rounded-full border border-[var(--bp-line)] bg-[var(--bp-card)]"
                                >
                                    <span class="ms" style="font-size: 17px">{{
                                        tagIcons[row.event.tag] ?? 'event'
                                    }}</span>
                                </div>
                            </div>
                            <div class="min-w-0 py-2">
                                <p class="truncate text-[14px] font-semibold">
                                    {{ row.event.title }}
                                </p>
                                <p class="text-[12px] text-[var(--bp-muted)]">
                                    {{ eventLength(row.event)
                                    }}<span class="sm:hidden">
                                        · {{ row.event.tag }}</span
                                    >
                                </p>
                            </div>
                            <span
                                class="bp-mono hidden rounded-sm border border-[var(--bp-line)] px-[7px] py-0.5 text-[10.5px] tracking-[0.08em] text-[var(--bp-ink-2)] uppercase sm:inline"
                                >{{ row.event.tag }}</span
                            >
                        </div>
                    </template>
                </div>

                <!-- B. Tasks -->
                <BlueprintSectionHeading letter="B" title="Tasks" class="mt-11">
                    <span
                        v-if="undoneCount > 0"
                        class="bp-mono rounded-sm border border-[var(--bp-line)] px-[7px] py-0.5 text-[10.5px] tracking-[0.08em] text-[var(--bp-muted)]"
                        >{{ undoneCount }} LEFT</span
                    >
                    <Link
                        href="/tasks"
                        class="text-[13px] font-medium underline underline-offset-[3px]"
                        >Tasks →</Link
                    >
                </BlueprintSectionHeading>
                <div class="mt-3 border-t border-[var(--bp-line)]">
                    <label
                        v-for="task in localTasks"
                        :key="task.id"
                        class="grid cursor-pointer grid-cols-[22px_minmax(0,1fr)] items-center gap-2.5 border-b border-[var(--bp-hair)] py-2.5"
                    >
                        <input
                            type="checkbox"
                            class="peer sr-only"
                            :checked="task.done"
                            @change="toggleTask(task)"
                        />
                        <span
                            :class="
                                task.done
                                    ? 'msf text-[var(--bp-accent)]'
                                    : 'ms text-[var(--bp-muted)]'
                            "
                            class="rounded-sm peer-focus-visible:outline-2 peer-focus-visible:outline-[var(--bp-accent)]"
                            style="font-size: 20px"
                            aria-hidden="true"
                            >{{
                                task.done
                                    ? 'check_box'
                                    : 'check_box_outline_blank'
                            }}</span
                        >
                        <span
                            class="text-[14px]"
                            :class="{
                                'text-[var(--bp-faint)] line-through':
                                    task.done,
                            }"
                            >{{ task.label }}</span
                        >
                    </label>
                    <Link
                        href="/tasks"
                        class="flex items-center gap-1.5 py-2.5 text-[13.5px] text-[var(--bp-faint)] hover:text-[var(--bp-ink)]"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >add</span
                        >
                        Add task
                    </Link>
                </div>

                <!-- C. Vision board -->
                <BlueprintSectionHeading
                    letter="C"
                    title="Vision board"
                    class="mt-11"
                >
                    <span
                        class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                        >{{ visionBoardItems.length }} OF 9 PINNED</span
                    >
                </BlueprintSectionHeading>
                <div class="mt-4 grid grid-cols-2 gap-3.5 sm:grid-cols-3">
                    <div
                        v-for="(item, index) in visionBoardItems"
                        :key="item.id"
                        class="flex flex-col gap-1.5"
                    >
                        <div
                            class="group relative aspect-[4/3] border border-[var(--bp-line)] bg-[var(--bp-card)] p-1.5"
                        >
                            <button
                                type="button"
                                class="block h-full w-full overflow-hidden"
                                :aria-label="`Open ${item.caption}`"
                                @click="openVisionLightbox(item)"
                            >
                                <img
                                    :src="item.imageUrl"
                                    :alt="item.caption"
                                    class="h-full w-full object-cover"
                                />
                            </button>
                            <span
                                class="pointer-events-none absolute -top-px -left-px h-2.5 w-2.5 border-t-2 border-l-2 border-[var(--bp-ink)]"
                                aria-hidden="true"
                            ></span>
                            <span
                                class="pointer-events-none absolute -right-px -bottom-px h-2.5 w-2.5 border-r-2 border-b-2 border-[var(--bp-ink)]"
                                aria-hidden="true"
                            ></span>
                            <button
                                type="button"
                                class="absolute top-3 right-3 flex h-7 w-7 items-center justify-center rounded-[3px] border border-[var(--bp-ink)] bg-[var(--bp-card)] opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                :aria-label="`Edit ${item.caption}`"
                                @click="openEditVisionItem(item)"
                            >
                                <span
                                    class="ms"
                                    style="font-size: 15px"
                                    aria-hidden="true"
                                    >edit</span
                                >
                            </button>
                        </div>
                        <div class="flex justify-between gap-2 text-[13px]">
                            <span class="truncate font-medium">{{
                                item.caption
                            }}</span>
                            <span
                                class="bp-mono shrink-0 text-[10.5px] text-[var(--bp-faint)]"
                                >{{ String(index + 1).padStart(2, '0') }}</span
                            >
                        </div>
                    </div>
                    <button
                        v-for="n in emptyVisionSlots"
                        :key="'empty-' + n"
                        type="button"
                        class="flex aspect-[4/3] items-center justify-center border border-dashed border-[var(--bp-fainter)] text-[var(--bp-faint)] hover:border-[var(--bp-ink)] hover:text-[var(--bp-ink)]"
                        aria-label="Add to vision board"
                        @click="openAddVisionItem"
                    >
                        <span
                            class="ms"
                            style="font-size: 24px"
                            aria-hidden="true"
                            >add</span
                        >
                    </button>
                </div>
            </div>

            <!-- Right column -->
            <div class="flex flex-col gap-7 lg:sticky lg:top-[76px]">
                <Link
                    href="/money"
                    class="block border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                >
                    <div
                        class="bp-mono flex items-center justify-between border-b border-[var(--bp-ink)] px-4 py-2.5 text-[10.5px] tracking-[0.12em]"
                    >
                        <span>MONEY</span>
                        <span
                            class="flex items-center gap-1 text-[var(--bp-faint)]"
                            >M-01<span
                                class="ms"
                                style="font-size: 15px"
                                aria-hidden="true"
                                >chevron_right</span
                            ></span
                        >
                    </div>
                    <div class="p-4">
                        <template v-if="moneySummary">
                            <p class="text-[12px] text-[var(--bp-muted)]">
                                Safe to spend this month
                            </p>
                            <p
                                class="mt-0.5 text-[30px] font-semibold tracking-[-0.03em] tabular-nums"
                            >
                                {{ formatPeso(moneySummary.safeToSpend) }}
                            </p>
                            <p
                                class="bp-mono mt-0.5 text-[12px] text-[var(--bp-accent)]"
                            >
                                {{ formatPeso(moneySummary.perDay) }} / day ·
                                {{ daysLeftInMonth }}
                                {{ daysLeftInMonth === 1 ? 'day' : 'days' }}
                                left
                            </p>
                            <div
                                class="mt-3.5 flex flex-col gap-1.5 border-t border-[var(--bp-hair)] pt-3"
                            >
                                <div
                                    v-for="due in moneySummary.dueSoon"
                                    :key="`${due.date}-${due.name}`"
                                    class="grid grid-cols-[52px_minmax(0,1fr)_auto] gap-2 text-[12.5px]"
                                >
                                    <span
                                        class="bp-mono text-[var(--bp-muted)]"
                                        >{{
                                            formatDay(due.date).toUpperCase()
                                        }}</span
                                    >
                                    <span class="truncate">{{ due.name }}</span>
                                    <span class="bp-mono">{{
                                        formatPeso(due.amount)
                                    }}</span>
                                </div>
                                <span
                                    class="text-[12px] text-[var(--bp-faint)]"
                                >
                                    <template
                                        v-if="moneySummary.dueSoonCount === 0"
                                        >Nothing due in the next 14
                                        days</template
                                    >
                                    <template v-else
                                        >{{ moneySummary.dueSoonCount }}
                                        {{
                                            moneySummary.dueSoonCount === 1
                                                ? 'payment'
                                                : 'payments'
                                        }}
                                        due in the next 14 days</template
                                    >
                                </span>
                            </div>
                        </template>
                        <p v-else class="text-[13px] text-[var(--bp-muted)]">
                            Add your income and bills to see what is safe to
                            spend.
                        </p>
                    </div>
                </Link>

                <section v-if="habits.length">
                    <div
                        class="flex items-center justify-between border-b border-[var(--bp-ink)] pb-2"
                    >
                        <h2 class="text-[15px] font-semibold">Habits</h2>
                        <span
                            class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                            >LAST 7 DAYS</span
                        >
                    </div>
                    <div
                        v-for="habit in habits.slice(0, 3)"
                        :key="habit.id"
                        class="grid grid-cols-[40px_minmax(0,1fr)_auto_auto] items-center gap-2.5 border-b border-[var(--bp-hair)] py-2.5"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full"
                            :style="{
                                background: `conic-gradient(var(--bp-accent) ${habit.pct}%, var(--bp-hair) ${habit.pct}%)`,
                            }"
                            aria-hidden="true"
                        >
                            <div
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-[var(--bp-card)]"
                            >
                                <span class="ms" style="font-size: 15px">{{
                                    habit.icon
                                }}</span>
                            </div>
                        </div>
                        <span class="truncate text-[13.5px] font-medium">{{
                            habit.name
                        }}</span>
                        <span
                            class="bp-mono rounded-sm border border-[var(--bp-line)] px-1.5 py-px text-[10.5px] text-[var(--bp-muted)]"
                            :class="{ invisible: habit.streak === 0 }"
                            :title="`${habit.streak}-day streak`"
                            >{{ habit.streak }}d</span
                        >
                        <span
                            class="bp-mono min-w-[38px] text-right text-[12.5px] font-semibold text-[var(--bp-accent)]"
                            >{{ habit.pct }}%</span
                        >
                    </div>
                </section>

                <section v-if="healthMetrics.length">
                    <div
                        class="flex items-center justify-between border-b border-[var(--bp-ink)] pb-2"
                    >
                        <h2 class="text-[15px] font-semibold">Health</h2>
                        <span
                            class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                            aria-hidden="true"
                            >FIG. 1</span
                        >
                    </div>
                    <div
                        v-for="metric in healthMetrics"
                        :key="metric.name"
                        class="border-b border-[var(--bp-hair)] py-2.5"
                    >
                        <div class="flex items-baseline gap-2">
                            <span
                                class="ms self-center text-[var(--bp-muted)]"
                                style="font-size: 16px"
                                aria-hidden="true"
                                >{{ metric.icon }}</span
                            >
                            <span class="text-[13px] text-[var(--bp-ink-2)]">{{
                                metric.name
                            }}</span>
                            <span
                                class="bp-mono ml-auto text-[14px] font-semibold"
                                >{{ metric.value }}</span
                            >
                            <span
                                class="text-[11.5px] text-[var(--bp-faint)]"
                                >{{ metric.sub }}</span
                            >
                        </div>
                        <div
                            class="mt-1.5 h-1.5 border border-[var(--bp-line)] bg-[var(--bp-paper)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
                            role="progressbar"
                            :aria-valuenow="metric.pct"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="metric.name"
                        >
                            <div
                                class="hab-progress-fill h-full bg-[var(--bp-accent)]"
                                :style="{
                                    width: Math.min(metric.pct, 100) + '%',
                                }"
                            ></div>
                        </div>
                    </div>
                </section>

                <section>
                    <div
                        class="flex items-center justify-between border-b border-[var(--bp-ink)] pb-2"
                    >
                        <h2 class="text-[15px] font-semibold">On this day</h2>
                    </div>
                    <div
                        class="mt-3 flex aspect-video items-center justify-center border border-[var(--bp-line)] bg-[var(--bp-card)] p-1.5"
                    >
                        <span
                            class="ms text-[var(--bp-fainter)]"
                            style="font-size: 36px"
                            aria-hidden="true"
                            >photo_camera</span
                        >
                    </div>
                    <p class="mt-1.5 text-[12px] text-[var(--bp-muted)]">
                        3 years ago · Batangas trip
                    </p>
                </section>
            </div>
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock drawing="Day / Today" sheet="D-01" />
        </div>

        <!-- Vision board modal -->
        <Dialog v-model:open="visionModalOpen">
            <DialogContent style="max-width: 420px">
                <DialogHeader>
                    <p class="bp-caption">Vision board · D-01</p>
                    <DialogTitle>
                        {{
                            editingVisionItem
                                ? 'Edit vision board item'
                                : 'Add to vision board'
                        }}
                    </DialogTitle>
                </DialogHeader>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitVisionForm"
                >
                    <div>
                        <label for="vision-image-input" :class="formLabel"
                            >Image</label
                        >
                        <div
                            v-if="visionImagePreview"
                            class="relative mb-2 aspect-[4/3] w-full border border-[var(--bp-line)] bg-[var(--bp-paper)] p-1.5"
                        >
                            <img
                                :src="visionImagePreview"
                                alt="Preview"
                                class="h-full w-full object-cover"
                            />
                            <span
                                class="pointer-events-none absolute -top-px -left-px h-2.5 w-2.5 border-t-2 border-l-2 border-[var(--bp-ink)]"
                                aria-hidden="true"
                            ></span>
                            <span
                                class="pointer-events-none absolute -right-px -bottom-px h-2.5 w-2.5 border-r-2 border-b-2 border-[var(--bp-ink)]"
                                aria-hidden="true"
                            ></span>
                        </div>
                        <input
                            id="vision-image-input"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-[13px] text-[var(--bp-muted)] file:mr-3 file:rounded-[3px] file:border file:border-[var(--bp-line)] file:bg-[var(--bp-card)] file:px-3 file:py-1.5 file:text-[13px] file:font-medium file:text-[var(--bp-ink)] hover:file:border-[var(--bp-ink)]"
                            @change="onVisionImageChange"
                        />
                        <p v-if="visionForm.errors.image" :class="formError">
                            {{ visionForm.errors.image }}
                        </p>
                    </div>

                    <div>
                        <label for="vision-caption-input" :class="formLabel"
                            >Caption</label
                        >
                        <input
                            id="vision-caption-input"
                            v-model="visionForm.caption"
                            type="text"
                            maxlength="80"
                            placeholder="e.g. Japan trip 2027"
                            :class="formField"
                        />
                        <p v-if="visionForm.errors.caption" :class="formError">
                            {{ visionForm.errors.caption }}
                        </p>
                    </div>

                    <div
                        class="-mx-6 mt-1 -mb-6 flex items-center gap-2 border-t border-[var(--bp-hair)] px-6 py-4"
                    >
                        <Button
                            v-if="editingVisionItem"
                            type="button"
                            :variant="
                                visionDeleteArmed ? 'destructive' : 'ghost'
                            "
                            :class="{ 'text-[#a3311f]': !visionDeleteArmed }"
                            @click="startDeleteVisionItem"
                        >
                            {{
                                visionDeleteArmed ? 'Confirm delete?' : 'Delete'
                            }}
                        </Button>
                        <div class="ml-auto flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                @click="visionModalOpen = false"
                                >Cancel</Button
                            >
                            <Button
                                type="submit"
                                :disabled="visionForm.processing"
                                >Save</Button
                            >
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Vision board lightbox -->
        <Dialog v-model:open="visionLightboxOpen">
            <DialogContent style="max-width: 720px">
                <DialogHeader>
                    <DialogTitle>
                        {{ viewingVisionItem?.caption }}
                    </DialogTitle>
                </DialogHeader>
                <div
                    v-if="viewingVisionItem"
                    class="border border-[var(--bp-line)] bg-[var(--bp-paper)] p-1.5"
                >
                    <img
                        :src="viewingVisionItem.imageUrl"
                        :alt="viewingVisionItem.caption"
                        class="w-full"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
