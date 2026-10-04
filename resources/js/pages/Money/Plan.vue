<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { update } from '@/actions/App/Http/Controllers/Money/GoalController';
import GoalDialog from '@/components/GoalDialog.vue';
import type { GoalRow } from '@/components/GoalDialog.vue';
import { Button } from '@/components/ui/button';
import WhatIfSimulator from '@/components/WhatIfSimulator.vue';
import { overview } from '@/routes/money';
import {
    formatDay,
    formatMonth,
    formatPeso,
    monthNames,
    toPesos,
} from '@/utils/money';

defineProps<{
    obligations: number;
    buffer: GoalRow | null;
    goals: GoalRow[];
    yearlyBills: {
        id: number;
        name: string;
        amount: number;
        dueMonth: number | null;
        monthlySetAside: number;
    }[];
    accounts: { id: number; name: string; isSpendable: boolean }[];
    bufferMonthOptions: number[];
    cards: { id: number; name: string; revolvingBalance: number }[];
    hasPlans: boolean;
}>();

const dialogOpen = ref(false);
const dialogKind = ref<'buffer' | 'sinking'>('sinking');
const editing = ref<GoalRow | null>(null);

function openDialog(kind: 'buffer' | 'sinking', goal: GoalRow | null): void {
    dialogKind.value = kind;
    editing.value = goal;
    dialogOpen.value = true;
}

/** Puts in the suggested monthly amount, keeping everything else as it is. */
function useSuggestion(goal: GoalRow): void {
    router.patch(
        update(goal.id).url,
        {
            kind: goal.kind,
            name: goal.name,
            monthly_contribution: toPesos(goal.suggestedContribution ?? 0),
            saved: goal.accountId ? null : toPesos(goal.saved),
            account_id: goal.accountId,
            target_amount: toPesos(goal.targetAmount ?? 0),
            target_date: goal.targetDate,
        },
        { preserveScroll: true },
    );
}

function eta(goal: GoalRow): string {
    if (goal.monthsToGo === 0) {
        return 'Reached';
    }

    if (goal.monthsToGo === null) {
        return 'Set a monthly amount to see when you will get there';
    }

    return `About ${goal.monthsToGo} ${goal.monthsToGo === 1 ? 'month' : 'months'} to go, by ${formatMonth(goal.finishMonth ?? '')}`;
}

const cardStyle =
    'background: var(--hab-card-bg); border: 1px solid var(--hab-card-border);';
</script>

<template>
    <Head title="Plan" />

    <div
        class="mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
    >
        <div class="mb-6">
            <h1
                class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                style="color: var(--hab-ink)"
            >
                Plan
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                Your safety net and what you are saving for
            </p>
        </div>

        <section class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Emergency buffer
                </h2>
                <Button
                    v-if="buffer"
                    variant="outline"
                    size="sm"
                    @click="openDialog('buffer', buffer)"
                    >Edit</Button
                >
                <Button v-else size="sm" @click="openDialog('buffer', null)"
                    >Set up</Button
                >
            </div>

            <p
                v-if="!buffer"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                A buffer holds a few months of your bills and debt payments, so
                a bad month does not turn into debt. Your obligations come to
                {{ formatPeso(obligations) }} a month right now.
            </p>
            <template v-else>
                <p
                    class="font-['Bricolage_Grotesque'] text-[26px] font-extrabold tabular-nums"
                    style="color: var(--hab-ink)"
                >
                    {{ formatPeso(buffer.saved) }}
                    <span
                        class="text-[15px] font-semibold"
                        style="color: var(--hab-muted)"
                        >of {{ formatPeso(buffer.target) }}</span
                    >
                </p>
                <div
                    class="my-2 h-2 overflow-hidden rounded-full"
                    style="background: var(--hab-progress-track)"
                    role="progressbar"
                    :aria-valuenow="buffer.percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full"
                        :style="{
                            width: buffer.percent + '%',
                            background: 'var(--hab-accent)',
                        }"
                    ></div>
                </div>
                <p class="text-[13px]" style="color: var(--hab-muted)">
                    {{ buffer.bufferMonths }} months of
                    {{ formatPeso(obligations) }}, which changes as installments
                    end. {{ formatPeso(buffer.monthlyContribution) }} a month.
                    {{ eta(buffer) }}.
                    <template v-if="buffer.accountName">
                        Using the balance of {{ buffer.accountName }}.</template
                    >
                </p>
            </template>
        </section>

        <section class="mb-6 rounded-[22px] p-6" :style="cardStyle">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Goals
                </h2>
                <Button
                    variant="outline"
                    size="sm"
                    @click="openDialog('sinking', null)"
                    >Add goal</Button
                >
            </div>
            <p
                v-if="goals.length === 0"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                Save a little each month for things you know are coming, like a
                trip, gifts or a repair.
            </p>
            <ul v-else class="flex flex-col">
                <li
                    v-for="(goal, index) in goals"
                    :key="goal.id"
                    class="py-3"
                    :style="
                        index > 0
                            ? 'border-top: 1px solid var(--hab-hairline)'
                            : ''
                    "
                >
                    <button
                        type="button"
                        class="block w-full text-left"
                        @click="openDialog('sinking', goal)"
                    >
                        <span class="flex items-baseline justify-between gap-3">
                            <span
                                class="truncate text-[14px] font-semibold"
                                style="color: var(--hab-ink)"
                                >{{ goal.name }}</span
                            >
                            <span
                                class="shrink-0 text-[13px] tabular-nums"
                                style="color: var(--hab-muted)"
                            >
                                {{ formatPeso(goal.saved) }} of
                                {{ formatPeso(goal.target) }}
                            </span>
                        </span>
                        <span
                            class="my-1.5 block h-1.5 overflow-hidden rounded-full"
                            style="background: var(--hab-progress-track)"
                            role="progressbar"
                            :aria-valuenow="goal.percent"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <span
                                class="block h-full rounded-full"
                                :style="{
                                    width: goal.percent + '%',
                                    background: 'var(--hab-accent)',
                                }"
                            ></span>
                        </span>
                        <span
                            class="block text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ formatPeso(goal.monthlyContribution) }} a month.
                            {{ eta(goal) }}.
                            <template v-if="goal.targetDate">
                                Needed by
                                {{ formatDay(goal.targetDate) }}.</template
                            >
                        </span>
                    </button>
                    <p
                        v-if="
                            goal.suggestedContribution !== null &&
                            goal.suggestedContribution !==
                                goal.monthlyContribution
                        "
                        class="mt-1 text-[12.5px]"
                        style="color: var(--hab-ink)"
                    >
                        To have it by {{ formatDay(goal.targetDate ?? '') }},
                        put in {{ formatPeso(goal.suggestedContribution) }} a
                        month.
                        <button
                            type="button"
                            class="font-semibold underline"
                            @click="useSuggestion(goal)"
                        >
                            Use this
                        </button>
                    </p>
                </li>
            </ul>
        </section>

        <section class="rounded-[22px] p-6" :style="cardStyle">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2
                    class="font-['Bricolage_Grotesque'] text-[18px] font-bold"
                    style="color: var(--hab-ink)"
                >
                    Yearly bills
                </h2>
                <Link
                    :href="overview().url"
                    class="text-[13px] font-semibold underline"
                    style="color: var(--hab-ink)"
                    >Edit in Bills</Link
                >
            </div>
            <p
                v-if="yearlyBills.length === 0"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                Yearly bills you add on the Overview are set aside a little each
                month and listed here.
            </p>
            <ul v-else>
                <li
                    v-for="(bill, index) in yearlyBills"
                    :key="bill.id"
                    class="flex items-center justify-between gap-3 py-2.5"
                    :style="
                        index > 0
                            ? 'border-top: 1px solid var(--hab-hairline)'
                            : ''
                    "
                >
                    <span class="min-w-0">
                        <span
                            class="block truncate text-[14px] font-semibold"
                            style="color: var(--hab-ink)"
                            >{{ bill.name }}</span
                        >
                        <span
                            class="block text-[12px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ formatPeso(bill.amount) }} due in
                            {{ monthNames[(bill.dueMonth ?? 1) - 1] }}
                        </span>
                    </span>
                    <span
                        class="shrink-0 text-[13px] tabular-nums"
                        style="color: var(--hab-ink)"
                    >
                        {{ formatPeso(bill.monthlySetAside) }} a month
                    </span>
                </li>
            </ul>
        </section>

        <WhatIfSimulator :cards="cards" :has-plans="hasPlans" />

        <GoalDialog
            v-model:open="dialogOpen"
            :kind="dialogKind"
            :goal="editing"
            :accounts="accounts"
            :buffer-month-options="bufferMonthOptions"
        />
    </div>
</template>
