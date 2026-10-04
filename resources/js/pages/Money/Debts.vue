<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Money/DebtController';
import DebtFormDialog from '@/components/DebtFormDialog.vue';
import type { DebtFormDebt } from '@/components/DebtFormDialog.vue';
import RolloffChart from '@/components/RolloffChart.vue';
import { Button } from '@/components/ui/button';
import { debtTypes, formatDay, formatPeso, interestLabel } from '@/utils/money';

export type DebtSummary = DebtFormDebt & {
    totalRemaining: number;
    dueThisMonth: number;
    paidThisMonth: number;
    nextDueDate: string | null;
    card:
        | (NonNullable<DebtFormDebt['card']> & {
              closesOn: string;
              projectedStatement: number;
              minimumDue: number | null;
              utilization: number | null;
          })
        | null;
};

const props = defineProps<{
    debts: DebtSummary[];
    monthlyTotal: number;
    rollOff: { month: string; total: number }[];
}>();

const adding = ref(false);

const groups = computed(() =>
    debtTypes
        .map((type) => ({
            ...type,
            debts: props.debts.filter((debt) => debt.type === type.value),
        }))
        .filter((group) => group.debts.length > 0),
);

const totalRemaining = computed(() =>
    props.debts.reduce((sum, debt) => sum + debt.totalRemaining, 0),
);

function utilizationPct(debt: DebtSummary): number {
    return Math.round((debt.card?.utilization ?? 0) * 100);
}

function utilizationColor(debt: DebtSummary): string {
    const pct = utilizationPct(debt);

    return pct >= 80 ? '#c0392b' : pct >= 50 ? '#d98e04' : 'var(--hab-accent)';
}
</script>

<template>
    <Head title="Debts" />

    <div
        class="mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
    >
        <div class="mb-6 flex items-end justify-between">
            <div>
                <h1
                    class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                    style="color: var(--hab-ink)"
                >
                    Debts
                </h1>
                <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                    Everything you owe, and when each payment runs out
                </p>
            </div>
            <Button @click="adding = true">Add debt</Button>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div
                class="rounded-[22px] p-5"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <p class="text-[12px]" style="color: var(--hab-muted)">
                    Due this month
                </p>
                <p
                    class="font-['Bricolage_Grotesque'] text-[26px] font-extrabold tabular-nums"
                    style="color: var(--hab-ink)"
                >
                    {{ formatPeso(monthlyTotal) }}
                </p>
            </div>
            <div
                class="rounded-[22px] p-5"
                style="
                    background: var(--hab-card-bg);
                    border: 1px solid var(--hab-card-border);
                "
            >
                <p class="text-[12px]" style="color: var(--hab-muted)">
                    Total still owed
                </p>
                <p
                    class="font-['Bricolage_Grotesque'] text-[26px] font-extrabold tabular-nums"
                    style="color: var(--hab-ink)"
                >
                    {{ formatPeso(totalRemaining) }}
                </p>
            </div>
        </div>

        <div
            class="mb-6 rounded-[22px] p-6"
            style="
                background: var(--hab-card-bg);
                border: 1px solid var(--hab-card-border);
                box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
            "
        >
            <h2
                class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                style="color: var(--hab-ink)"
            >
                Monthly payments rolling off
            </h2>
            <RolloffChart :series="rollOff" />
        </div>

        <div
            v-if="debts.length === 0"
            class="rounded-[22px] p-10 text-center text-[14px]"
            style="
                background: var(--hab-card-bg);
                border: 1px solid var(--hab-card-border);
                color: var(--hab-muted);
            "
        >
            No debts yet. Add a card, loan or anything you owe to start tracking
            it.
        </div>

        <section v-for="group in groups" :key="group.value" class="mb-6">
            <h2
                class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                style="color: var(--hab-ink)"
            >
                {{ group.label }}
            </h2>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <Link
                    v-for="debt in group.debts"
                    :key="debt.id"
                    :href="show(debt.id).url"
                    class="block rounded-[22px] p-6 transition-opacity hover:opacity-90"
                    :class="{ 'opacity-60': debt.status === 'paid_off' }"
                    style="
                        background: var(--hab-card-bg);
                        border: 1px solid var(--hab-card-border);
                        box-shadow: 0 8px 24px -20px var(--hab-card-shadow);
                    "
                >
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3
                                class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                                style="color: var(--hab-ink)"
                            >
                                {{ debt.name }}
                            </h3>
                            <p
                                class="text-[12.5px]"
                                style="color: var(--hab-muted)"
                            >
                                {{ debt.lender ? debt.lender + ' · ' : ''
                                }}{{
                                    debt.status === 'paid_off'
                                        ? 'Paid off'
                                        : interestLabel(debt.interestType)
                                }}
                            </p>
                        </div>
                        <div v-if="debt.nextDueDate" class="text-right">
                            <p
                                class="text-[11.5px]"
                                style="color: var(--hab-muted)"
                            >
                                Next due {{ formatDay(debt.nextDueDate) }}
                            </p>
                            <p
                                v-if="debt.card"
                                class="text-[11.5px]"
                                style="color: var(--hab-faint)"
                            >
                                Closes {{ formatDay(debt.card.closesOn) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-x-6 gap-y-3"
                    >
                        <div>
                            <p
                                class="text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                {{
                                    debt.card
                                        ? 'Projected next statement'
                                        : 'Due this month'
                                }}
                            </p>
                            <p
                                class="font-['Bricolage_Grotesque'] text-[22px] font-extrabold [overflow-wrap:anywhere] tabular-nums"
                                style="color: var(--hab-ink)"
                            >
                                {{
                                    formatPeso(
                                        debt.card
                                            ? debt.card.projectedStatement
                                            : debt.dueThisMonth,
                                    )
                                }}
                            </p>
                        </div>
                        <div v-if="debt.card && debt.card.minimumDue !== null">
                            <p
                                class="text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                Minimum due
                            </p>
                            <p
                                class="font-['Bricolage_Grotesque'] text-[22px] font-extrabold [overflow-wrap:anywhere] tabular-nums"
                                style="color: var(--hab-ink)"
                            >
                                {{ formatPeso(debt.card.minimumDue) }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-[12px]"
                                style="color: var(--hab-muted)"
                            >
                                Still owed
                            </p>
                            <p
                                class="font-['Bricolage_Grotesque'] text-[22px] font-extrabold [overflow-wrap:anywhere] tabular-nums"
                                style="color: var(--hab-ink)"
                            >
                                {{ formatPeso(debt.totalRemaining) }}
                            </p>
                        </div>
                    </div>

                    <template v-if="debt.card && debt.card.creditLimit > 0">
                        <div
                            class="mt-4 mb-1.5 flex justify-between text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            <span
                                >{{ utilizationPct(debt) }}% of limit used</span
                            >
                            <span class="tabular-nums">{{
                                formatPeso(debt.card.creditLimit)
                            }}</span>
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full"
                            style="background: var(--hab-progress-track)"
                        >
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width:
                                        Math.min(utilizationPct(debt), 100) +
                                        '%',
                                    background: utilizationColor(debt),
                                }"
                            ></div>
                        </div>
                    </template>
                </Link>
            </div>
        </section>

        <DebtFormDialog v-model:open="adding" />
    </div>
</template>
