<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { home, login, today } from '@/routes';

const props = defineProps<{ status: number }>();

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

type Copy = { title: string; description: string };

const COPY: Record<number, Copy> = {
    403: {
        title: 'Not yours to open',
        description:
            'This belongs to another account, or you no longer have access to it.',
    },
    404: {
        title: 'Nothing here',
        description:
            'That page does not exist. It may have been renamed, or deleted.',
    },
    419: {
        title: 'Your session expired',
        description:
            'You were away a while, so we signed you out. Log back in and pick up where you left off.',
    },
    429: {
        title: 'Too many attempts',
        description: 'Give it a minute, then try again.',
    },
    500: {
        title: 'Something broke',
        description:
            'The error is on our side, not yours. It has been logged and nothing you saved was lost.',
    },
    503: {
        title: 'Down for maintenance',
        description: 'Back shortly. Nothing is lost while we work.',
    },
};

const FALLBACK: Copy = {
    title: 'Something went wrong',
    description: 'That request could not be completed.',
};

const copy = computed<Copy>(() => COPY[props.status] ?? FALLBACK);

const action = computed(() => {
    if (props.status === 419) {
        return { href: login(), label: 'Log in again' };
    }

    return isAuthenticated.value
        ? { href: today(), label: 'Back to Today' }
        : { href: home(), label: 'Go to the homepage' };
});
</script>

<template>
    <Head :title="`${status} — ${copy.title}`" />

    <main
        class="flex min-h-screen flex-col items-center justify-center bg-[var(--hab-app-bg)] px-4 py-16 text-[var(--hab-ink)]"
    >
        <div
            class="w-full max-w-md rounded-2xl border border-[var(--hab-card-border)] bg-[var(--hab-card-bg)] px-6 py-10 text-center sm:px-10"
        >
            <p
                class="font-mono text-5xl font-semibold tracking-tight text-[var(--hab-faint)]"
            >
                {{ status }}
            </p>

            <h1 class="mt-4 text-xl font-semibold tracking-tight">
                {{ copy.title }}
            </h1>

            <p class="mt-3 text-sm leading-relaxed text-[var(--hab-muted)]">
                {{ copy.description }}
            </p>

            <Link
                :href="action.href"
                class="mt-8 inline-block rounded-lg bg-[var(--hab-btn-dark-bg)] px-5 py-2.5 text-sm font-medium text-[var(--hab-btn-dark-ink)] transition-opacity hover:opacity-90"
            >
                {{ action.label }}
            </Link>
        </div>
    </main>
</template>
