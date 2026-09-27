<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { dashboard, login, privacy, register, terms } from '@/routes';

type NavItem = { title: string; icon: string; delay: string };
type NavSection = { label: string; items: Omit<NavItem, 'delay'>[] };

/**
 * Mirrors the signed-in sidebar so the landing page shows the real index
 * of the app rather than an invented feature list.
 */
const navSections: NavSection[] = [
    {
        label: 'Day',
        items: [
            { title: 'Today', icon: 'wb_sunny' },
            { title: 'Calendar', icon: 'calendar_month' },
            { title: 'Tasks', icon: 'check_circle' },
        ],
    },
    {
        label: 'Money',
        items: [
            { title: 'Payoff Plan', icon: 'trending_down' },
            { title: 'Ledger', icon: 'receipt_long' },
            { title: 'Budget', icon: 'wallet' },
            { title: 'Forecast', icon: 'insights' },
        ],
    },
    {
        label: 'Life',
        items: [
            { title: 'Health', icon: 'favorite' },
            { title: 'Habits', icon: 'loop' },
            { title: 'Notes', icon: 'sticky_note_2' },
            { title: 'Journal', icon: 'auto_stories' },
            { title: 'Meals', icon: 'restaurant' },
            { title: 'Groceries', icon: 'shopping_cart' },
            { title: 'Pets', icon: 'pets' },
            { title: 'Photos', icon: 'photo_library' },
        ],
    },
];

/**
 * Stagger the nav items in on load, counting across sections rather than
 * restarting the count at each group.
 */
let cursor = 0;
const staggeredSections: { label: string; items: NavItem[] }[] =
    navSections.map((section) => ({
        label: section.label,
        items: section.items.map((item) => ({
            ...item,
            delay: `${cursor++ * 22 + 80}ms`,
        })),
    }));

const comingSoon = [
    'Health',
    'Habits',
    'Notes',
    'Journal',
    'Meals',
    'Groceries',
    'Pets',
    'Photos',
];

// The brand tokens live under `body[data-theme]`, applied by AppSidebarLayout
// for signed-in users. Guests get the default ocean palette here.
onMounted((): void => {
    document.body.dataset.theme = 'ocean';
});

onBeforeUnmount((): void => {
    delete document.body.dataset.theme;
});
</script>

<template>
    <Head title="Lexi" />

    <div
        class="min-h-screen font-sans"
        style="background: var(--hab-app-bg); color: var(--hab-ink)"
    >
        <header class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-6">
            <div class="flex items-center gap-3">
                <span
                    class="flex h-7 w-7 items-center justify-center rounded-lg"
                    style="background: var(--hab-sidebar-bg)"
                >
                    <span
                        style="
                            font-family: Georgia, serif;
                            font-size: 17px;
                            font-weight: 700;
                            line-height: 1;
                            letter-spacing: -0.5px;
                            color: var(--hab-brand);
                        "
                        >L</span
                    >
                </span>
                <span
                    class="font-['Bricolage_Grotesque'] text-[19px] font-bold tracking-[-0.02em]"
                    >Lexi</span
                >
            </div>

            <nav class="ml-auto flex items-center gap-2 text-[14px]">
                <template v-if="$page.props.auth.user">
                    <Link :href="dashboard()" class="btn-solid">Open Lexi</Link>
                </template>
                <template v-else>
                    <Link :href="login()" class="btn-quiet">Log in</Link>
                    <Link :href="register()" class="btn-solid"
                        >Create an account</Link
                    >
                </template>
            </nav>
        </header>

        <!-- Hero: the app's own index, rendered as the page's left column -->
        <section
            class="mx-auto grid max-w-6xl gap-10 px-6 pt-6 pb-20 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-16 lg:pt-16"
        >
            <div class="max-w-[34rem] lg:self-center">
                <h1
                    class="font-['Bricolage_Grotesque'] text-[2.6rem] leading-[1.03] font-extrabold tracking-[-0.035em] sm:text-[3.4rem]"
                >
                    One place for everything you're keeping track of.
                </h1>
                <p
                    class="mt-6 max-w-[46ch] text-[16.5px] leading-[1.65]"
                    style="color: var(--hab-ink-secondary)"
                >
                    Lexi is a private home for the day ahead, the debt you're
                    clearing, and the hundred small things that keep a household
                    running. Nothing connects to a bank. No one else is looking.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="btn-solid btn-lg"
                        >Open Lexi</Link
                    >
                    <template v-else>
                        <Link :href="login()" class="btn-solid btn-lg"
                            >Log in</Link
                        >
                        <Link :href="register()" class="btn-outline btn-lg"
                            >Create an account</Link
                        >
                    </template>
                </div>
            </div>

            <!-- The sidebar, as the hero image -->
            <div
                class="rounded-2xl px-3 py-6"
                style="background: var(--hab-sidebar-bg)"
            >
                <div
                    v-for="section in staggeredSections"
                    :key="section.label"
                    class="mb-4 last:mb-0"
                >
                    <p
                        class="px-3 py-1.5 text-[10px] font-semibold tracking-widest uppercase"
                        style="color: #8a978f"
                    >
                        {{ section.label }}
                    </p>
                    <div class="grid grid-cols-2 lg:grid-cols-1">
                        <div
                            v-for="item in section.items"
                            :key="item.title"
                            class="nav-item flex items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] font-medium"
                            :style="{
                                color: '#c3d1c9',
                                animationDelay: item.delay,
                            }"
                        >
                            <span
                                class="material-symbols-rounded text-[18px]"
                                style="color: #94a89d; font-size: 18px"
                                >{{ item.icon }}</span
                            >
                            <span>{{ item.title }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Day -->
        <section class="mx-auto max-w-6xl px-6 py-14">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-16">
                <div class="max-w-[30rem]">
                    <h2
                        class="font-['Bricolage_Grotesque'] text-[2rem] leading-[1.1] font-bold tracking-[-0.03em]"
                    >
                        The day, before it gets away from you
                    </h2>
                    <p
                        class="mt-4 max-w-[48ch] text-[15.5px] leading-[1.65]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        Today gathers what's on the calendar, what's due, and
                        what you said you'd do onto one screen — the one you
                        check over coffee and again at six.
                    </p>
                </div>

                <div class="hab-card p-5">
                    <div
                        class="flex items-baseline justify-between border-b pb-3"
                        style="border-color: var(--hab-hairline)"
                    >
                        <span class="text-[15px] font-semibold">Today</span>
                        <span
                            class="text-[12px] tabular-nums"
                            style="color: var(--hab-muted)"
                            >Sat 20 Sep</span
                        >
                    </div>
                    <div
                        class="flex gap-4 border-b py-3.5"
                        style="border-color: var(--hab-hairline)"
                    >
                        <span
                            class="w-14 shrink-0 text-[12.5px] tabular-nums"
                            style="color: var(--hab-muted)"
                            >09:30</span
                        >
                        <span class="text-[14px]"
                            >Vet — Miso's annual shots</span
                        >
                    </div>
                    <div
                        class="flex gap-4 border-b py-3.5"
                        style="border-color: var(--hab-hairline)"
                    >
                        <span
                            class="w-14 shrink-0 text-[12.5px] tabular-nums"
                            style="color: var(--hab-muted)"
                            >14:00</span
                        >
                        <span class="text-[14px]">Move the card payment</span>
                    </div>
                    <div class="flex items-center gap-3 pt-3.5">
                        <span
                            class="material-symbols-rounded text-[18px]"
                            style="color: var(--hab-brand); font-size: 18px"
                            >check_circle</span
                        >
                        <span
                            class="text-[14px] line-through"
                            style="color: var(--hab-faint)"
                            >Groceries for the week</span
                        >
                    </div>
                </div>
            </div>
        </section>

        <!-- Money -->
        <section class="mx-auto max-w-6xl px-6 py-14">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-16">
                <div class="max-w-[30rem] lg:order-last">
                    <h2
                        class="font-['Bricolage_Grotesque'] text-[2rem] leading-[1.1] font-bold tracking-[-0.03em]"
                    >
                        Debt with an end date
                    </h2>
                    <p
                        class="mt-4 max-w-[48ch] text-[15.5px] leading-[1.65]"
                        style="color: var(--hab-ink-secondary)"
                    >
                        Payoff Plan turns balances into a finish line: what each
                        debt is costing you, what one extra payment moves, and
                        the month the last of it clears. Ledger, Budget and
                        Forecast keep that date honest.
                    </p>
                </div>

                <div class="hab-card p-5">
                    <div class="flex items-baseline justify-between">
                        <span class="text-[15px] font-semibold"
                            >Payoff Plan</span
                        >
                        <span
                            class="text-[12px]"
                            style="color: var(--hab-muted)"
                            >Debt-free Mar 2028</span
                        >
                    </div>
                    <p
                        class="mt-3 font-['Bricolage_Grotesque'] text-[32px] leading-none font-bold tracking-[-0.03em] tabular-nums"
                    >
                        ₱182,400
                    </p>
                    <p
                        class="mt-1.5 text-[13px]"
                        style="color: var(--hab-muted)"
                    >
                        left across 3 debts
                    </p>

                    <div
                        class="mt-5 h-2 w-full overflow-hidden rounded-full"
                        style="background: var(--hab-progress-track)"
                    >
                        <div
                            class="h-full rounded-full"
                            style="
                                width: 41%;
                                background: linear-gradient(
                                    90deg,
                                    var(--hab-grad-c),
                                    var(--hab-accent)
                                );
                            "
                        />
                    </div>
                    <p
                        class="mt-2.5 text-[12.5px]"
                        style="color: var(--hab-muted)"
                    >
                        <span class="tabular-nums">41%</span> cleared since you
                        started
                    </p>
                </div>
            </div>
        </section>

        <!-- Life -->
        <section class="mx-auto max-w-6xl px-6 py-14">
            <h2
                class="max-w-[24rem] font-['Bricolage_Grotesque'] text-[2rem] leading-[1.1] font-bold tracking-[-0.03em]"
            >
                The rest of the house
            </h2>
            <p
                class="mt-4 max-w-[52ch] text-[15.5px] leading-[1.65]"
                style="color: var(--hab-ink-secondary)"
            >
                Sketched in and being built one at a time — the parts of a life
                that don't fit in a calendar or a spreadsheet.
            </p>
            <ul class="mt-7 flex flex-wrap gap-2">
                <li
                    v-for="item in comingSoon"
                    :key="item"
                    class="rounded-full border px-3.5 py-1.5 text-[13.5px]"
                    style="
                        border-color: var(--hab-card-border);
                        background: var(--hab-chip-fill);
                        color: var(--hab-ink-secondary);
                    "
                >
                    {{ item }}
                </li>
            </ul>
        </section>

        <!-- Close -->
        <section class="px-6 pt-6 pb-16">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-6 rounded-2xl px-8 py-10"
                style="background: var(--hab-sidebar-bg)"
            >
                <p
                    class="max-w-[22rem] font-['Bricolage_Grotesque'] text-[1.6rem] leading-[1.15] font-bold tracking-[-0.03em] text-white"
                >
                    It's one household, and it's yours alone.
                </p>
                <Link
                    :href="$page.props.auth.user ? dashboard() : login()"
                    class="btn-lg rounded-xl font-medium"
                    :style="{
                        background: 'var(--hab-brand)',
                        color: 'var(--hab-brand-ink)',
                    }"
                >
                    {{ $page.props.auth.user ? 'Open Lexi' : 'Log in' }}
                </Link>
            </div>
        </section>

        <footer class="px-6 pb-12">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 border-t pt-6 text-[13.5px]"
                style="
                    border-color: var(--hab-card-border);
                    color: var(--hab-muted);
                "
            >
                <Link :href="terms()" class="underline underline-offset-4"
                    >Terms of service</Link
                >
                <span aria-hidden="true">&middot;</span>
                <Link :href="privacy()" class="underline underline-offset-4"
                    >Privacy policy</Link
                >
            </div>
        </footer>
    </div>
</template>

<style scoped>
.hab-card {
    background: var(--hab-card-bg);
    border: 1px solid var(--hab-card-border);
    border-radius: 16px;
}

.btn-solid,
.btn-quiet,
.btn-outline {
    display: inline-flex;
    align-items: center;
    border-radius: 10px;
    padding: 0.5rem 0.95rem;
    font-weight: 500;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease;
}

.btn-lg {
    padding: 0.7rem 1.25rem;
    font-size: 15px;
}

.btn-solid {
    background: var(--hab-btn-dark-bg);
    color: var(--hab-btn-dark-ink);
}

.btn-solid:hover {
    background: var(--hab-ink-secondary);
}

.btn-outline {
    border: 1px solid var(--hab-card-border);
    background: var(--hab-card-bg);
    color: var(--hab-ink);
}

.btn-outline:hover {
    border-color: var(--hab-muted);
}

.btn-quiet {
    color: var(--hab-ink-secondary);
}

.btn-quiet:hover {
    background: var(--hab-chip-fill);
}

:focus-visible {
    outline: 2px solid var(--hab-accent);
    outline-offset: 3px;
}

@media (prefers-reduced-motion: no-preference) {
    .nav-item {
        opacity: 0;
        transform: translateY(6px);
        animation: nav-rise 0.5s cubic-bezier(0.2, 0.7, 0.3, 1) forwards;
    }

    @keyframes nav-rise {
        to {
            opacity: 1;
            transform: none;
        }
    }
}
</style>
