<script setup lang="ts">
type Account = { id: number; name: string; institution: string | null; last4: string | null; balance: string; type: string };
type AccountGroup = { type: string; label: string; subtotal: number; accounts: Account[] };

defineProps<{
    netWorth: number;
    groups: AccountGroup[];
    cycleLabel: string;
}>();
</script>

<template>
    <div class="flex h-64 flex-col items-center justify-center gap-2">
        <p class="text-[var(--hab-muted)] text-sm">Accounts — coming soon</p>
    </div>
</template>
