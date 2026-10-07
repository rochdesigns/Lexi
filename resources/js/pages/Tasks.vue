<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { sheetWidth } from '@/utils/blueprint';

type Task = { id: number; label: string; section_id: number; done: boolean };
type Section = {
    id: number;
    label: string;
    position: number;
    color: string | null;
    tasks: Task[];
};

const props = defineProps<{ groups: Section[] }>();

const localGroups = ref<Section[]>(
    props.groups.map((g) => ({
        ...g,
        tasks: g.tasks.map((t) => ({ ...t })),
    })),
);

watch(
    () => props.groups,
    (newGroups) => {
        localGroups.value = newGroups.map((g) => ({
            ...g,
            tasks: g.tasks.map((t) => ({ ...t })),
        }));
    },
    { deep: true },
);

// ── Progress ─────────────────────────────────────────────────────────────────

const totalTasks = computed(() =>
    localGroups.value.reduce((sum, g) => sum + g.tasks.length, 0),
);
const doneTasks = computed(() =>
    localGroups.value.reduce(
        (sum, g) => sum + g.tasks.filter((t) => t.done).length,
        0,
    ),
);
const progressPct = computed(() =>
    totalTasks.value > 0
        ? Math.round((doneTasks.value / totalTasks.value) * 100)
        : 0,
);

// ── Task toggle ──────────────────────────────────────────────────────────────

function toggleTask(task: Task): void {
    const prev = task.done;
    task.done = !prev;
    router.patch(
        `/tasks/${task.id}`,
        { done: task.done },
        {
            preserveScroll: true,
            onError: () => {
                task.done = prev;
            },
        },
    );
}

function deleteTask(section: Section, task: Task): void {
    section.tasks = section.tasks.filter((t) => t.id !== task.id);
    router.delete(`/tasks/${task.id}`, {
        preserveScroll: true,
        onError: () => {
            section.tasks.push(task);
        },
    });
}

// ── Inline add task ──────────────────────────────────────────────────────────

const addingInSection = ref<number | null>(null);
const newTaskLabel = ref('');
const newTaskInputRef = ref<HTMLInputElement | null>(null);

function startAddTask(sectionId: number): void {
    addingInSection.value = sectionId;
    newTaskLabel.value = '';
    nextTick(() => newTaskInputRef.value?.focus());
}

function submitNewTask(section: Section): void {
    const label = newTaskLabel.value.trim();

    if (!label) {
        cancelAddTask();

        return;
    }

    const optimisticId = -Date.now();
    const optimisticTask: Task = {
        id: optimisticId,
        label,
        section_id: section.id,
        done: false,
    };
    section.tasks.push(optimisticTask);
    newTaskLabel.value = '';
    nextTick(() => newTaskInputRef.value?.focus());

    router.post(
        '/tasks',
        { label, section_id: section.id },
        {
            preserveScroll: true,
            onError: () => {
                section.tasks = section.tasks.filter(
                    (t) => t.id !== optimisticId,
                );
            },
        },
    );
}

function cancelAddTask(): void {
    addingInSection.value = null;
    newTaskLabel.value = '';
}

// ── Inline rename section ────────────────────────────────────────────────────

const editingSection = ref<number | null>(null);
const editLabel = ref('');
const editInputRef = ref<HTMLInputElement | null>(null);

const justOpenedRename = ref(false);

function startRename(section: Section): void {
    editingSection.value = section.id;
    editLabel.value = section.label;
    justOpenedRename.value = true;
    setTimeout(() => {
        justOpenedRename.value = false;
    }, 0);
    nextTick(() => editInputRef.value?.focus());
}

function submitRename(section: Section): void {
    const label = editLabel.value.trim();

    if (!label) {
        cancelRename(section);

        return;
    }

    editingSection.value = null;
    const prev = section.label;
    section.label = label;

    router.patch(
        `/task-sections/${section.id}`,
        { label },
        {
            preserveScroll: true,
            onError: () => {
                section.label = prev;
            },
        },
    );
}

function cancelRename(section: Section): void {
    section.label = editLabel.value.trim() ? section.label : section.label;
    editingSection.value = null;
}

// ── Section color ────────────────────────────────────────────────────────────

function handleWindowClick(): void {
    colorPickerOpen.value = null;

    if (justOpenedRename.value || editingSection.value === null) {
        return;
    }

    const section = localGroups.value.find(
        (g) => g.id === editingSection.value,
    );

    if (section) {
        submitRename(section);
    }
}
onMounted(() => window.addEventListener('click', handleWindowClick));
onUnmounted(() => window.removeEventListener('click', handleWindowClick));

const PALETTE = [
    '#fce4e4',
    '#fde8d0',
    '#fdf6d0',
    '#d4f0e0',
    '#d0e8fd',
    '#e8d4f0',
    '#fad4e8',
    '#e8e8e8',
];

const colorPickerOpen = ref<number | null>(null);

function toggleColorPicker(sectionId: number): void {
    colorPickerOpen.value =
        colorPickerOpen.value === sectionId ? null : sectionId;
}

function pickColor(section: Section, hex: string | null): void {
    colorPickerOpen.value = null;
    const prev = section.color;
    section.color = hex;
    router.patch(
        `/task-sections/${section.id}`,
        { color: hex },
        {
            preserveScroll: true,
            onError: () => {
                section.color = prev;
            },
        },
    );
}

// ── Delete section ───────────────────────────────────────────────────────────

const deleteTarget = ref<Section | null>(null);

function confirmDelete(section: Section): void {
    deleteTarget.value = section;
}

function executeDelete(): void {
    const section = deleteTarget.value;

    if (!section) {
        return;
    }

    deleteTarget.value = null;
    localGroups.value = localGroups.value.filter((g) => g.id !== section.id);

    router.delete(`/task-sections/${section.id}`, {
        preserveScroll: true,
        onError: () => {
            localGroups.value.push(section);
            localGroups.value.sort((a, b) => a.position - b.position);
        },
    });
}

// ── Drag to reorder ──────────────────────────────────────────────────────────

const draggedId = ref<number | null>(null);

function onDragStart(section: Section): void {
    draggedId.value = section.id;
}

function onDragOver(e: DragEvent, target: Section): void {
    e.preventDefault();

    if (draggedId.value === null || draggedId.value === target.id) {
        return;
    }

    const fromIdx = localGroups.value.findIndex(
        (g) => g.id === draggedId.value,
    );
    const toIdx = localGroups.value.findIndex((g) => g.id === target.id);

    if (fromIdx === -1 || toIdx === -1) {
        return;
    }

    const reordered = [...localGroups.value];
    const [moved] = reordered.splice(fromIdx, 1);
    reordered.splice(toIdx, 0, moved);
    localGroups.value = reordered;
}

function onDrop(): void {
    if (draggedId.value === null) {
        return;
    }

    draggedId.value = null;

    const sections = localGroups.value.map((g, i) => ({
        id: g.id,
        position: i + 1,
    }));

    router.post(
        '/task-sections/reorder',
        { sections },
        { preserveScroll: true },
    );
}

// ── View mode ────────────────────────────────────────────────────────────────

type ViewMode = 'list' | 'grid';
/** Browser storage can be unavailable, so the view simply falls back to a list. */
function storedView(): ViewMode {
    try {
        return localStorage.getItem('tasks-view') === 'grid' ? 'grid' : 'list';
    } catch {
        return 'list';
    }
}

const viewMode = ref<ViewMode>(storedView());

function setView(mode: ViewMode): void {
    viewMode.value = mode;

    try {
        localStorage.setItem('tasks-view', mode);
    } catch {
        // Nothing to do: the choice lasts until the page is left.
    }
}

const viewOptions = [
    { mode: 'list', label: 'List', icon: 'view_agenda' },
    { mode: 'grid', label: 'Grid', icon: 'grid_view' },
] as const;

/**
 * Sections keep their pastel colours; on the sheet they become a thin rule,
 * deepened a little so a pale colour still reads at that size.
 */
function ruleColor(color: string | null): string {
    return color
        ? `color-mix(in oklch, ${color} 72%, #6f6e69)`
        : 'var(--bp-line)';
}

function sectionLetter(index: number): string {
    return index < 26 ? String.fromCharCode(65 + index) : String(index + 1);
}

function leftIn(section: Section): number {
    return section.tasks.filter((task) => !task.done).length;
}

const dimension = computed(() => {
    const sections = localGroups.value.length;

    return `${doneTasks.value} of ${totalTasks.value} done · ${sections} ${sections === 1 ? 'section' : 'sections'}`;
});

// ── Add section ───────────────────────────────────────────────────────────────

const addingSectionInput = ref(false);
const newSectionLabel = ref('');
const newSectionInputRef = ref<HTMLInputElement | null>(null);

function startAddSection(): void {
    addingSectionInput.value = true;
    newSectionLabel.value = '';
    nextTick(() => newSectionInputRef.value?.focus());
}

function submitNewSection(): void {
    const label = newSectionLabel.value.trim();

    if (!label) {
        addingSectionInput.value = false;

        return;
    }

    addingSectionInput.value = false;

    router.post('/task-sections', { label }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tasks" />

    <Teleport defer to="#page-actions">
        <button
            type="button"
            class="bp flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
            @click.stop="startAddSection"
        >
            <span class="ms" style="font-size: 16px" aria-hidden="true"
                >add</span
            >
            <span class="hidden sm:inline">Add section</span>
        </button>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            raised
            icon="check_circle"
            code="D-03"
            section="Day"
            title="Tasks"
            subtitle="Everything on your plate, sorted by when"
            :dimension="dimension"
        />

        <div :class="sheetWidth.wide">
            <!-- Summary strip and view switch -->
            <div
                class="mb-8 grid grid-cols-1 items-stretch gap-5 md:grid-cols-[minmax(0,1fr)_auto]"
            >
                <div
                    class="grid grid-cols-2 border border-[var(--bp-ink)] bg-[var(--bp-card)] sm:grid-cols-3"
                >
                    <div class="px-[18px] py-3.5">
                        <p class="bp-caption">Done</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ doneTasks }} of {{ totalTasks }}
                        </p>
                    </div>
                    <div
                        class="border-l border-[var(--bp-ink)] px-[18px] py-3.5"
                    >
                        <p class="bp-caption">Left</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ totalTasks - doneTasks }}
                        </p>
                    </div>
                    <div
                        class="bp-grid-fine col-span-2 border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:col-span-1 sm:border-t-0 sm:border-l"
                    >
                        <p class="bp-caption flex justify-between">
                            <span>Progress</span>
                            <span class="font-semibold text-[var(--bp-accent)]"
                                >{{ progressPct }}%</span
                            >
                        </p>
                        <div
                            class="mt-3 h-3 border border-[var(--bp-ink)] bg-[var(--bp-card)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
                            role="progressbar"
                            :aria-valuenow="progressPct"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="Tasks done"
                        >
                            <div
                                class="h-full bg-[var(--bp-accent)] transition-[width] duration-300"
                                :style="{ width: progressPct + '%' }"
                            ></div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col justify-center gap-1.5">
                    <span class="bp-caption" id="tasks-view-label">View</span>
                    <div
                        class="flex self-start overflow-hidden rounded-[3px] border border-[var(--bp-ink)]"
                        role="radiogroup"
                        aria-labelledby="tasks-view-label"
                    >
                        <button
                            v-for="option in viewOptions"
                            :key="option.mode"
                            type="button"
                            role="radio"
                            :aria-checked="viewMode === option.mode"
                            class="flex items-center gap-1.5 px-3 py-1.5 text-[13px]"
                            :class="
                                viewMode === option.mode
                                    ? 'bg-[var(--bp-ink)] font-semibold text-[var(--bp-card)]'
                                    : 'text-[var(--bp-ink-2)] hover:bg-[var(--bp-hover)]'
                            "
                            @click="setView(option.mode)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >{{ option.icon }}</span
                            >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Section cards -->
            <div
                class="grid grid-cols-1 items-start gap-5"
                :class="{ 'md:grid-cols-2': viewMode === 'grid' }"
            >
                <section
                    v-for="(group, index) in localGroups"
                    :key="group.id"
                    class="relative border border-[var(--bp-line)] bg-[var(--bp-card)]"
                    :class="{ 'opacity-60': draggedId === group.id }"
                    :draggable="editingSection !== group.id"
                    :aria-label="group.label"
                    @dragstart="onDragStart(group)"
                    @dragover="onDragOver($event, group)"
                    @drop="onDrop"
                >
                    <div
                        class="absolute inset-x-0 top-0 h-1"
                        :style="{ background: ruleColor(group.color) }"
                        aria-hidden="true"
                    ></div>

                    <div class="flex items-center gap-2.5 px-[18px] pt-4 pb-3">
                        <span
                            class="ms cursor-grab text-[#c9c7c0]"
                            style="font-size: 18px"
                            aria-hidden="true"
                            title="Drag to reorder"
                            >drag_indicator</span
                        >
                        <span
                            class="bp-mono flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-[var(--bp-ink)] text-[11px] font-semibold"
                            aria-hidden="true"
                            >{{ sectionLetter(index) }}</span
                        >
                        <input
                            v-if="editingSection === group.id"
                            ref="editInputRef"
                            v-model="editLabel"
                            aria-label="Section name"
                            class="min-w-0 flex-1 border-b-2 border-[var(--bp-ink)] bg-transparent text-[19px] font-semibold tracking-[-0.015em] outline-none"
                            @click.stop
                            @keydown.enter.prevent="submitRename(group)"
                            @keydown.escape.prevent="cancelRename(group)"
                            @blur="submitRename(group)"
                        />
                        <h2
                            v-else
                            class="min-w-0 cursor-text truncate text-[19px] font-semibold tracking-[-0.015em]"
                            title="Click to rename"
                            @click.stop="startRename(group)"
                        >
                            {{ group.label }}
                        </h2>
                        <span
                            v-if="
                                leftIn(group) > 0 && editingSection !== group.id
                            "
                            class="bp-mono shrink-0 rounded-sm border border-[var(--bp-line)] px-[7px] py-0.5 text-[10.5px] tracking-[0.08em] text-[var(--bp-muted)]"
                            >{{ leftIn(group) }} LEFT</span
                        >

                        <div
                            class="ml-auto flex shrink-0 gap-0.5 text-[var(--bp-faint)]"
                        >
                            <div class="relative">
                                <button
                                    type="button"
                                    class="flex h-7 w-7 items-center justify-center rounded-[3px] hover:bg-[var(--bp-hover)]"
                                    :aria-label="`Colour for ${group.label}`"
                                    :aria-expanded="
                                        colorPickerOpen === group.id
                                    "
                                    @click.stop="toggleColorPicker(group.id)"
                                >
                                    <span
                                        class="box-border h-[13px] w-[13px] border border-[var(--bp-ink)]"
                                        :style="{
                                            background: ruleColor(group.color),
                                        }"
                                    ></span>
                                </button>
                                <div
                                    v-if="colorPickerOpen === group.id"
                                    class="absolute top-8 right-0 z-10 w-[138px] border border-[var(--bp-ink)] bg-[var(--bp-card)] p-2"
                                    @click.stop
                                >
                                    <div class="grid grid-cols-4 gap-1.5">
                                        <button
                                            v-for="hex in PALETTE"
                                            :key="hex"
                                            type="button"
                                            class="box-border h-6 w-6 border"
                                            :class="
                                                group.color === hex
                                                    ? 'border-2 border-[var(--bp-ink)]'
                                                    : 'border-[var(--bp-line)] hover:border-[var(--bp-ink)]'
                                            "
                                            :style="{
                                                background: ruleColor(hex),
                                            }"
                                            :aria-label="`Use colour ${hex}`"
                                            @click="pickColor(group, hex)"
                                        ></button>
                                        <button
                                            type="button"
                                            class="flex h-6 w-6 items-center justify-center border border-[var(--bp-line)] hover:border-[var(--bp-ink)]"
                                            aria-label="No colour"
                                            @click="pickColor(group, null)"
                                        >
                                            <span
                                                class="ms text-[var(--bp-muted)]"
                                                style="font-size: 13px"
                                                aria-hidden="true"
                                                >block</span
                                            >
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="flex h-7 w-7 items-center justify-center rounded-[3px] hover:bg-[var(--bp-hover)] hover:text-[var(--bp-ink)]"
                                :aria-label="`Delete ${group.label}`"
                                @click.stop="confirmDelete(group)"
                            >
                                <span
                                    class="ms"
                                    style="font-size: 17px"
                                    aria-hidden="true"
                                    >delete</span
                                >
                            </button>
                        </div>
                    </div>

                    <div class="px-[18px] pb-1.5">
                        <div
                            v-for="task in group.tasks"
                            :key="task.id"
                            class="grid grid-cols-[22px_minmax(0,1fr)_24px] items-center gap-2.5 border-t border-[var(--bp-hair)] py-2.5"
                        >
                            <label class="contents cursor-pointer">
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
                            <button
                                type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-[3px] text-[#c9c7c0] hover:text-[var(--bp-ink)]"
                                :aria-label="`Delete ${task.label}`"
                                @click="deleteTask(group, task)"
                            >
                                <span
                                    class="ms"
                                    style="font-size: 16px"
                                    aria-hidden="true"
                                    >close</span
                                >
                            </button>
                        </div>

                        <div
                            v-if="addingInSection === group.id"
                            class="grid grid-cols-[22px_minmax(0,1fr)] items-center gap-2.5 border-t border-[var(--bp-hair)] py-2.5"
                        >
                            <span
                                class="ms text-[var(--bp-fainter)]"
                                style="font-size: 20px"
                                aria-hidden="true"
                                >check_box_outline_blank</span
                            >
                            <input
                                ref="newTaskInputRef"
                                v-model="newTaskLabel"
                                aria-label="New task"
                                class="min-w-0 bg-transparent text-[14px] outline-none placeholder:text-[var(--bp-faint)]"
                                placeholder="New task…"
                                @keydown.enter.prevent="submitNewTask(group)"
                                @keydown.escape.prevent="cancelAddTask"
                                @blur="submitNewTask(group)"
                            />
                        </div>
                        <button
                            v-else
                            type="button"
                            class="flex w-full items-center gap-1.5 border-t border-[var(--bp-hair)] pt-2.5 pb-3 text-[13.5px] text-[var(--bp-faint)] hover:text-[var(--bp-ink)]"
                            @click="startAddTask(group.id)"
                        >
                            <span
                                class="ms"
                                style="font-size: 17px"
                                aria-hidden="true"
                                >add</span
                            >
                            Add task
                        </button>
                    </div>
                </section>

                <!-- Add section -->
                <div
                    v-if="addingSectionInput"
                    class="border border-[var(--bp-ink)] bg-[var(--bp-card)] px-[18px] py-4"
                >
                    <input
                        ref="newSectionInputRef"
                        v-model="newSectionLabel"
                        aria-label="Section name"
                        class="w-full border-b-2 border-[var(--bp-ink)] bg-transparent text-[19px] font-semibold tracking-[-0.015em] outline-none placeholder:text-[var(--bp-faint)]"
                        placeholder="Section name…"
                        @click.stop
                        @keydown.enter.prevent="submitNewSection"
                        @keydown.escape.prevent="addingSectionInput = false"
                        @blur="submitNewSection"
                    />
                </div>
                <button
                    v-else
                    type="button"
                    class="flex min-h-[72px] items-center justify-center gap-1.5 border border-dashed border-[var(--bp-fainter)] text-[14px] text-[var(--bp-muted)] hover:border-[var(--bp-ink)] hover:text-[var(--bp-ink)]"
                    @click.stop="startAddSection"
                >
                    <span class="ms" style="font-size: 18px" aria-hidden="true"
                        >add</span
                    >
                    Add section
                </button>
            </div>

            <BlueprintTitleBlock drawing="Day / Tasks" sheet="D-03" />
        </div>
    </div>

    <!-- Delete confirmation dialog -->
    <Dialog
        :open="deleteTarget !== null"
        @update:open="
            (v) => {
                if (!v) deleteTarget = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete "{{ deleteTarget?.label }}"?</DialogTitle>
                <DialogDescription>
                    {{ deleteTarget?.tasks.length ?? 0 }}
                    {{
                        (deleteTarget?.tasks.length ?? 0) === 1
                            ? 'task'
                            : 'tasks'
                    }}
                    will also be permanently deleted. This cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <div class="flex justify-end gap-2 pt-2">
                <Button variant="outline" @click="deleteTarget = null"
                    >Cancel</Button
                >
                <Button variant="destructive" @click="executeDelete"
                    >Delete</Button
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
