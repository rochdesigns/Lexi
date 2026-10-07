<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Money/DebtController';
import AllocationPlanner from '@/components/AllocationPlanner.vue';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import DebtFormDialog from '@/components/DebtFormDialog.vue';
import type { DebtFormDebt } from '@/components/DebtFormDialog.vue';
import InfoNote from '@/components/InfoNote.vue';
import RolloffChart from '@/components/RolloffChart.vue';
import { sheetWidth } from '@/utils/blueprint';
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
        .map((type) => {
            const debts = props.debts.filter(
                (debt) => debt.type === type.value,
            );

            return {
                ...type,
                debts,
                owed: debts.reduce((sum, debt) => sum + debt.totalRemaining, 0),
            };
        })
        .filter((group) => group.debts.length > 0)
        // Section A is the roll-off chart, so the groups start at B.
        .map((group, index) => ({
            ...group,
            letter: String.fromCharCode(66 + index),
        })),
);

const activeCount = computed(
    () => props.debts.filter((debt) => debt.status !== 'paid_off').length,
);
const paidOffCount = computed(() => props.debts.length - activeCount.value);

const dimension = computed(() => {
    if (props.debts.length === 0) {
        return null;
    }

    return paidOffCount.value > 0
        ? `${activeCount.value} active · ${paidOffCount.value} paid off`
        : `${activeCount.value} active`;
});

function daysUntil(date: string): number {
    const [year, month, day] = date.split('-').map(Number);
    const today = new Date();
    const start = new Date(
        today.getFullYear(),
        today.getMonth(),
        today.getDate(),
    );

    return Math.round(
        (new Date(year, month - 1, day).getTime() - start.getTime()) /
            86_400_000,
    );
}

/** The active debt whose next payment falls soonest. */
const nextDue = computed(() => {
    const upcoming = props.debts
        .filter((debt) => debt.status !== 'paid_off' && debt.nextDueDate)
        .sort((a, b) => (a.nextDueDate! < b.nextDueDate! ? -1 : 1));

    return upcoming[0] ?? null;
});

function inDays(date: string): string {
    const days = daysUntil(date);

    if (days < 0) {
        return `${-days} ${days === -1 ? 'day' : 'days'} ago`;
    }

    if (days === 0) {
        return 'today';
    }

    return days === 1 ? 'tomorrow' : `in ${days} days`;
}

function shortDay(date: string): string {
    return formatDay(date).toUpperCase();
}

/** This month's amount is covered by payments already made. */
function isPaidThisMonth(debt: DebtSummary): boolean {
    return (
        debt.status !== 'paid_off' &&
        debt.dueThisMonth > 0 &&
        debt.paidThisMonth >= debt.dueThisMonth
    );
}

const paidChip = `PAID ${new Date().toLocaleDateString('en-US', { month: 'short' }).toUpperCase()}`;

function figures(debt: DebtSummary): { label: string; value: number }[] {
    if (debt.status === 'paid_off') {
        return [{ label: 'Still owed', value: debt.totalRemaining }];
    }

    const list = [
        debt.card
            ? {
                  label: 'Projected statement',
                  value: debt.card.projectedStatement,
              }
            : { label: 'Due this month', value: debt.dueThisMonth },
    ];

    if (debt.card && debt.card.minimumDue !== null) {
        list.push({ label: 'Minimum due', value: debt.card.minimumDue });
    }

    list.push({ label: 'Still owed', value: debt.totalRemaining });

    return list;
}

const cardMinimumsTotal = computed(() =>
    props.debts
        .filter((debt) => debt.status !== 'paid_off')
        .reduce((sum, debt) => sum + (debt.card?.minimumDue ?? 0), 0),
);

const hasCards = computed(() =>
    props.debts.some((debt) => debt.card && debt.status !== 'paid_off'),
);

const totalRemaining = computed(() =>
    props.debts.reduce((sum, debt) => sum + debt.totalRemaining, 0),
);

function utilizationPct(debt: DebtSummary): number {
    return Math.round((debt.card?.utilization ?? 0) * 100);
}

function utilizationColor(debt: DebtSummary): string {
    const pct = utilizationPct(debt);

    return pct >= 80 ? '#a3311f' : pct >= 50 ? '#b4532a' : 'var(--bp-accent)';
}
</script>

<template>
    <Head title="Debts" />

    <Teleport defer to="#page-actions">
        <button
            type="button"
            class="bp flex items-center gap-1.5 rounded bg-[var(--bp-ink)] px-3 py-1.5 text-[13px] font-semibold text-[var(--bp-card)]"
            @click="adding = true"
        >
            <span class="ms" style="font-size: 16px" aria-hidden="true"
                >add</span
            >
            <span class="hidden sm:inline">Add debt</span>
        </button>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            icon="credit_card"
            code="M-02"
            section="Money"
            title="Debts"
            subtitle="Everything you owe, and when each payment runs out"
            :dimension="dimension"
        />

        <div
            class="grid grid-cols-1 items-start gap-10"
            :class="[
                sheetWidth.wide,
                { 'lg:grid-cols-[minmax(0,1fr)_340px]': hasCards },
            ]"
        >
            <div class="min-w-0">
                <!-- Summary strip -->
                <div
                    class="mb-3.5 grid grid-cols-1 border border-[var(--bp-ink)] bg-[var(--bp-card)] sm:grid-cols-3"
                >
                    <div class="px-[18px] py-3.5">
                        <p class="bp-caption">Due this month</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ formatPeso(monthlyTotal) }}
                        </p>
                    </div>
                    <div
                        class="border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:border-t-0 sm:border-l"
                    >
                        <p class="bp-caption">Total still owed</p>
                        <p
                            class="mt-1 text-[26px] font-semibold tracking-[-0.02em] tabular-nums"
                        >
                            {{ formatPeso(totalRemaining) }}
                        </p>
                    </div>
                    <div
                        class="bp-grid-fine border-t border-[var(--bp-ink)] px-[18px] py-3.5 sm:border-t-0 sm:border-l"
                    >
                        <p class="bp-caption">Next due</p>
                        <template v-if="nextDue">
                            <p
                                class="mt-1.5 truncate text-[15px] font-semibold"
                            >
                                {{ nextDue.name }}
                            </p>
                            <p
                                class="bp-mono text-[12px] text-[var(--bp-muted)]"
                            >
                                {{ formatDay(nextDue.nextDueDate!) }} ·
                                {{ inDays(nextDue.nextDueDate!) }}
                            </p>
                        </template>
                        <p
                            v-else
                            class="mt-1.5 text-[13px] text-[var(--bp-muted)]"
                        >
                            Nothing due
                        </p>
                    </div>
                </div>
                <InfoNote id="debts-feeds" blueprint class="mb-8">
                    What is due this month is the Debt payments number on the
                    Overview, and each due date shows on the calendar and in the
                    shortfall warnings. Card figures come from the statement you
                    last entered.
                </InfoNote>

                <!-- A. Roll-off -->
                <BlueprintSectionHeading
                    letter="A"
                    title="Monthly payments rolling off"
                >
                    <span
                        class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                        aria-hidden="true"
                        >FIG. 1</span
                    >
                </BlueprintSectionHeading>
                <RolloffChart :series="rollOff" class="mt-4" />

                <div
                    v-if="debts.length === 0"
                    class="mt-11 border border-dashed border-[var(--bp-faint)] px-6 py-10 text-center text-[14px] text-[var(--bp-muted)]"
                >
                    No debts yet. Add a card, loan or anything you owe to start
                    tracking it.
                </div>

                <!-- B… One section per kind of debt -->
                <section v-for="group in groups" :key="group.value">
                    <BlueprintSectionHeading
                        :letter="group.letter"
                        :title="group.label"
                        class="mt-11"
                    >
                        <span
                            class="bp-mono text-[11px] tracking-[0.1em] text-[var(--bp-faint)]"
                            >{{ formatPeso(group.owed) }} OWED</span
                        >
                    </BlueprintSectionHeading>

                    <div class="mt-3.5 grid grid-cols-1 gap-3.5 md:grid-cols-2">
                        <Link
                            v-for="debt in group.debts"
                            :key="debt.id"
                            :href="show(debt.id).url"
                            class="relative block border border-[var(--bp-line)] bg-[var(--bp-card)] px-[18px] py-4 transition-colors hover:border-[var(--bp-ink)]"
                            :class="{
                                'opacity-55': debt.status === 'paid_off',
                            }"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3
                                        class="text-[16px] font-semibold tracking-[-0.01em]"
                                    >
                                        {{ debt.name }}
                                    </h3>
                                    <p
                                        class="text-[12.5px] text-[var(--bp-muted)]"
                                    >
                                        {{
                                            debt.lender
                                                ? debt.lender + ' · '
                                                : ''
                                        }}{{
                                            debt.status === 'paid_off'
                                                ? 'Paid off'
                                                : interestLabel(
                                                      debt.interestType,
                                                  )
                                        }}
                                    </p>
                                </div>
                                <div
                                    v-if="debt.status !== 'paid_off'"
                                    class="bp-mono shrink-0 text-right text-[11px] leading-normal"
                                >
                                    <p
                                        v-if="debt.nextDueDate"
                                        class="text-[var(--bp-ink-2)]"
                                    >
                                        DUE {{ shortDay(debt.nextDueDate) }}
                                    </p>
                                    <p
                                        v-if="debt.card"
                                        class="text-[var(--bp-faint)]"
                                    >
                                        CLOSES
                                        {{ shortDay(debt.card.closesOn) }}
                                    </p>
                                    <span
                                        v-if="isPaidThisMonth(debt)"
                                        class="mt-0.5 inline-block rounded-sm border border-[var(--bp-accent)] px-1.5 py-px text-[10px] tracking-[0.08em] text-[var(--bp-accent)]"
                                        >{{ paidChip }}</span
                                    >
                                </div>
                            </div>

                            <div
                                class="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(100px,1fr))] gap-3 border-t border-[var(--bp-hair)] pt-3"
                            >
                                <div
                                    v-for="figure in figures(debt)"
                                    :key="figure.label"
                                >
                                    <p
                                        class="text-[11.5px] text-[var(--bp-muted)]"
                                    >
                                        {{ figure.label }}
                                    </p>
                                    <p
                                        class="bp-mono mt-0.5 text-[16px] font-semibold [overflow-wrap:anywhere]"
                                    >
                                        {{ formatPeso(figure.value) }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="
                                    debt.card &&
                                    debt.card.creditLimit > 0 &&
                                    debt.status !== 'paid_off'
                                "
                                class="mt-3.5"
                            >
                                <div
                                    class="flex justify-between text-[12px] text-[var(--bp-muted)]"
                                >
                                    <span
                                        >{{ utilizationPct(debt) }}% of limit
                                        used</span
                                    >
                                    <span class="bp-mono">{{
                                        formatPeso(debt.card.creditLimit)
                                    }}</span>
                                </div>
                                <div
                                    class="mt-[5px] h-2 border border-[var(--bp-line)] bg-[var(--bp-paper)] bg-[linear-gradient(90deg,var(--bp-line)_1px,transparent_1px)] bg-[length:10%_100%]"
                                    role="progressbar"
                                    :aria-valuenow="utilizationPct(debt)"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    :aria-label="`${debt.name} limit used`"
                                >
                                    <div
                                        class="h-full"
                                        :style="{
                                            width:
                                                Math.min(
                                                    utilizationPct(debt),
                                                    100,
                                                ) + '%',
                                            background: utilizationColor(debt),
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </Link>
                    </div>
                </section>
            </div>

            <AllocationPlanner
                v-if="hasCards"
                :minimums-total="cardMinimumsTotal"
                class="lg:sticky lg:top-[76px]"
            />
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock drawing="Money / Debts" sheet="M-02" />
        </div>

        <DebtFormDialog v-model:open="adding" />
    </div>
</template>
