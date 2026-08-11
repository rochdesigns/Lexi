<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    store as storeVisionBoardItem,
    update as updateVisionBoardItem,
    destroy as destroyVisionBoardItem,
} from '@/actions/App/Http/Controllers/VisionBoardItemController';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { HabitatTheme } from '@/types';

type TodayEvent = { id: number; title: string; time: string; end_time: string | null; tag: string; color: string };
type TodayTask = { id: number; label: string; section_id: number; done: boolean };
type MoneySummary = { safeToSpend: number; budget: number; spent: number };
type Habit = { id: number; name: string; icon: string; color: string; pct: number; streak: number };
type HealthMetric = { name: string; value: string; sub: string; pct: number; color: string; icon: string };
type VisionBoardItem = { id: number; imageUrl: string; caption: string };

const props = defineProps<{
    user: { name: string; firstName: string; theme: HabitatTheme };
    todayEvents: TodayEvent[];
    todayTasks: TodayTask[];
    moneySummary: MoneySummary;
    habits: Habit[];
    healthMetrics: HealthMetric[];
    visionBoardItems: VisionBoardItem[];
}>();

// Optimistic task toggle
const localTasks = ref(props.todayTasks.map((t) => ({ ...t })));

const undoneCount = computed(() => localTasks.value.filter((t) => !t.done).length);

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

// Dynamic greeting
const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour >= 5 && hour < 12) return {
        text: 'Good morning', icon: 'wb_sunny',
        gradA: '#d4875a', gradB: '#b5663e', gradC: '#8a4621',
        overlay: 'overlay-morning',
    };
    if (hour >= 12 && hour < 17) return {
        text: 'Good afternoon', icon: 'light_mode',
        gradA: '#2f8faa', gradB: '#256f88', gradC: '#1a5268',
        overlay: 'overlay-afternoon',
    };
    if (hour >= 17 && hour < 21) return {
        text: 'Good evening', icon: 'wb_twilight',
        gradA: '#9b5490', gradB: '#7a3c72', gradC: '#592a52',
        overlay: 'overlay-evening',
    };
    return {
        text: 'Good night', icon: 'bedtime',
        gradA: '#263670', gradB: '#1c285c', gradC: '#131d44',
        overlay: 'overlay-night',
    };
});

// Hero subtitle
const heroSubtitle = computed(() => {
    const events = props.todayEvents.length;
    const tasks = undoneCount.value;
    const eventsText = `${events} ${events === 1 ? 'event' : 'events'}`;
    const tasksText = `${tasks} ${tasks === 1 ? 'task' : 'tasks'} to go`;
    const budgetPct = props.moneySummary.budget > 0
        ? (props.moneySummary.spent / props.moneySummary.budget) * 100
        : 0;
    const budgetMood = budgetPct < 60
        ? "you're comfortably under budget"
        : budgetPct < 85
          ? "you're on track"
          : budgetPct < 100
            ? "you're getting close on budget"
            : "you're over budget";
    const day = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    return `${day} — ${eventsText}, ${tasksText}, and ${budgetMood}.`;
});

// Money
const spentPct = computed(() =>
    props.moneySummary.budget > 0
        ? Math.min(100, (props.moneySummary.spent / props.moneySummary.budget) * 100)
        : 0,
);

function formatPeso(amount: number): string {
    return '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Tag pill color helper
const tagColors: Record<string, { bg: string; text: string }> = {
    Work: { bg: '#efe0f5', text: '#a85fc0' },
    Personal: { bg: '#fbe2d6', text: '#c0512c' },
    Health: { bg: '#dff3e6', text: '#2f7d5b' },
};
function tagStyle(tag: string): { bg: string; text: string } {
    return tagColors[tag] ?? { bg: '#f1ede3', text: '#8a8172' };
}

function formatTime12h(time: string): string {
    const [h, m] = time.split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const hour = h % 12 || 12;
    return `${hour}:${String(m).padStart(2, '0')} ${period}`;
}

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

const emptyVisionSlots = computed(() => Math.max(0, 6 - props.visionBoardItems.length));

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

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Hero -->
        <div
            class="relative mb-5 w-full overflow-hidden rounded-[22px] px-5 py-5 sm:px-8 sm:py-7"
            :style="{
                background: `radial-gradient(120% 140% at 100% 0%, ${greeting.gradA} 0%, ${greeting.gradB} 55%, ${greeting.gradC} 100%)`,
                boxShadow: '0 24px 40px -26px rgba(20,20,20,.7)',
            }"
        >
            <div class="pointer-events-none absolute inset-0" :class="greeting.overlay" />
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-white/90" style="font-size: 32px">{{ greeting.icon }}</span>
                <h1 class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold text-white">
                    {{ greeting.text }}, {{ user.firstName }}
                </h1>
            </div>
            <p class="mt-2 text-[14px] text-white/72 pl-0.5">{{ heroSubtitle }}</p>
        </div>

        <!-- Two-column layout -->
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_340px]">

            <!-- ── Left column ── -->
            <div class="flex flex-col gap-5">

                <!-- Today's schedule -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                            Today's schedule
                        </h2>
                        <Link href="/calendar" class="text-[13px] font-medium" :style="{ color: 'var(--hab-accent)' }">
                            Calendar →
                        </Link>
                    </div>

                    <div v-if="todayEvents.length === 0" class="py-4 text-center text-[13px]" style="color: var(--hab-muted)">
                        Nothing scheduled today
                    </div>

                    <div v-for="(event, i) in todayEvents" :key="event.id">
                        <div
                            v-if="i > 0"
                            class="my-3 h-px"
                            style="background: var(--hab-hairline)"
                        ></div>
                        <div class="flex items-center gap-3">
                            <span class="w-28 shrink-0 text-[12px] tabular-nums" style="color: var(--hab-muted)">
                                {{ formatTime12h(event.time) }}{{ event.end_time ? ' – ' + formatTime12h(event.end_time) : '' }}
                            </span>
                            <span
                                class="h-2 w-2 shrink-0 rounded-full"
                                :style="{ background: event.color }"
                            ></span>
                            <span class="flex-1 text-[14px] font-medium" style="color: var(--hab-ink)">
                                {{ event.title }}
                            </span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                :style="{ background: tagStyle(event.tag).bg, color: tagStyle(event.tag).text }"
                            >
                                {{ event.tag }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tasks -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                            Tasks
                        </h2>
                        <div class="flex items-center gap-3">
                            <span
                                v-if="undoneCount > 0"
                                class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                            >{{ undoneCount }} left</span>
                            <Link href="/tasks" class="text-[13px] font-medium" :style="{ color: 'var(--hab-accent)' }">
                                Tasks →
                            </Link>
                        </div>
                    </div>

                    <div v-for="(task, i) in localTasks" :key="task.id">
                        <div
                            v-if="i > 0"
                            class="my-2 h-px"
                            style="background: var(--hab-hairline)"
                        ></div>
                        <label class="flex cursor-pointer items-center gap-3 py-1">
                            <!-- Custom checkbox -->
                            <button
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-[6px] border-2 transition-colors"
                                :style="task.done
                                    ? { background: 'var(--hab-accent)', borderColor: 'var(--hab-accent)' }
                                    : { background: 'transparent', borderColor: '#c3bbab' }"
                                @click="toggleTask(task)"
                                type="button"
                            >
                                <span
                                    v-if="task.done"
                                    class="material-symbols-rounded filled text-white"
                                    style="font-size: 13px"
                                >check</span>
                            </button>
                            <span
                                class="text-[14px]"
                                :style="task.done
                                    ? { textDecoration: 'line-through', color: 'var(--hab-faint)' }
                                    : { color: 'var(--hab-ink)' }"
                            >{{ task.label }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── Right column ── -->
            <div class="flex flex-col gap-5">

                <!-- Money snapshot -->
                <Link
                    href="/money"
                    class="block rounded-[22px] p-5 transition-opacity hover:opacity-90"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">Money</h2>
                        <span class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)">chevron_right</span>
                    </div>
                    <p class="mb-1 text-[12px]" style="color: var(--hab-muted)">Safe to spend this month</p>
                    <p class="mb-3 font-['Bricolage_Grotesque'] text-[28px] font-extrabold" style="color: var(--hab-ink)">
                        {{ formatPeso(moneySummary.safeToSpend) }}
                    </p>
                    <!-- Progress bar -->
                    <div class="h-[9px] w-full rounded-full" style="background: var(--hab-progress-track)">
                        <div
                            class="hab-progress-fill h-full rounded-full"
                            :style="{ width: spentPct + '%', background: 'var(--hab-spend-fill)' }"
                        ></div>
                    </div>
                </Link>

                <!-- Habits -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">Habits</h2>

                    <div v-for="(habit, i) in habits.slice(0, 3)" :key="habit.id">
                        <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>
                        <div class="flex items-center gap-3">
                            <!-- Conic-gradient ring -->
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                                :style="{
                                    background: `conic-gradient(${habit.color} ${habit.pct}%, var(--hab-progress-track) ${habit.pct}%)`,
                                }"
                            >
                                <div
                                    class="flex h-7 w-7 items-center justify-center rounded-full"
                                    style="background: var(--hab-card-bg)"
                                >
                                    <span class="material-symbols-rounded" :style="{ fontSize: '14px', color: habit.color }">
                                        {{ habit.icon }}
                                    </span>
                                </div>
                            </div>
                            <span class="flex-1 text-[13px] font-medium" style="color: var(--hab-ink)">{{ habit.name }}</span>
                            <span
                                v-if="habit.streak > 0"
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                            >{{ habit.streak }}d</span>
                            <span class="text-[13px] font-semibold" :style="{ color: habit.color }">
                                {{ habit.pct }}%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Health -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">Health</h2>

                    <div v-for="(metric, i) in healthMetrics" :key="metric.name">
                        <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>
                        <div class="flex items-center gap-3">
                            <!-- Icon tile -->
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px]"
                                :style="{ background: metric.color + '22' }"
                            >
                                <span class="material-symbols-rounded" :style="{ fontSize: '18px', color: metric.color }">
                                    {{ metric.icon }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-1.5">
                                    <span class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                                        {{ metric.value }}
                                    </span>
                                    <span class="text-[12px]" style="color: var(--hab-muted)">{{ metric.sub }}</span>
                                </div>
                                <div class="mt-1.5 h-[9px] w-full rounded-full" style="background: var(--hab-progress-track)">
                                    <div
                                        class="hab-progress-fill h-full rounded-full"
                                        :style="{ width: metric.pct + '%', background: metric.color }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- On this day -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
                >
                    <h2 class="mb-3 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">On this day</h2>
                    <!-- Photo placeholder -->
                    <div
                        class="flex w-full items-center justify-center rounded-[16px]"
                        style="aspect-ratio: 16/9; background: #e8e3d8"
                    >
                        <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">photo_camera</span>
                    </div>
                    <p class="mt-2 text-[12px]" style="color: var(--hab-muted)">3 years ago · Batangas trip</p>
                </div>

            </div>
        </div>

        <!-- Vision board -->
        <div
            class="mt-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                    Vision board
                </h2>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div
                    v-for="item in visionBoardItems"
                    :key="item.id"
                    class="group relative aspect-square overflow-hidden rounded-[14px]"
                >
                    <img :src="item.imageUrl" :alt="item.caption" class="h-full w-full object-cover" />
                    <div
                        class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
                        style="background: linear-gradient(transparent, rgba(0,0,0,.7))"
                    ></div>
                    <span class="absolute inset-x-2 bottom-2 truncate text-[12px] font-medium text-white">
                        {{ item.caption }}
                    </span>
                    <button
                        type="button"
                        class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-full opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100"
                        style="background: rgba(0,0,0,.55)"
                        @click="openEditVisionItem(item)"
                    >
                        <span class="material-symbols-rounded text-white" style="font-size: 15px">edit</span>
                    </button>
                </div>

                <button
                    v-for="n in emptyVisionSlots"
                    :key="'empty-' + n"
                    type="button"
                    class="flex aspect-square items-center justify-center rounded-[14px] border-2 border-dashed transition-colors hover:opacity-80"
                    style="border-color: var(--hab-hairline)"
                    @click="openAddVisionItem"
                >
                    <span class="material-symbols-rounded" style="font-size: 26px; color: var(--hab-faint)">add</span>
                </button>
            </div>
        </div>

        <!-- Vision board modal -->
        <Dialog v-model:open="visionModalOpen">
            <DialogContent style="max-width: 400px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        {{ editingVisionItem ? 'Edit vision board item' : 'Add to vision board' }}
                    </DialogTitle>
                </DialogHeader>

                <form class="flex flex-col gap-4 pt-2" @submit.prevent="submitVisionForm">
                    <div>
                        <label for="vision-image-input" class="mb-1.5 block text-[13px] font-medium" style="color: var(--hab-ink)">Image</label>
                        <div
                            v-if="visionImagePreview"
                            class="mb-2 flex aspect-video w-full items-center justify-center overflow-hidden rounded-[12px]"
                            style="background: var(--hab-progress-track)"
                        >
                            <img :src="visionImagePreview" alt="Preview" class="h-full w-full object-cover" />
                        </div>
                        <input
                            id="vision-image-input"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="text-[13px]"
                            @change="onVisionImageChange"
                        />
                        <p v-if="visionForm.errors.image" class="mt-1 text-[12px]" style="color: #c0512c">
                            {{ visionForm.errors.image }}
                        </p>
                    </div>

                    <div>
                        <label for="vision-caption-input" class="mb-1.5 block text-[13px] font-medium" style="color: var(--hab-ink)">Caption</label>
                        <input
                            id="vision-caption-input"
                            v-model="visionForm.caption"
                            type="text"
                            maxlength="80"
                            placeholder="e.g. Japan trip 2027"
                            class="w-full rounded-[10px] border px-3 py-2 text-[14px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)"
                        />
                        <p v-if="visionForm.errors.caption" class="mt-1 text-[12px]" style="color: #c0512c">
                            {{ visionForm.errors.caption }}
                        </p>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <button
                            v-if="editingVisionItem"
                            type="button"
                            class="rounded-full text-[13px] font-medium transition-colors"
                            :style="visionDeleteArmed
                                ? 'color: #c0512c; background: #fbe2d6; padding: 4px 10px'
                                : 'color: #c0512c'"
                            @click="startDeleteVisionItem"
                        >
                            {{ visionDeleteArmed ? 'Confirm delete?' : 'Delete' }}
                        </button>
                        <div class="ml-auto flex gap-2">
                            <button
                                type="button"
                                class="rounded-full px-4 py-2 text-[13px] font-medium"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                                @click="visionModalOpen = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="rounded-full px-4 py-2 text-[13px] font-semibold text-white"
                                style="background: var(--hab-accent)"
                                :disabled="visionForm.processing"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
/* ── Morning: warm sun glow pulses from top-right ── */
.overlay-morning {
    background: radial-gradient(circle at 88% 12%, rgba(255, 220, 100, 0.38) 0%, transparent 58%);
    animation: morning-glow 7s ease-in-out infinite;
}
@keyframes morning-glow {
    0%, 100% { opacity: 0.65; transform: scale(1); }
    50%       { opacity: 1;    transform: scale(1.12); }
}

/* ── Afternoon: crisp shimmer sweeps left→right ── */
.overlay-afternoon {
    background: linear-gradient(108deg, transparent 30%, rgba(255, 255, 255, 0.13) 50%, transparent 70%);
    animation: afternoon-shimmer 9s ease-in-out infinite;
}
@keyframes afternoon-shimmer {
    0%, 100% { transform: translateX(-50%); opacity: 0.4; }
    50%       { transform: translateX(50%);  opacity: 1; }
}

/* ── Evening: warm ember glow breathes from bottom-left ── */
.overlay-evening {
    background: radial-gradient(circle at 14% 88%, rgba(255, 145, 80, 0.38) 0%, transparent 55%);
    animation: evening-glow 8s ease-in-out infinite;
}
@keyframes evening-glow {
    0%, 100% { opacity: 0.55; transform: scale(1); }
    50%       { opacity: 1;    transform: scale(1.18); }
}

/* ── Night: cool moonlight drifts slowly ── */
.overlay-night {
    background: radial-gradient(circle at 68% 28%, rgba(140, 165, 255, 0.22) 0%, transparent 52%);
    animation: night-drift 14s ease-in-out infinite;
}
@keyframes night-drift {
    0%, 100% { opacity: 0.55; transform: translate(0,    0)    scale(1); }
    40%       { opacity: 1;    transform: translate(4%, -3%)   scale(1.08); }
    70%       { opacity: 0.75; transform: translate(-3%, 4%)   scale(0.96); }
}
</style>
