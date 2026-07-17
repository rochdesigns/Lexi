<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Task = { id: number; label: string; group: 'today' | 'week' | 'someday'; done: boolean };
type TaskGroup = { key: 'today' | 'week' | 'someday'; label: string; tasks: Task[]; leftCount: number };

const props = defineProps<{ groups: TaskGroup[] }>();

const localGroups = ref(
    props.groups.map((g) => ({
        ...g,
        tasks: g.tasks.map((t) => ({ ...t })),
    })),
);

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

const showModal = ref(false);
const form = ref({ label: '', group: 'today' as Task['group'] });

function openModal(): void {
    form.value = { label: '', group: 'today' };
    showModal.value = true;
}

function submitTask(): void {
    router.post('/tasks', form.value, {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
        },
    });
}

const groupOptions: Array<{ key: Task['group']; label: string }> = [
    { key: 'today', label: 'Today' },
    { key: 'week', label: 'This week' },
    { key: 'someday', label: 'Someday' },
];
</script>

<template>
    <Head title="Tasks" />

    <div class="mx-auto w-full max-w-[1180px] px-[30px] pb-[46px] pt-6">

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
            <button
                class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[14px] font-semibold text-white"
                style="background: var(--hab-ink)"
                @click="openModal"
            >
                <span class="material-symbols-rounded" style="font-size: 18px">add</span>
                Add task
            </button>
        </div>

        <!-- Group cards -->
        <div class="flex flex-col gap-5">
            <div
                v-for="group in localGroups"
                :key="group.key"
                class="rounded-[22px] p-6"
                style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h2
                        class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                        style="color: var(--hab-ink)"
                    >
                        {{ group.label }}
                    </h2>
                    <span
                        v-if="group.tasks.filter((t) => !t.done).length > 0"
                        class="rounded-full px-2.5 py-0.5 text-[12px] font-semibold"
                        style="background: var(--hab-surface); color: var(--hab-muted)"
                    >
                        {{ group.tasks.filter((t) => !t.done).length }} left
                    </span>
                </div>

                <!-- Empty state -->
                <p
                    v-if="group.tasks.length === 0"
                    class="py-2 text-[13px]"
                    style="color: var(--hab-muted)"
                >
                    Nothing here yet
                </p>

                <!-- Task rows -->
                <template v-else>
                    <div v-for="(task, i) in group.tasks" :key="task.id">
                        <div
                            v-if="i > 0"
                            style="height: 1px; background: var(--hab-card-border)"
                        />
                        <button
                            class="flex w-full items-center gap-3 py-3 text-left"
                            @click="toggleTask(task)"
                        >
                            <span
                                class="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-[5px] border-2 transition-colors"
                                :style="task.done
                                    ? 'background: var(--hab-accent); border-color: var(--hab-accent)'
                                    : 'background: transparent; border-color: var(--hab-muted)'"
                            >
                                <span
                                    v-if="task.done"
                                    class="material-symbols-rounded text-white"
                                    style="font-size: 14px"
                                >check</span>
                            </span>
                            <span
                                class="text-[14px]"
                                :style="task.done
                                    ? 'text-decoration: line-through; color: var(--hab-muted)'
                                    : 'color: var(--hab-ink)'"
                            >{{ task.label }}</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Add task modal -->
    <Dialog v-model:open="showModal">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add task</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4 pt-2" @submit.prevent="submitTask">
                <Input
                    v-model="form.label"
                    autofocus
                    placeholder="What needs doing?"
                    required
                />
                <div class="flex gap-2">
                    <button
                        v-for="opt in groupOptions"
                        :key="opt.key"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors"
                        :style="form.group === opt.key
                            ? 'background: var(--hab-accent); color: white'
                            : 'background: var(--hab-surface); color: var(--hab-muted)'"
                        @click="form.group = opt.key"
                    >
                        {{ opt.label }}
                    </button>
                </div>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="showModal = false">Cancel</Button>
                    <Button type="submit">Add task</Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
