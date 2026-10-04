<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    title: string;
    updatedOn: string;
}>();
</script>

<template>
    <Head :title="title" />

    <div
        class="min-h-screen bg-[var(--hab-app-bg)] px-4 py-12 text-[var(--hab-ink)] sm:px-6 sm:py-16"
    >
        <article class="mx-auto w-full max-w-2xl">
            <Link
                :href="home()"
                class="text-sm text-[var(--hab-muted)] underline underline-offset-4 hover:text-[var(--hab-ink)]"
            >
                &larr; Back to Lexi
            </Link>

            <h1 class="mt-8 text-3xl font-semibold tracking-tight sm:text-4xl">
                {{ title }}
            </h1>

            <p class="mt-2 text-sm text-[var(--hab-muted)]">
                Last updated {{ updatedOn }}
            </p>

            <div class="legal mt-10">
                <slot />
            </div>

            <footer
                class="mt-16 border-t border-[var(--hab-card-border)] pt-6 text-sm text-[var(--hab-muted)]"
            >
                <slot name="footer" />
            </footer>
        </article>
    </div>
</template>

<style scoped>
.legal :deep(h2) {
    margin-top: 2.5rem;
    font-size: 1.125rem;
    font-weight: 600;
    letter-spacing: -0.01em;
}

.legal :deep(h2:first-child) {
    margin-top: 0;
}

.legal :deep(p),
.legal :deep(ul) {
    margin-top: 0.875rem;
    font-size: 0.9375rem;
    line-height: 1.7;
    color: var(--hab-ink-secondary);
}

.legal :deep(ul) {
    padding-left: 1.25rem;
    list-style: disc;
}

.legal :deep(li) {
    margin-top: 0.375rem;
}

.legal :deep(a) {
    color: var(--hab-ink);
    text-decoration: underline;
    text-underline-offset: 4px;
}

.legal :deep(strong) {
    font-weight: 600;
    color: var(--hab-ink);
}
</style>
