<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, watchEffect } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const theme = computed(() => (page.props.auth?.user as any)?.theme ?? 'ocean');

watchEffect(() => {
    document.body.dataset.theme = theme.value;
});

onUnmounted(() => {
    delete document.body.dataset.theme;
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="overflow-x-hidden bg-[var(--bp-paper)] bg-(image:--bp-noise)"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
