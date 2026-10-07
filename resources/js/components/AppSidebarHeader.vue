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
    {
        icon: 'wb_sunny',
        label: 'Day',
        paths: ['/today', '/calendar', '/tasks'],
    },
    {
        icon: 'interests',
        label: 'Life',
        paths: [
            '/health',
            '/habits',
            '/notes',
            '/journal',
            '/meals',
            '/groceries',
            '/pets',
        ],
    },
    {
        icon: 'account_balance_wallet',
        label: 'Money',
        paths: ['/money'],
    },
];

type Page = { label: string; icon: string };

/**
 * Each page's breadcrumb label and icon, matching the sidebar. Most specific
 * first: the first prefix that matches wins, which also keeps detail pages such
 * as /money/debts/1 labelled "Debts" rather than "1".
 */
const pages: Record<string, Page> = {
    '/today': { label: 'Today', icon: 'wb_sunny' },
    '/calendar': { label: 'Calendar', icon: 'calendar_month' },
    '/tasks': { label: 'Tasks', icon: 'check_circle' },
    '/health': { label: 'Health', icon: 'favorite' },
    '/habits': { label: 'Habits', icon: 'loop' },
    '/notes': { label: 'Notes', icon: 'sticky_note_2' },
    '/journal': { label: 'Journal', icon: 'auto_stories' },
    '/meals': { label: 'Meals', icon: 'restaurant' },
    '/groceries': { label: 'Groceries', icon: 'shopping_cart' },
    '/pets': { label: 'Pets', icon: 'pets' },
    '/money/debts': { label: 'Debts', icon: 'credit_card' },
    '/money/calendar': { label: 'Calendar', icon: 'event_upcoming' },
    '/money/transactions': { label: 'Transactions', icon: 'receipt_long' },
    '/money/plan': { label: 'Plan', icon: 'flag' },
    '/money': { label: 'Overview', icon: 'account_balance_wallet' },
    '/photos': { label: 'Photos', icon: 'photo_library' },
};

const activeSection = computed(
    (): Section | null =>
        sections.find((s) =>
            s.paths.some((p) => currentUrl.value.startsWith(p)),
        ) ?? null,
);

const pagePath = computed(
    (): string | null =>
        Object.keys(pages).find((p) => currentUrl.value.startsWith(p)) ?? null,
);

const pageLabel = computed((): string => {
    if (pagePath.value) {
        return pages[pagePath.value].label;
    }

    const segment = currentUrl.value.split('/').filter(Boolean).pop() ?? '';

    return segment.charAt(0).toUpperCase() + segment.slice(1);
});

const pageIcon = computed(
    (): string | null =>
        (pagePath.value ? pages[pagePath.value].icon : null) ??
        activeSection.value?.icon ??
        null,
);

/** A detail page (deeper than its list) links the list's crumb back to it. */
const pageLink = computed((): string | null =>
    pagePath.value && currentUrl.value.split('?')[0] !== pagePath.value
        ? pagePath.value
        : null,
);
</script>

<template>
    <header
        class="bp sticky top-0 z-10 flex h-[52px] shrink-0 items-center gap-3 bg-[var(--bp-paper)]/90 px-4 backdrop-blur md:px-6"
    >
        <!-- Mobile sidebar trigger -->
        <SidebarTrigger class="-ml-1 shrink-0 md:hidden" />

        <!-- Breadcrumb -->
        <div class="flex min-w-0 items-center gap-[7px] text-[13px]">
            <span
                v-if="pageIcon"
                class="ms text-[var(--bp-muted)]"
                style="font-size: 16px"
                aria-hidden="true"
                >{{ pageIcon }}</span
            >
            <template v-if="activeSection">
                <span class="text-[var(--bp-muted)]">{{
                    activeSection.label
                }}</span>
                <span class="text-[var(--bp-faint)]">/</span>
            </template>
            <Link
                v-if="pageLink"
                :href="pageLink"
                class="page-label truncate font-medium hover:text-[var(--bp-ink)]"
                >{{ pageLabel }}</Link
            >
            <span v-else class="page-label truncate font-medium">{{
                pageLabel
            }}</span>
            <!-- A detail page adds its own last crumb here with
                 <Teleport defer to="#page-crumb">. -->
            <span id="page-crumb" class="page-crumb contents" />
        </div>

        <!-- Right actions -->
        <div class="ml-auto flex items-center gap-1">
            <button
                type="button"
                class="hidden items-center gap-1.5 rounded px-2.5 py-1.5 text-[13px] text-[var(--bp-muted)] hover:bg-[var(--bp-hover)] sm:flex"
            >
                <span class="ms" style="font-size: 17px" aria-hidden="true"
                    >search</span
                >
                <span>Search</span>
                <kbd class="bp-mono text-[10px] text-[var(--bp-faint)]">⌘K</kbd>
            </button>

            <!-- A page puts its own main action here with
                 <Teleport defer to="#page-actions">; the default shows otherwise. -->
            <div id="page-actions" class="page-actions ml-1.5 flex gap-1.5" />
            <Link
                href="/money/transactions"
                class="default-action ml-1.5 flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
            >
                <span class="ms" style="font-size: 16px" aria-hidden="true"
                    >add</span
                >
                <span class="hidden sm:inline">New entry</span>
            </Link>
        </div>
    </header>
</template>

<style scoped>
.page-actions:has(*) ~ .default-action {
    display: none;
}
</style>
