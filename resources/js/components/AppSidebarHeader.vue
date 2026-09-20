<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { BreadcrumbItem } from '@/types';

// Prop accepted for layout compatibility but not used — header derives state from URL.
withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const { currentUrl } = useCurrentUrl();

type Section = { icon: string; label: string; paths: string[] };

const sections: Section[] = [
    { icon: 'wb_sunny', label: 'Day', paths: ['/today', '/calendar', '/tasks'] },
    {
        icon: 'interests',
        label: 'Life',
        paths: ['/health', '/habits', '/notes', '/journal', '/meals', '/groceries', '/pets'],
    },
    {
        icon: 'account_balance_wallet',
        label: 'Money',
        paths: ['/payoff-plan', '/ledger', '/budget'],
    },
];

const pageLabels: Record<string, string> = {
    '/today': 'Today',
    '/calendar': 'Calendar',
    '/tasks': 'Tasks',
    '/health': 'Health',
    '/habits': 'Habits',
    '/notes': 'Notes',
    '/journal': 'Journal',
    '/meals': 'Meals',
    '/groceries': 'Groceries',
    '/pets': 'Pets',
    '/payoff-plan': 'Payoff Plan',
    '/ledger': 'Ledger',
    '/budget': 'Budget',
    '/photos': 'Photos',
};

const activeSection = computed(
    (): Section | null =>
        sections.find((s) => s.paths.some((p) => currentUrl.value.startsWith(p))) ?? null,
);

const pageLabel = computed((): string => {
    const matched = Object.keys(pageLabels).find((p) => currentUrl.value.startsWith(p));
    if (matched) {
        return pageLabels[matched];
    }
    const segment = currentUrl.value.split('/').filter(Boolean).pop() ?? '';
    return segment.charAt(0).toUpperCase() + segment.slice(1);
});

const notificationCount = 1;
</script>

<template>
    <header
        class="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b px-4 md:px-6"
        style="background: rgba(239, 236, 228, 0.86); backdrop-filter: blur(12px); border-color: #e0dacd"
    >
        <!-- Mobile sidebar trigger -->
        <SidebarTrigger class="md:hidden -ml-1 shrink-0" />

        <!-- Breadcrumb -->
        <div class="flex items-center gap-1.5 text-[13px]">
            <template v-if="activeSection">
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)">
                    {{ activeSection.icon }}
                </span>
                <span style="color: var(--hab-faint)">{{ activeSection.label }}</span>
                <span style="color: var(--hab-faint)">/</span>
            </template>
            <span class="font-semibold" style="color: var(--hab-ink)">{{ pageLabel }}</span>
        </div>

        <!-- Right actions -->
        <div class="ml-auto flex items-center gap-2">
            <!-- Search -->
            <div
                class="hidden sm:flex w-[200px] lg:w-[250px] items-center gap-2 rounded-[11px] border px-3 py-1.5"
                style="border-color: #e0dacd; background: var(--hab-card-bg)"
            >
                <span class="material-symbols-rounded shrink-0" style="font-size: 16px; color: var(--hab-faint)">search</span>
                <input
                    type="text"
                    placeholder="Search anything in your life…"
                    class="min-w-0 flex-1 bg-transparent text-[13px] outline-none"
                    style="color: var(--hab-ink)"
                    readonly
                />
                <kbd
                    class="rounded-[6px] px-1.5 py-0.5 text-[10px] font-medium"
                    style="background: var(--hab-chip-fill); color: var(--hab-faint)"
                    >⌘K</kbd
                >
            </div>

            <!-- Notification bell -->
            <button class="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-black/5">
                <span class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-ink-secondary)">
                    notifications
                </span>
                <span
                    v-if="notificationCount > 0"
                    class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full"
                    style="background: #c0512c"
                ></span>
            </button>

            <!-- New entry — the entry sheet itself lives on the ledger -->
            <Link
                href="/ledger"
                class="flex items-center gap-1.5 rounded-[11px] px-3 sm:px-4 py-2 text-[13px] font-semibold"
                style="background: var(--hab-btn-dark-bg); color: var(--hab-btn-dark-ink)"
            >
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-btn-dark-ink)">add</span>
                <span class="hidden sm:inline">New entry</span>
            </Link>
        </div>
    </header>
</template>
