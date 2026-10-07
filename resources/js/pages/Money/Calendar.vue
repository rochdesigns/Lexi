<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BlueprintPageHead from '@/components/BlueprintPageHead.vue';
import BlueprintSectionHeading from '@/components/BlueprintSectionHeading.vue';
import BlueprintTitleBlock from '@/components/BlueprintTitleBlock.vue';
import InfoNote from '@/components/InfoNote.vue';
import { calendar } from '@/routes/money';
import { sheetWidth } from '@/utils/blueprint';
import { formatDay, formatPeso, formatPesoShort } from '@/utils/money';

type Item = {
    kind: 'debt' | 'bill' | 'income';
    name: string;
    amount: number;
    autopay: boolean;
    sourceId: number | null;
    received: boolean;
    short: number;
};

type Gap = {
    kind: 'debt' | 'bill';
    name: string;
    amount: number;
    date: string;
    short: number;
    nextPayday: string | null;
};

const props = defineProps<{
    month: string;
    minMonth: string;
    maxMonth: string;
    today: string;
    hasAccounts: boolean;
    days: { date: string; items: Item[] }[];
    gaps: Gap[];
}>();

const kindLabel = { debt: 'Debt', bill: 'Bill', income: 'Payday' } as const;
const weekdays = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

const title = computed(() => {
    const [year, month] = props.month.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    });
});

const itemsByDate = computed(
    () => new Map(props.days.map((day) => [day.date, day.items])),
);

const cells = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    const blanks = new Date(year, month - 1, 1).getDay();
    const total = new Date(year, month, 0).getDate();
    const result: {
        date: string;
        day: number;
        items: Item[];
        isToday: boolean;
        isPast: boolean;
        hasGap: boolean;
    }[] = [];

    for (let day = 1; day <= total; day++) {
        const date = `${props.month}-${String(day).padStart(2, '0')}`;
        const items = itemsByDate.value.get(date) ?? [];

        result.push({
            date,
            day,
            items,
            isToday: date === props.today,
            isPast: date < props.today,
            hasGap: items.some((item) => item.short > 0),
        });
    }

    return { blanks, days: result };
});

const selected = ref<string | null>(null);

watch(
    () => props.month,
    () => {
        selected.value = props.today.startsWith(props.month)
            ? props.today
            : null;
    },
    { immediate: true },
);

const selectedItems = computed(() =>
    selected.value ? (itemsByDate.value.get(selected.value) ?? []) : [],
);

function shiftMonth(delta: number): string {
    const [year, month] = props.month.split('-').map(Number);
    const next = new Date(year, month - 1 + delta, 1);

    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
}

function goTo(month: string): void {
    router.get(
        calendar({ query: { month } }).url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

type ChipTone = 'income' | 'short' | 'out';

function chipTone(item: Item): ChipTone {
    if (item.short > 0) {
        return 'short';
    }

    return item.kind === 'income' ? 'income' : 'out';
}

const chipClass: Record<ChipTone, string> = {
    income: 'border-[var(--bp-accent-line)] bg-[var(--bp-tint)] text-[var(--bp-accent)]',
    short: 'border-[var(--bp-ink)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.1)_0_1px,transparent_1px_5px)] font-semibold',
    out: 'border-[#e2e0da] bg-[var(--bp-sidebar)]',
};

const dotClass: Record<ChipTone, string> = {
    income: 'bg-[var(--bp-accent)]',
    short: 'bg-[var(--bp-ink)]',
    out: 'border border-[var(--bp-muted)]',
};

/** "TODAY · TUE 06 OCT" for the selected day's panel. */
const selectedLabel = computed(() => {
    if (!selected.value) {
        return 'NO DAY PICKED';
    }

    const [year, month, day] = selected.value.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    const label = `${weekdays[date.getDay()]} ${String(day).padStart(2, '0')} ${date.toLocaleDateString('en-US', { month: 'short' }).toUpperCase()}`;

    return selected.value === props.today ? `TODAY · ${label}` : label;
});
</script>

<template>
    <Head title="Cash flow calendar" />

    <!-- The blueprint's header has search only, so hold the slot to hide "New entry". -->
    <Teleport defer to="#page-actions">
        <span aria-hidden="true"></span>
    </Teleport>

    <div class="bp pb-16">
        <BlueprintPageHead
            raised
            icon="event_upcoming"
            code="M-03"
            section="Money"
            title="Calendar"
            subtitle="Paydays and everything due, and where the cash runs short"
            dimension="Paydays · bills · debt dues"
        />

        <div
            :class="sheetWidth.wide"
            class="grid grid-cols-1 items-start gap-9 lg:grid-cols-[minmax(0,1fr)_300px]"
        >
            <div class="min-w-0">
                <p
                    v-if="!hasAccounts"
                    class="mb-4 text-[13px] text-[var(--bp-muted)]"
                >
                    Add an account on the Overview to see where the cash runs
                    short.
                </p>

                <div
                    v-if="gaps.length > 0"
                    class="mb-7 flex items-start gap-3.5 border border-[var(--bp-ink)] bg-[var(--bp-card)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.055)_0_1px,transparent_1px_8px)] px-[18px] py-3.5 text-[14px] leading-normal"
                    role="alert"
                >
                    <span
                        class="ms shrink-0"
                        style="font-size: 22px"
                        aria-hidden="true"
                        >warning</span
                    >
                    <div>
                        <p class="font-semibold">Gaps this month</p>
                        <ul class="text-[var(--bp-ink-2)]">
                            <li
                                v-for="gap in gaps"
                                :key="`${gap.name}-${gap.date}`"
                            >
                                <span class="bp-mono">{{
                                    formatPeso(gap.short)
                                }}</span>
                                short for {{ gap.name }} on
                                {{ formatDay(gap.date)
                                }}<template v-if="gap.nextPayday"
                                    >, next payday
                                    {{ formatDay(gap.nextPayday) }}</template
                                ><template v-else
                                    >, and no payday is set</template
                                >
                            </li>
                        </ul>
                    </div>
                </div>

                <BlueprintSectionHeading letter="A" :title="title">
                    <span
                        class="bp-mono text-[10.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                        aria-hidden="true"
                        >FIG. 1</span
                    >
                </BlueprintSectionHeading>
                <div
                    class="mt-3.5 border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                >
                    <div
                        class="grid grid-cols-7 border-b border-[var(--bp-ink)]"
                        aria-hidden="true"
                    >
                        <span
                            v-for="weekday in weekdays"
                            :key="weekday"
                            class="bp-mono px-1 py-[7px] text-[9px] tracking-[0.12em] text-[var(--bp-muted)] sm:px-2 sm:text-[10px]"
                            >{{ weekday }}</span
                        >
                    </div>
                    <div class="grid grid-cols-7">
                        <div
                            v-for="n in cells.blanks"
                            :key="`blank-${n}`"
                            class="min-h-16 border-r border-b border-[var(--bp-hair)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.04)_0_1px,transparent_1px_7px)] sm:min-h-[104px]"
                            aria-hidden="true"
                        ></div>
                        <button
                            v-for="cell in cells.days"
                            :key="cell.date"
                            type="button"
                            class="flex min-h-16 min-w-0 flex-col gap-[3px] border-r border-b border-[var(--bp-hair)] p-1 text-left hover:bg-[var(--bp-paper)] sm:min-h-[104px] sm:p-1.5"
                            :class="[
                                { 'opacity-50': cell.isPast },
                                cell.hasGap
                                    ? 'shadow-[inset_0_0_0_1.5px_var(--bp-ink)]'
                                    : selected === cell.date
                                      ? 'shadow-[inset_0_0_0_1.5px_var(--bp-accent)]'
                                      : '',
                            ]"
                            :aria-pressed="selected === cell.date"
                            :aria-label="`${formatDay(cell.date)}${cell.items.length ? `, ${cell.items.length} ${cell.items.length === 1 ? 'item' : 'items'}` : ''}${cell.hasGap ? ', cash runs short' : ''}`"
                            @click="selected = cell.date"
                        >
                            <span class="flex items-center justify-between">
                                <span
                                    v-if="cell.isToday"
                                    class="bp-mono flex h-5 min-w-5 items-center justify-center bg-[var(--bp-accent)] px-1 text-[11px] font-semibold text-[var(--bp-card)]"
                                    >{{ cell.day }}</span
                                >
                                <span
                                    v-else
                                    class="bp-mono text-[11px] font-medium"
                                    >{{ cell.day }}</span
                                >
                                <span
                                    v-if="cell.hasGap"
                                    class="ms"
                                    style="font-size: 14px"
                                    aria-hidden="true"
                                    >warning</span
                                >
                            </span>
                            <span
                                v-for="item in cell.items.slice(0, 2)"
                                :key="`${item.kind}-${item.name}`"
                                class="hidden truncate border px-[5px] py-px text-[10.5px] leading-normal sm:block"
                                :class="chipClass[chipTone(item)]"
                                aria-hidden="true"
                                >{{ item.received ? '✓ ' : '' }}{{ item.name }}
                                {{ formatPesoShort(item.amount) }}</span
                            >
                            <span
                                v-if="cell.items.length > 2"
                                class="hidden text-[10.5px] text-[var(--bp-muted)] sm:block"
                                aria-hidden="true"
                                >+{{ cell.items.length - 2 }} more</span
                            >
                            <span
                                v-if="cell.items.length > 0"
                                class="mt-auto flex gap-0.5 sm:hidden"
                                aria-hidden="true"
                            >
                                <span
                                    v-for="item in cell.items.slice(0, 3)"
                                    :key="`dot-${item.kind}-${item.name}`"
                                    class="box-border h-1.5 w-1.5 rotate-45"
                                    :class="dotClass[chipTone(item)]"
                                ></span>
                            </span>
                        </button>
                    </div>
                </div>
                <p
                    class="mt-3.5 text-[12px] leading-[1.55] text-pretty text-[var(--bp-muted)]"
                >
                    Card dues use the real minimum for the statement you owe
                    now. For later months only the installments are known, so
                    the real amount will be higher if you carry a balance. Gaps
                    carry forward from month to month, starting from the balance
                    of the accounts you can spend.
                </p>
            </div>

            <div class="flex flex-col gap-3.5 lg:sticky lg:top-[76px]">
                <div
                    class="flex items-center justify-between border border-[var(--bp-ink)] bg-[var(--bp-card)] px-2.5 py-2"
                >
                    <button
                        type="button"
                        class="flex h-[26px] w-[26px] items-center justify-center rounded-[3px] border border-[var(--bp-line)] enabled:hover:border-[var(--bp-ink)] disabled:border-[var(--bp-hair)] disabled:text-[var(--bp-fainter)]"
                        aria-label="Previous month"
                        :disabled="month <= minMonth"
                        @click="goTo(shiftMonth(-1))"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >chevron_left</span
                        >
                    </button>
                    <span class="flex flex-col items-center">
                        <span
                            class="bp-mono text-[9.5px] tracking-[0.12em] text-[var(--bp-faint)]"
                            >MONTH</span
                        >
                        <span class="text-[14.5px] font-semibold">{{
                            title
                        }}</span>
                        <button
                            v-if="month !== minMonth"
                            type="button"
                            class="text-[11.5px] text-[var(--bp-muted)] underline underline-offset-2 hover:text-[var(--bp-ink)]"
                            @click="goTo(minMonth)"
                        >
                            Back to this month
                        </button>
                    </span>
                    <button
                        type="button"
                        class="flex h-[26px] w-[26px] items-center justify-center rounded-[3px] border border-[var(--bp-line)] enabled:hover:border-[var(--bp-ink)] disabled:border-[var(--bp-hair)] disabled:text-[var(--bp-fainter)]"
                        aria-label="Next month"
                        :disabled="month >= maxMonth"
                        @click="goTo(shiftMonth(1))"
                    >
                        <span
                            class="ms"
                            style="font-size: 17px"
                            aria-hidden="true"
                            >chevron_right</span
                        >
                    </button>
                </div>

                <section
                    class="border border-[var(--bp-ink)] bg-[var(--bp-card)]"
                    aria-live="polite"
                >
                    <div
                        class="bp-mono flex items-center justify-between border-b border-[var(--bp-ink)] px-4 py-2.5 text-[10.5px] tracking-[0.12em]"
                    >
                        <h2>{{ selectedLabel }}</h2>
                        <span
                            v-if="selectedItems.length"
                            class="text-[var(--bp-faint)]"
                            >{{ selectedItems.length }}
                            {{
                                selectedItems.length === 1 ? 'ITEM' : 'ITEMS'
                            }}</span
                        >
                    </div>
                    <div class="px-4 pt-1 pb-3">
                        <p
                            v-if="!selected"
                            class="mt-3 mb-1 text-[13px] text-[var(--bp-muted)]"
                        >
                            Pick a day to see what is due.
                        </p>
                        <p
                            v-else-if="selectedItems.length === 0"
                            class="mt-3 mb-1 text-[13px] text-[var(--bp-muted)]"
                        >
                            Nothing due or paid this day.
                        </p>
                        <div
                            v-for="item in selectedItems"
                            :key="`${item.kind}-${item.name}`"
                            class="flex justify-between gap-2.5 border-b border-[var(--bp-hair)] py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-semibold">
                                    {{ item.name }}
                                </p>
                                <p
                                    class="text-[12px] text-[var(--bp-muted)]"
                                    :class="{
                                        'font-semibold text-[var(--bp-ink)]':
                                            item.short > 0,
                                    }"
                                >
                                    {{ kindLabel[item.kind]
                                    }}<template v-if="item.received">
                                        ·
                                        {{
                                            item.kind === 'income'
                                                ? 'received'
                                                : 'paid'
                                        }}</template
                                    ><template v-if="item.autopay">
                                        · automatic</template
                                    ><template v-if="item.short > 0">
                                        ·
                                        {{ formatPeso(item.short) }}
                                        short</template
                                    >
                                </p>
                            </div>
                            <span
                                class="bp-mono shrink-0 text-[13px] font-semibold"
                                :class="{
                                    'text-[var(--bp-accent)]':
                                        item.kind === 'income',
                                }"
                                >{{ item.kind === 'income' ? '+' : ''
                                }}{{ formatPeso(item.amount) }}</span
                            >
                        </div>
                    </div>
                </section>

                <div
                    class="flex flex-col gap-[7px] border border-[var(--bp-line)] px-3.5 py-3 text-[12px] text-[var(--bp-ink-2)]"
                >
                    <span class="flex items-center gap-2"
                        ><span
                            class="h-2.5 w-3.5 border border-[var(--bp-accent-line)] bg-[var(--bp-tint)]"
                        ></span
                        >Payday</span
                    >
                    <span class="flex items-center gap-2"
                        ><span
                            class="h-2.5 w-3.5 border border-[#e2e0da] bg-[var(--bp-sidebar)]"
                        ></span
                        >Bill or debt due</span
                    >
                    <span class="flex items-center gap-2"
                        ><span
                            class="h-2.5 w-3.5 border border-[var(--bp-ink)] bg-[repeating-linear-gradient(135deg,rgba(27,27,26,0.15)_0_1px,transparent_1px_4px)]"
                        ></span
                        >Cash runs short</span
                    >
                    <span class="flex items-center gap-2"
                        ><span class="bp-mono w-3.5 text-center text-[11px]"
                            >✓</span
                        >Received or paid</span
                    >
                </div>

                <InfoNote id="calendar-paydays" blueprint>
                    Paydays come from your income forecast and bills from your
                    bills. A pay day you mark as received shows a tick and is
                    not counted again, because the money is already in your
                    balance.
                </InfoNote>
            </div>
        </div>

        <div :class="sheetWidth.wide">
            <BlueprintTitleBlock drawing="Money / Calendar" sheet="M-03" />
        </div>
    </div>
</template>
