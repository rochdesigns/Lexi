<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { update } from '@/actions/App/Http/Controllers/Money/GoalController';
import BillDialog from '@/components/BillDialog.vue';
import type { BillRow } from '@/components/BillDialog.vue';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import GoalDialog from '@/components/GoalDialog.vue';
import type { GoalRow } from '@/components/GoalDialog.vue';
import InfoNote from '@/components/InfoNote.vue';
import WhatIfSimulator from '@/components/WhatIfSimulator.vue';
import { sheetWidth } from '@/utils/blueprint';
import {
    formatDay,
    formatMonth,
    formatPeso,
    formatPesoShort,
    monthNames,
    toPesos,
} from '@/utils/money';

type YearlyBill = BillRow & {
    frequency: 'quarterly' | 'annual';
    monthlySetAside: number;
};

const props = defineProps<{
    obligations: number;
    buffer: GoalRow | null;
    goals: GoalRow[];
    yearlyBills: YearlyBill[];
    accounts: { id: number; name: string; isSpendable: boolean }[];
    bufferMonthOptions: number[];
    cards: { id: number; name: string; revolvingBalance: number }[];
    hasPlans: boolean;
}>();

const billOpen = ref(false);
const editingBill = ref<YearlyBill | null>(null);

function openBill(bill: YearlyBill): void {
    editingBill.value = bill;
    billOpen.value = true;
}

function openNewBill(): void {
    editingBill.value = null;
    billOpen.value = true;
}

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

/** One line under a goal's bar: what goes in, how long it takes and when it is needed. */
function goalMeta(goal: GoalRow): string {
    if (goal.monthsToGo === 0) {
        return 'Full, so it no longer takes from safe to spend';
    }

    const parts = [`${formatPeso(goal.monthlyContribution)} a month`];

    parts.push(
        goal.monthsToGo === null
            ? 'set a monthly amount to see when you will get there'
            : `about ${goal.monthsToGo} ${goal.monthsToGo === 1 ? 'month' : 'months'} to go`,
    );

    if (goal.targetDate) {
        parts.push(`needed by ${formatDay(goal.targetDate)}`);
    }

    return parts.join(' · ');
}

function hasSuggestion(goal: GoalRow): boolean {
    return (
        goal.suggestedContribution !== null &&
        goal.suggestedContribution !== goal.monthlyContribution
    );
}

/** A goal that is full stops taking money, the same rule the Overview uses. */
function activeContribution(goal: GoalRow | null): number {
    return goal && goal.remaining > 0 ? goal.monthlyContribution : 0;
}

const bufferMonthly = computed(() => activeContribution(props.buffer));
const goalsMonthly = computed(() =>
    props.goals.reduce((sum, goal) => sum + activeContribution(goal), 0),
);
const yearlyMonthly = computed(() =>
    props.yearlyBills.reduce((sum, bill) => sum + bill.monthlySetAside, 0),
);
const setAsideMonthly = computed(
    () => bufferMonthly.value + goalsMonthly.value + yearlyMonthly.value,
);

const split = computed(() => [
    { label: 'Buffer', amount: bufferMonthly.value, fill: 'var(--bp-accent)' },
    {
        label: 'Goals',
        amount: goalsMonthly.value,
        fill: 'color-mix(in oklch, var(--bp-accent) 50%, var(--bp-card))',
    },
    { label: 'Yearly', amount: yearlyMonthly.value, fill: 'transparent' },
]);

/** The buffer's target is a number of months of obligations; mark each month on the bar. */
const bufferMarks = computed(() => {
    const months = props.buffer?.bufferMonths ?? 0;

    return Array.from({ length: months }, (_, index) => ({
        months: index + 1,
        left: ((index + 1) / months) * 100,
        amount: props.obligations * (index + 1),
    }));
});

const currentMonthIndex = new Date().getMonth();

function monthCells(bill: YearlyBill): { due: boolean; now: boolean }[] {
    return monthNames.map((_, index) => ({
        due:
            bill.dueMonth !== null &&
            (index - (bill.dueMonth - 1)) %
                (bill.frequency === 'quarterly' ? 3 : 12) ===
                0,
        now: index === currentMonthIndex,
    }));
}
</script>

<template>
    <Head title="Plan" />

    <Teleport defer to="#page-actions">
        <button
            type="button"
            class="bp flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
            @click="openDialog('sinking', null)"
        >
            <span class="ms" style="font-size: 16px" aria-hidden="true"
                >add</span
            >
            <span class="hidden sm:inline">Add goal</span>
        </button>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            raised
            icon="flag"
            code="M-05"
            section="Money"
            title="Plan"
            subtitle="Your safety net and what you are saving for"
            :dimension="
                setAsideMonthly > 0
                    ? `${formatPesoShort(setAsideMonthly)} set aside each month`
                    : null
            "
        />

        <div
            :class="sheetWidth.wide"
            class="grid grid-cols-1 items-start gap-10 lg:grid-cols-[minmax(0,1fr)_340px]"
        >
            <div class="min-w-0">
                <!-- Summary strip -->
                <div
                    class="mb-8 grid grid-cols-1 border border-[var(--bp-ink)] bg-[var(--bp-card)] sm:grid-cols-3"
                >
                    <div class="px-[18px] py-3.5">
                        <p class="bp-caption">Set aside / month</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ formatPeso(setAsideMonthly) }}
                        </p>
                    </div>
                    <div
                        class="border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:border-t-0 sm:border-l"
                    >
                        <p class="bp-caption">Obligations / month</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ formatPeso(obligations) }}
                        </p>
                    </div>
                    <div
                        class="bp-grid-fine border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:border-t-0 sm:border-l"
                    >
                        <p class="bp-caption">Split</p>
                        <div
                            class="mt-2.5 flex h-3 border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                            role="img"
                            :aria-label="
                                split
                                    .map(
                                        (part) =>
                                            `${part.label} ${formatPeso(part.amount)}`,
                                    )
                                    .join(', ')
                            "
                        >
                            <template
                                v-for="(part, index) in split"
                                :key="part.label"
                            >
                                <div
                                    v-if="part.amount > 0"
                                    :class="{
                                        'border-l border-[var(--bp-ink)]':
                                            index > 0 &&
                                            split
                                                .slice(0, index)
                                                .some((p) => p.amount > 0),
                                    }"
                                    :style="{
                                        flexGrow: part.amount,
                                        background: part.fill,
                                    }"
                                ></div>
                            </template>
                        </div>
                        <div
                            class="bp-mono mt-1.5 flex justify-between text-[10px] text-[var(--bp-muted)] uppercase"
                        >
                            <span v-for="part in split" :key="part.label">{{
                                part.label
                            }}</span>
                        </div>
                    </div>
                </div>

                <!-- A. Emergency buffer -->
                <BlueprintSectionHeading letter="A" title="Emergency buffer">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded border border-[var(--bp-line)] bg-[var(--bp-card)] px-2.5 py-[5px] text-[13px] font-medium hover:border-[var(--bp-ink)]"
                        @click="openDialog('buffer', buffer)"
                    >
                        <span
                            class="ms"
                            style="font-size: 16px"
                            aria-hidden="true"
                            >{{ buffer ? 'edit' : 'add' }}</span
                        >
                        {{ buffer ? 'Edit' : 'Set up' }}
                    </button>
                </BlueprintSectionHeading>

                <div
                    class="relative mt-4 border border-[var(--bp-line)] bg-[var(--bp-card)] px-6 py-[22px]"
                >
                    <p
                        v-if="!buffer"
                        class="text-[14px] text-[var(--bp-ink-2)]"
                    >
                        A buffer holds a few months of your bills and debt
                        payments, so a bad month does not turn into debt. Your
                        obligations come to
                        <span class="bp-mono">{{
                            formatPeso(obligations)
                        }}</span>
                        a month right now.
                    </p>
                    <template v-else>
                        <div class="flex flex-wrap items-baseline gap-x-2.5">
                            <span
                                class="text-[34px] font-semibold tracking-[-0.03em] tabular-nums"
                                >{{ formatPeso(buffer.saved) }}</span
                            >
                            <span class="text-[15px] text-[var(--bp-muted)]"
                                >of {{ formatPeso(buffer.target) }}</span
                            >
                            <span
                                class="bp-mono ml-auto text-[13px] font-semibold text-[var(--bp-accent)]"
                                >{{ buffer.percent }}%</span
                            >
                        </div>
                        <div
                            class="relative mt-4 h-[18px] border border-[var(--bp-ink)] bg-[var(--bp-paper)]"
                            role="progressbar"
                            :aria-valuenow="buffer.percent"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="Emergency buffer saved"
                        >
                            <div
                                class="hab-progress-fill h-full bg-[var(--bp-accent)]"
                                :style="{ width: buffer.percent + '%' }"
                            ></div>
                            <div
                                v-for="mark in bufferMarks.slice(0, -1)"
                                :key="mark.months"
                                class="absolute -top-[5px] -bottom-[5px] w-px bg-[var(--bp-ink)]"
                                :style="{ left: mark.left + '%' }"
                            ></div>
                        </div>
                        <div
                            class="bp-mono mt-1.5 hidden text-[10.5px] text-[var(--bp-muted)] uppercase sm:grid"
                            :style="{
                                gridTemplateColumns: `repeat(${bufferMarks.length}, minmax(0, 1fr))`,
                            }"
                        >
                            <span
                                v-for="mark in bufferMarks"
                                :key="mark.months"
                                class="pr-1.5 text-right last:pr-0"
                            >
                                {{ mark.months }}
                                {{ mark.months === 1 ? 'month' : 'months' }} ·
                                {{ formatPesoShort(mark.amount) }}
                            </span>
                        </div>
                        <dl
                            class="mt-[18px] grid grid-cols-[120px_minmax(0,1fr)] gap-y-1 border-t border-[var(--bp-hair)] pt-3.5 text-[13.5px] sm:grid-cols-[170px_minmax(0,1fr)]"
                        >
                            <dt class="text-[var(--bp-muted)]">Each month</dt>
                            <dd class="bp-mono">
                                {{ formatPeso(buffer.monthlyContribution) }}
                            </dd>
                            <dt class="text-[var(--bp-muted)]">Gets there</dt>
                            <dd>{{ eta(buffer) }}</dd>
                            <dt class="text-[var(--bp-muted)]">Target</dt>
                            <dd>
                                {{ buffer.bufferMonths }} months of obligations,
                                so it shrinks as installments end
                            </dd>
                            <dt class="text-[var(--bp-muted)]">Kept in</dt>
                            <dd v-if="buffer.accountName">
                                {{ buffer.accountName }}
                                <span class="text-[var(--bp-faint)]"
                                    >· uses its balance</span
                                >
                            </dd>
                            <dd v-else class="text-[var(--bp-muted)]">
                                Entered by hand
                            </dd>
                        </dl>
                    </template>
                </div>
                <InfoNote id="plan-buffer" blueprint class="mt-3.5">
                    What you put in each month comes off safe to spend on the
                    Overview until the buffer is full. The target follows your
                    obligations, so it shrinks as installments end.
                </InfoNote>

                <!-- B. Goals -->
                <BlueprintSectionHeading letter="B" title="Goals" class="mt-11">
                    <span
                        v-if="goals.length"
                        class="bp-mono text-[11px] tracking-[0.1em] text-[var(--bp-faint)]"
                        >{{ formatPeso(goalsMonthly) }} / MO</span
                    >
                </BlueprintSectionHeading>
                <InfoNote id="plan-goals" blueprint class="mt-3">
                    The monthly amounts are part of the set-aside on the
                    Overview. A goal that is full stops taking money from safe
                    to spend.
                </InfoNote>
                <div class="mt-3 border-t border-[var(--bp-ink)]">
                    <p
                        v-if="goals.length === 0"
                        class="border-b border-[var(--bp-hair)] py-3.5 text-[14px] text-[var(--bp-muted)]"
                    >
                        Save a little each month for things you know are coming,
                        like a trip, gifts or a repair.
                    </p>
                    <div
                        v-for="goal in goals"
                        :key="goal.id"
                        class="border-b border-[var(--bp-hair)] py-3.5"
                    >
                        <button
                            type="button"
                            class="block w-full text-left"
                            @click="openDialog('sinking', goal)"
                        >
                            <span
                                class="flex items-baseline justify-between gap-3"
                            >
                                <span
                                    class="truncate text-[15px] font-semibold"
                                    >{{ goal.name }}</span
                                >
                                <span class="bp-mono shrink-0 text-[13px]">
                                    {{ formatPeso(goal.saved) }}
                                    <span class="text-[var(--bp-faint)]"
                                        >/ {{ formatPeso(goal.target) }}</span
                                    >
                                </span>
                            </span>
                            <span
                                class="mt-2 block h-2 border border-[var(--bp-line)] bg-[var(--bp-paper)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
                                role="progressbar"
                                :aria-valuenow="goal.percent"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                :aria-label="`${goal.name} saved`"
                            >
                                <span
                                    class="hab-progress-fill block h-full bg-[var(--bp-accent)]"
                                    :style="{ width: goal.percent + '%' }"
                                ></span>
                            </span>
                            <span
                                class="mt-1.5 flex justify-between gap-3 text-[12.5px] text-[var(--bp-muted)]"
                            >
                                <span>{{ goalMeta(goal) }}</span>
                                <span
                                    v-if="goal.monthsToGo === 0"
                                    class="bp-mono shrink-0 self-start rounded-sm border border-[var(--bp-accent)] px-1.5 py-px text-[10.5px] tracking-[0.08em] text-[var(--bp-accent)]"
                                    >REACHED</span
                                >
                            </span>
                        </button>
                        <div
                            v-if="hasSuggestion(goal)"
                            class="mt-2 flex items-center gap-2.5 border border-dashed border-[var(--bp-faint)] px-3 py-2 text-[12.5px]"
                        >
                            <span
                                class="ms shrink-0 text-[var(--bp-accent)]"
                                style="font-size: 16px"
                                aria-hidden="true"
                                >tips_and_updates</span
                            >
                            <span class="flex-1">
                                To have it by
                                {{ formatDay(goal.targetDate ?? '') }}, put in
                                {{
                                    formatPeso(goal.suggestedContribution ?? 0)
                                }}
                                a month.
                            </span>
                            <button
                                type="button"
                                class="shrink-0 font-semibold underline underline-offset-[3px]"
                                @click="useSuggestion(goal)"
                            >
                                Use this
                            </button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 py-2.5 text-[13.5px] text-[var(--bp-faint)] hover:text-[var(--bp-ink)]"
                        @click="openDialog('sinking', null)"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >add</span
                        >
                        Add goal
                    </button>
                </div>

                <!-- C. Yearly bills -->
                <BlueprintSectionHeading
                    letter="C"
                    title="Yearly and quarterly bills"
                    class="mt-11"
                />
                <div class="mt-3 border-t border-[var(--bp-ink)]">
                    <p
                        v-if="yearlyBills.length === 0"
                        class="border-b border-[var(--bp-hair)] py-3.5 text-[14px] text-[var(--bp-muted)]"
                    >
                        Yearly and quarterly bills you add are set aside a
                        little each month and listed here.
                    </p>
                    <template v-else>
                        <button
                            v-for="bill in yearlyBills"
                            :key="bill.id"
                            type="button"
                            class="grid w-full cursor-pointer grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 border-b border-[var(--bp-hair)] py-3 text-left hover:bg-[var(--bp-soft)] sm:grid-cols-[minmax(0,1fr)_220px_130px]"
                            @click="openBill(bill)"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-[14px] font-semibold">
                                    {{ bill.name }}
                                </p>
                                <p class="text-[12px] text-[var(--bp-muted)]">
                                    {{ formatPeso(bill.amount) }}
                                    {{
                                        bill.frequency === 'quarterly'
                                            ? `every 3 months from ${monthNames[(bill.dueMonth ?? 1) - 1]}`
                                            : `due in ${monthNames[(bill.dueMonth ?? 1) - 1]}`
                                    }}
                                </p>
                            </div>
                            <div
                                class="col-span-2 row-start-2 grid grid-cols-12 gap-0.5 sm:col-span-1 sm:row-start-auto"
                                aria-hidden="true"
                            >
                                <div
                                    v-for="(cell, index) in monthCells(bill)"
                                    :key="index"
                                    class="box-border h-3.5"
                                    :class="
                                        cell.due
                                            ? 'bg-[var(--bp-ink)]'
                                            : cell.now
                                              ? 'border border-[var(--bp-accent)] bg-[var(--bp-soft)]'
                                              : 'border border-[var(--bp-line)]'
                                    "
                                ></div>
                            </div>
                            <span
                                class="bp-mono text-right text-[13px] whitespace-nowrap"
                            >
                                {{ formatPeso(bill.monthlySetAside) }}
                                <span class="text-[var(--bp-faint)]">/ mo</span>
                            </span>
                        </button>
                        <div
                            class="bp-mono mt-2 flex gap-4 text-[10px] text-[var(--bp-faint)]"
                        >
                            <span class="flex items-center gap-[5px]"
                                ><span
                                    class="h-2.5 w-2.5 bg-[var(--bp-ink)]"
                                ></span
                                >DUE</span
                            >
                            <span class="flex items-center gap-[5px]"
                                ><span
                                    class="box-border h-2.5 w-2.5 border border-[var(--bp-accent)] bg-[var(--bp-soft)]"
                                ></span
                                >THIS MONTH</span
                            >
                            <span>JAN → DEC</span>
                        </div>
                    </template>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 py-2.5 text-[13.5px] text-[var(--bp-faint)] hover:text-[var(--bp-ink)]"
                        @click="openNewBill"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >add</span
                        >
                        Add bill
                    </button>
                </div>
            </div>

            <WhatIfSimulator
                :cards="cards"
                :has-plans="hasPlans"
                class="lg:sticky lg:top-[76px]"
            />
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock drawing="Money / Plan" sheet="M-05" />
        </div>

        <BillDialog
            v-model:open="billOpen"
            :bill="editingBill"
            default-frequency="annual"
            :accounts="accounts"
            :cards="cards"
        />
        <GoalDialog
            v-model:open="dialogOpen"
            :kind="dialogKind"
            :goal="editing"
            :accounts="accounts"
            :buffer-month-options="bufferMonthOptions"
        />
    </div>
</template>
