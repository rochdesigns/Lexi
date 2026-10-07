<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import type { LexiNavSection } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth?.user as any);
const { isCurrentUrl } = useCurrentUrl();

const navSections: LexiNavSection[] = [
    {
        code: '01',
        label: 'Day',
        items: [
            { title: 'Today', href: '/today', icon: 'wb_sunny' },
            { title: 'Calendar', href: '/calendar', icon: 'calendar_month' },
            { title: 'Tasks', href: '/tasks', icon: 'check_circle' },
        ],
    },
    {
        code: '02',
        label: 'Life',
        items: [
            { title: 'Health', href: '/health', icon: 'favorite' },
            { title: 'Habits', href: '/habits', icon: 'loop' },
            { title: 'Notes', href: '/notes', icon: 'sticky_note_2' },
            { title: 'Journal', href: '/journal', icon: 'auto_stories' },
            { title: 'Meals', href: '/meals', icon: 'restaurant' },
            { title: 'Groceries', href: '/groceries', icon: 'shopping_cart' },
            { title: 'Pets', href: '/pets', icon: 'pets' },
            { title: 'Photos', href: '/photos', icon: 'photo_library' },
        ],
    },
    {
        code: '03',
        label: 'Money',
        items: [
            { title: 'Overview', href: '/money', icon: 'wallet' },
            { title: 'Debts', href: '/money/debts', icon: 'credit_card' },
            {
                title: 'Calendar',
                href: '/money/calendar',
                icon: 'event_upcoming',
            },
            {
                title: 'Transactions',
                href: '/money/transactions',
                icon: 'receipt_long',
            },
            { title: 'Plan', href: '/money/plan', icon: 'flag' },
        ],
    },
];
</script>

<template>
    <Sidebar class="bp border-r border-[var(--bp-line)]">
        <!-- Brand row -->
        <SidebarHeader class="px-2.5 pt-4 pb-0">
            <div class="flex items-center gap-2.5 px-2.5 pt-1.5 pb-5">
                <div
                    class="flex h-[26px] w-[26px] items-center justify-center rounded bg-[var(--bp-ink)]"
                >
                    <span
                        class="font-[Georgia,serif] text-[16px] leading-none font-bold text-[var(--bp-card)]"
                        >L</span
                    >
                </div>
                <span class="text-[15px] font-semibold tracking-[-0.01em]"
                    >Lexi</span
                >
                <span
                    v-if="user"
                    class="bp-mono ml-auto rounded-full border border-[var(--bp-line)] px-2 py-px text-[10.5px] text-[var(--bp-muted)]"
                >
                    {{ user.name.split(' ')[0] }}
                </span>
            </div>
        </SidebarHeader>

        <!-- Nav sections -->
        <SidebarContent class="overflow-y-auto px-2.5 py-0">
            <div
                v-for="section in navSections"
                :key="section.label"
                class="mb-4 flex flex-col gap-px"
            >
                <p
                    class="flex gap-2 px-2.5 py-1.5 text-[11.5px] font-medium text-[var(--bp-faint)]"
                >
                    <span
                        class="bp-mono text-[10.5px] text-[var(--bp-fainter)]"
                        >{{ section.code }}</span
                    >
                    <span>{{ section.label }}</span>
                </p>
                <nav class="flex flex-col gap-px">
                    <Link
                        v-for="item in section.items"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="
                            isCurrentUrl(item.href) ? 'page' : undefined
                        "
                        :class="[
                            'flex items-center gap-2.5 rounded px-2.5 py-[5px] text-[13.5px] transition-colors',
                            isCurrentUrl(item.href)
                                ? 'bg-[var(--bp-active)] font-semibold text-[var(--bp-ink)]'
                                : 'font-medium text-[var(--bp-ink-2)] hover:bg-[var(--bp-hover)]',
                        ]"
                    >
                        <span class="bp-duo h-[18px] w-[18px] text-[18px]">
                            <span
                                :class="
                                    isCurrentUrl(item.href)
                                        ? 'text-[var(--bp-soft)]'
                                        : 'text-[var(--bp-line)]'
                                "
                                >{{ item.icon }}</span
                            >
                            <span
                                :class="
                                    isCurrentUrl(item.href)
                                        ? 'text-[var(--bp-accent)]'
                                        : 'text-[var(--bp-ink-2)]'
                                "
                                >{{ item.icon }}</span
                            >
                        </span>
                        <span>{{ item.title }}</span>
                    </Link>
                </nav>
            </div>
        </SidebarContent>

        <!-- User tile -->
        <SidebarFooter class="px-2.5 pb-4">
            <div class="border-t border-[var(--bp-hair)] pt-2.5">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            class="flex w-full items-center gap-2.5 rounded px-2.5 py-2 text-left transition-colors hover:bg-[var(--bp-hover)]"
                        >
                            <div
                                class="bp-mono flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full border border-[var(--bp-ink)] bg-[var(--bp-card)] text-[11px] font-semibold"
                            >
                                {{ user ? getInitials(user.name) : '?' }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13px] font-medium">
                                    {{ user?.name }}
                                </p>
                                <p class="text-[11px] text-[var(--bp-muted)]">
                                    Free plan
                                </p>
                            </div>
                            <span
                                class="ms text-[var(--bp-faint)]"
                                style="font-size: 16px"
                                >chevron_right</span
                            >
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" side="right" class="w-56">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </SidebarFooter>
    </Sidebar>
</template>
