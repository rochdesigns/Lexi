<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { HabitatTheme } from '@/types';

type TodayEvent = { id: number; title: string; time: string; tag: string; color: string };
type TodayTask = { id: number; label: string; section_id: number; done: boolean };
type MoneySummary = { safeToSpend: number; budget: number; spent: number };
type Habit = { id: number; name: string; icon: string; color: string; pct: number; streak: number };
type HealthMetric = { name: string; value: string; sub: string; pct: number; color: string; icon: string };

const props = defineProps<{
    user: { name: string; firstName: string; theme: HabitatTheme };
    todayEvents: TodayEvent[];
    todayTasks: TodayTask[];
    moneySummary: MoneySummary;
    habits: Habit[];
    healthMetrics: HealthMetric[];
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
                            <span class="w-16 shrink-0 text-[12px] tabular-nums" style="color: var(--hab-muted)">
                                {{ event.time }}
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
                    href="/budgets"
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
