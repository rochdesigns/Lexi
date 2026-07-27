<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

type Task = { id: number; label: string; section_id: number; done: boolean };
type Section = { id: number; label: string; position: number; color: string | null; tasks: Task[] };

const props = defineProps<{ groups: Section[] }>();

const localGroups = ref<Section[]>(
    props.groups.map((g) => ({
        ...g,
        tasks: g.tasks.map((t) => ({ ...t })),
    })),
);

watch(() => props.groups, (newGroups) => {
    localGroups.value = newGroups.map((g) => ({
        ...g,
        tasks: g.tasks.map((t) => ({ ...t })),
    }));
}, { deep: true });

// ── Progress ─────────────────────────────────────────────────────────────────

const totalTasks = computed(() => localGroups.value.reduce((sum, g) => sum + g.tasks.length, 0));
const doneTasks = computed(() => localGroups.value.reduce((sum, g) => sum + g.tasks.filter((t) => t.done).length, 0));
const progressPct = computed(() => totalTasks.value > 0 ? Math.round((doneTasks.value / totalTasks.value) * 100) : 0);

// ── Task toggle ──────────────────────────────────────────────────────────────

function toggleTask(task: Task): void {
    const prev = task.done;
    task.done = !prev;
    router.patch(
        `/tasks/${task.id}`,
        { done: task.done },
        { preserveScroll: true, onError: () => { task.done = prev; } },
    );
}

function deleteTask(section: Section, task: Task): void {
    section.tasks = section.tasks.filter((t) => t.id !== task.id);
    router.delete(`/tasks/${task.id}`, {
        preserveScroll: true,
        onError: () => { section.tasks.push(task); },
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

    const optimisticId = -(Date.now());
    const optimisticTask: Task = { id: optimisticId, label, section_id: section.id, done: false };
    section.tasks.push(optimisticTask);
    newTaskLabel.value = '';
    nextTick(() => newTaskInputRef.value?.focus());

    router.post(
        '/tasks',
        { label, section_id: section.id },
        {
            preserveScroll: true,
            onError: () => {
                section.tasks = section.tasks.filter((t) => t.id !== optimisticId);
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
    setTimeout(() => { justOpenedRename.value = false; }, 0);
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
            onError: () => { section.label = prev; },
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
    if (justOpenedRename.value || editingSection.value === null) { return; }
    const section = localGroups.value.find((g) => g.id === editingSection.value);
    if (section) { submitRename(section); }
}
onMounted(() => window.addEventListener('click', handleWindowClick));
onUnmounted(() => window.removeEventListener('click', handleWindowClick));

const PALETTE = [
    '#fce4e4', '#fde8d0', '#fdf6d0', '#d4f0e0',
    '#d0e8fd', '#e8d4f0', '#fad4e8', '#e8e8e8',
];

const colorPickerOpen = ref<number | null>(null);

function toggleColorPicker(sectionId: number): void {
    colorPickerOpen.value = colorPickerOpen.value === sectionId ? null : sectionId;
}

function pickColor(section: Section, hex: string | null): void {
    colorPickerOpen.value = null;
    const prev = section.color;
    section.color = hex;
    router.patch(
        `/task-sections/${section.id}`,
        { color: hex },
        { preserveScroll: true, onError: () => { section.color = prev; } },
    );
}

// ── Delete section ───────────────────────────────────────────────────────────

const deleteTarget = ref<Section | null>(null);

function confirmDelete(section: Section): void {
    deleteTarget.value = section;
}

function executeDelete(): void {
    const section = deleteTarget.value;
    if (!section) { return; }

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
    if (draggedId.value === null || draggedId.value === target.id) { return; }

    const fromIdx = localGroups.value.findIndex((g) => g.id === draggedId.value);
    const toIdx = localGroups.value.findIndex((g) => g.id === target.id);
    if (fromIdx === -1 || toIdx === -1) { return; }

    const reordered = [...localGroups.value];
    const [moved] = reordered.splice(fromIdx, 1);
    reordered.splice(toIdx, 0, moved);
    localGroups.value = reordered;
}

function onDrop(): void {
    if (draggedId.value === null) { return; }
    draggedId.value = null;

    const sections = localGroups.value.map((g, i) => ({ id: g.id, position: i + 1 }));

    router.post('/task-sections/reorder', { sections }, { preserveScroll: true });
}

// ── View mode ────────────────────────────────────────────────────────────────

type ViewMode = 'list' | 'grid';
const viewMode = ref<ViewMode>((localStorage.getItem('tasks-view') as ViewMode) ?? 'list');

function setView(mode: ViewMode): void {
    viewMode.value = mode;
    localStorage.setItem('tasks-view', mode);
}

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

    router.post(
        '/task-sections',
        { label },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Tasks" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Page header -->
        <div class="mb-6 flex items-end justify-between">
            <div>
                <h1
                    class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                    style="color: var(--hab-ink)"
                >
                    Tasks
                </h1>
                <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                    Everything on your plate, sorted by when
                </p>
            </div>

            <div class="flex items-center gap-3">
                <!-- Progress widget -->
                <div v-if="totalTasks > 0" class="flex items-center gap-3">
                    <span class="text-[13px] font-medium tabular-nums" style="color: var(--hab-muted)">
                        {{ doneTasks }} of {{ totalTasks }} done
                    </span>
                    <div
                        class="h-2 w-32 overflow-hidden rounded-full"
                        style="background: var(--hab-progress-track)"
                    >
                        <div
                            class="h-full rounded-full transition-all duration-300"
                            style="background: var(--hab-accent)"
                            :style="{ width: progressPct + '%' }"
                        ></div>
                    </div>
                </div>

                <!-- View toggle -->
            <div
                class="flex items-center gap-0.5 rounded-xl p-1"
                style="background: var(--hab-surface); border: 1px solid var(--hab-card-border)"
            >
                <button
                    v-for="{ mode, icon } in ([{ mode: 'list', icon: 'view_agenda' }, { mode: 'grid', icon: 'grid_view' }] as const)"
                    :key="mode"
                    class="flex h-8 w-8 items-center justify-center rounded-lg transition-colors"
                    :style="viewMode === mode
                        ? 'background: var(--hab-card-bg); color: var(--hab-ink); box-shadow: 0 1px 3px rgba(0,0,0,.08)'
                        : 'color: var(--hab-muted)'"
                    :title="mode === 'list' ? 'List view' : 'Grid view'"
                    @click="setView(mode)"
                >
                    <span class="material-symbols-rounded" style="font-size: 18px">{{ icon }}</span>
                </button>
            </div>
            </div>
        </div>

        <!-- Section cards -->
        <div :class="viewMode === 'grid' ? 'grid grid-cols-1 sm:grid-cols-2 gap-5' : 'flex flex-col gap-5'">
            <div
                v-for="group in localGroups"
                :key="group.id"
                class="rounded-[22px] p-6"
                :style="`background: ${group.color ?? 'var(--hab-card-bg)'}; border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)`"
                :draggable="editingSection !== group.id"
                @dragstart="onDragStart(group)"
                @dragover="onDragOver($event, group)"
                @drop="onDrop"
            >
                <!-- Section header -->
                <div class="group mb-4 flex items-center gap-2">
                    <!-- Drag handle -->
                    <span
                        class="material-symbols-rounded cursor-grab opacity-0 transition-opacity group-hover:opacity-40"
                        style="font-size: 18px; color: var(--hab-muted)"
                    >drag_indicator</span>

                    <!-- Editable label -->
                    <input
                        v-if="editingSection === group.id"
                        ref="editInputRef"
                        v-model="editLabel"
                        class="flex-1 rounded-md px-2 py-0.5 font-['Bricolage_Grotesque'] text-[18px] font-bold outline-none ring-1"
                        style="color: var(--hab-ink); background: var(--hab-surface); ring-color: var(--hab-accent)"
                        @click.stop
                        @keydown.enter.prevent="submitRename(group)"
                        @keydown.escape.prevent="cancelRename(group)"
                        @blur="submitRename(group)"
                    />
                    <h2
                        v-else
                        class="flex-1 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                        style="color: var(--hab-ink)"
                        @click="startRename(group)"
                    >
                        {{ group.label }}
                    </h2>

                    <!-- Task count -->
                    <span
                        v-if="group.tasks.filter((t) => !t.done).length > 0 && editingSection !== group.id"
                        class="rounded-full px-2.5 py-0.5 text-[12px] font-semibold"
                        style="background: var(--hab-surface); color: var(--hab-muted)"
                    >
                        {{ group.tasks.filter((t) => !t.done).length }} left
                    </span>

                    <!-- Color picker + delete -->
                    <div class="flex items-center gap-1">
                        <div class="relative">
                            <button
                                class="flex h-7 w-7 items-center justify-center rounded-lg"
                                title="Section color"
                                @click.stop="toggleColorPicker(group.id)"
                            >
                                <span
                                    class="h-4 w-4 rounded-full border-2"
                                    :style="`background: ${group.color ?? 'var(--hab-surface)'}; border-color: var(--hab-card-border)`"
                                />
                            </button>
                            <div
                                v-if="colorPickerOpen === group.id"
                                class="absolute right-0 top-8 z-10 w-[130px] rounded-xl p-2 shadow-lg"
                                style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border)"
                            >
                                <div class="grid grid-cols-4 gap-1.5">
                                    <button
                                        v-for="hex in PALETTE"
                                        :key="hex"
                                        class="h-6 w-6 rounded-full border-2 transition-transform hover:scale-110"
                                        :style="`background: ${hex}; border-color: ${group.color === hex ? 'var(--hab-accent)' : 'transparent'}`"
                                        @click="pickColor(group, hex)"
                                    />
                                    <button
                                        class="flex h-6 w-6 items-center justify-center rounded-full border-2 transition-transform hover:scale-110"
                                        style="border-color: var(--hab-card-border)"
                                        title="No color"
                                        @click="pickColor(group, null)"
                                    >
                                        <span class="material-symbols-rounded" style="font-size: 13px; color: var(--hab-muted)">block</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button
                            class="flex h-7 w-7 items-center justify-center rounded-lg transition-colors"
                            style="color: var(--hab-muted)"
                            title="Delete section"
                            @click="confirmDelete(group)"
                        >
                            <span class="material-symbols-rounded" style="font-size: 16px">delete</span>
                        </button>
                    </div>
                </div>

                <!-- Task rows -->
                <template v-if="group.tasks.length > 0">
                    <div v-for="(task, i) in group.tasks" :key="task.id">
                        <div v-if="i > 0" style="height: 1px; background: var(--hab-card-border)" />
                        <div class="group/task flex w-full items-center gap-3 py-3">
                            <button
                                class="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-[5px] border-2 transition-colors"
                                :style="task.done
                                    ? 'background: var(--hab-accent); border-color: var(--hab-accent)'
                                    : 'background: transparent; border-color: var(--hab-muted)'"
                                @click="toggleTask(task)"
                            >
                                <span
                                    v-if="task.done"
                                    class="material-symbols-rounded text-white"
                                    style="font-size: 14px"
                                >check</span>
                            </button>
                            <span
                                class="flex-1 text-left text-[14px]"
                                :style="task.done
                                    ? 'text-decoration: line-through; color: var(--hab-muted)'
                                    : 'color: var(--hab-ink)'"
                                @click="toggleTask(task)"
                            >{{ task.label }}</span>
                            <div class="overflow-hidden transition-[max-width] duration-200 ease-out max-w-0 group-hover/task:max-w-[32px] flex-shrink-0">
                                <div class="flex items-center pl-2">
                                    <button class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-md"
                                        style="color: var(--hab-muted)"
                                        title="Delete task"
                                        @click="deleteTask(group, task)">
                                        <span class="material-symbols-rounded" style="font-size: 16px">delete</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Separator before add-task row -->
                <div
                    v-if="group.tasks.length > 0 && addingInSection !== group.id"
                    style="height: 1px; background: var(--hab-card-border)"
                />

                <!-- Inline add-task input -->
                <div v-if="addingInSection === group.id" class="pt-2">
                    <div v-if="group.tasks.length > 0" style="height: 1px; background: var(--hab-card-border); margin-bottom: 8px" />
                    <div class="flex items-center gap-3 py-2">
                        <span
                            class="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-[5px] border-2"
                            style="border-color: var(--hab-muted); opacity: 0.4"
                        />
                        <input
                            ref="newTaskInputRef"
                            v-model="newTaskLabel"
                            class="flex-1 bg-transparent text-[14px] outline-none"
                            style="color: var(--hab-ink)"
                            placeholder="New task…"
                            @keydown.enter.prevent="submitNewTask(group)"
                            @keydown.escape.prevent="cancelAddTask"
                            @blur="submitNewTask(group)"
                        />
                    </div>
                </div>

                <!-- + Add task button -->
                <button
                    v-else
                    class="mt-2 flex items-center gap-1.5 py-1.5 text-[13px] font-medium transition-opacity"
                    style="color: var(--hab-muted)"
                    @click="startAddTask(group.id)"
                >
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span>
                    Add task
                </button>
            </div>
        </div>

        <!-- + Add section -->
        <div class="mt-5">
            <div
                v-if="addingSectionInput"
                class="rounded-[22px] p-6"
                style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border)"
            >
                <input
                    ref="newSectionInputRef"
                    v-model="newSectionLabel"
                    class="w-full bg-transparent font-['Bricolage_Grotesque'] text-[18px] font-bold outline-none"
                    style="color: var(--hab-ink)"
                    placeholder="Section name…"
                    @keydown.enter.prevent="submitNewSection"
                    @keydown.escape.prevent="addingSectionInput = false"
                    @blur="submitNewSection"
                />
            </div>
            <button
                v-else
                class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[14px] font-semibold transition-colors"
                style="color: var(--hab-muted); border: 1.5px dashed var(--hab-card-border)"
                @click="startAddSection"
            >
                <span class="material-symbols-rounded" style="font-size: 18px">add</span>
                Add section
            </button>
        </div>
    </div>

    <!-- Delete confirmation dialog -->
    <Dialog :open="deleteTarget !== null" @update:open="(v) => { if (!v) deleteTarget = null; }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete "{{ deleteTarget?.label }}"?</DialogTitle>
                <DialogDescription>
                    {{ deleteTarget?.tasks.length ?? 0 }}
                    {{ (deleteTarget?.tasks.length ?? 0) === 1 ? 'task' : 'tasks' }}
                    will also be permanently deleted. This cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <div class="flex justify-end gap-2 pt-2">
                <Button variant="outline" @click="deleteTarget = null">Cancel</Button>
                <Button variant="destructive" @click="executeDelete">Delete</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
