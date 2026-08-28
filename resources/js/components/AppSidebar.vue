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
import type { HabitatNavSection } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth?.user as any);
const { isCurrentUrl } = useCurrentUrl();

const navSections: HabitatNavSection[] = [
    {
        label: 'Day',
        items: [
            { title: 'Today', href: '/today', icon: 'wb_sunny' },
            { title: 'Calendar', href: '/calendar', icon: 'calendar_month' },
            { title: 'Tasks', href: '/tasks', icon: 'check_circle' },
        ],
    },
    {
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
        label: 'Money',
        items: [
            { title: 'Accounts', href: '/accounts', icon: 'account_balance' },
            { title: 'Money', href: '/money', icon: 'account_balance_wallet' },
            { title: 'Set-asides', href: '/set-asides', icon: 'savings' },
            { title: 'Debt', href: '/debt', icon: 'credit_card' },
            { title: 'Payoff Plan', href: '/payoff-plan', icon: 'trending_down' },
            { title: 'Ledger', href: '/ledger', icon: 'receipt_long' },
        ],
    },
];
</script>

<template>
    <Sidebar
        class="border-r-0"
        :style="{ background: 'var(--hab-sidebar-bg)' }"
    >
        <!-- Brand row -->
        <SidebarHeader class="px-4 py-5">
            <div class="flex items-center gap-3">
                <!-- Logo mark -->
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-white">
                    <span style="font-family: 'Georgia', serif; font-size: 17px; font-weight: 700; color: #142731; line-height: 1; letter-spacing: -0.5px">L</span>
                </div>
                <!-- Wordmark -->
                <span
                    class="font-['Bricolage_Grotesque'] text-[17px] font-bold text-white"
                >Lexi</span>
                <!-- User pill -->
                <span
                    v-if="user"
                    class="ml-auto rounded-full px-2.5 py-0.5 text-[11px] font-medium text-white/80"
                    style="background: rgba(255,255,255,.15)"
                >
                    {{ user.name.split(' ')[0] }}
                </span>
            </div>
        </SidebarHeader>

        <!-- Nav sections -->
        <SidebarContent class="px-2 py-0 overflow-y-auto">
            <div v-for="section in navSections" :key="section.label" class="mb-4">
                <!-- Group label -->
                <p
                    class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-widest"
                    style="color: #8a978f"
                >
                    {{ section.label }}
                </p>
                <!-- Nav items -->
                <nav>
                    <Link
                        v-for="item in section.items"
                        :key="item.href"
                        :href="item.href"
                        :class="['flex items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] transition-colors', isCurrentUrl(item.href) ? 'font-semibold' : 'font-medium']"
                        :style="isCurrentUrl(item.href)
                            ? { background: 'var(--hab-brand)', color: 'var(--hab-brand-ink)' }
                            : { color: '#c3d1c9' }"
                    >
                        <span
                            class="material-symbols-rounded text-[18px]"
                            :style="{ color: isCurrentUrl(item.href) ? 'var(--hab-brand-ink)' : '#94a89d', fontSize: '18px' }"
                        >{{ item.icon }}</span>
                        <span>{{ item.title }}</span>
                    </Link>
                </nav>
            </div>
        </SidebarContent>

        <!-- User tile -->
        <SidebarFooter class="px-2 pb-4">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-white/10"
                    >
                        <!-- Avatar -->
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[13px] font-semibold"
                            :style="{ background: 'var(--hab-brand)', color: 'var(--hab-brand-ink)' }"
                        >
                            {{ user ? getInitials(user.name) : '?' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[13px] font-medium text-white">
                                {{ user?.name }}
                            </p>
                            <p class="text-[11px]" style="color: #8a978f">Free plan</p>
                        </div>
                        <span class="material-symbols-rounded text-[16px]" style="color: #8a978f; font-size: 16px">chevron_right</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" side="right" class="w-56">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarFooter>
    </Sidebar>
</template>
