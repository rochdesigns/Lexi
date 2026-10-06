<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InfoNote from '@/components/InfoNote.vue';
import { Button } from '@/components/ui/button';
import { calendar } from '@/routes/money';
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
const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

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

function chipStyle(item: Item): string {
    if (item.short > 0) {
        return 'background: #fdecea; color: #8f2a1f';
    }

    return item.kind === 'income'
        ? 'background: color-mix(in srgb, var(--hab-accent) 16%, transparent); color: var(--hab-accent)'
        : 'background: var(--hab-hairline); color: var(--hab-ink)';
}

const cardStyle =
    'background: var(--hab-card-bg); border: 1px solid var(--hab-card-border);';
</script>

<template>
    <Head title="Cash flow calendar" />

    <div
        class="mx-auto w-full max-w-[1180px] px-4 pt-6 pb-[46px] sm:px-6 lg:px-[30px]"
    >
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold"
                    style="color: var(--hab-ink)"
                >
                    Calendar
                </h1>
                <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                    Paydays and everything due, and where the cash runs short
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="month <= minMonth"
                    aria-label="Previous month"
                    @click="goTo(shiftMonth(-1))"
                    >‹</Button
                >
                <span
                    class="min-w-[130px] text-center text-[15px] font-semibold"
                    style="color: var(--hab-ink)"
                    >{{ title }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="month >= maxMonth"
                    aria-label="Next month"
                    @click="goTo(shiftMonth(1))"
                    >›</Button
                >
                <Button
                    v-if="month !== minMonth"
                    variant="ghost"
                    size="sm"
                    @click="goTo(minMonth)"
                    >This month</Button
                >
            </div>
        </div>

        <InfoNote id="calendar-paydays">
            Paydays come from your income forecast and bills from your bills. A
            pay day you mark as received shows a tick and is not counted again,
            because the money is already in your balance.
        </InfoNote>

        <p
            v-if="!hasAccounts"
            class="mb-4 text-[13px]"
            style="color: var(--hab-muted)"
        >
            Add an account on the Overview to see where the cash runs short.
        </p>

        <div
            v-if="gaps.length > 0"
            class="mb-6 rounded-[16px] px-5 py-4 text-[14px]"
            style="
                background: #fdecea;
                border: 1px solid #f5c6c0;
                color: #8f2a1f;
            "
            role="alert"
        >
            <p class="mb-1 font-semibold">Gaps this month</p>
            <ul class="flex flex-col gap-0.5">
                <li v-for="gap in gaps" :key="`${gap.name}-${gap.date}`">
                    {{ formatPeso(gap.short) }} short for {{ gap.name }} on
                    {{ formatDay(gap.date)
                    }}<template v-if="gap.nextPayday">
                        , next payday {{ formatDay(gap.nextPayday) }}</template
                    ><template v-else>, and no payday is set</template>
                </li>
            </ul>
        </div>

        <div class="mb-6 rounded-[22px] p-3 sm:p-5" :style="cardStyle">
            <div class="mb-1 grid grid-cols-7 gap-1">
                <div
                    v-for="weekday in weekdays"
                    :key="weekday"
                    class="py-1 text-center text-[11.5px] font-semibold"
                    style="color: var(--hab-muted)"
                >
                    {{ weekday }}
                </div>
            </div>
            <div class="grid grid-cols-7 gap-1">
                <div v-for="n in cells.blanks" :key="`blank-${n}`"></div>
                <button
                    v-for="cell in cells.days"
                    :key="cell.date"
                    type="button"
                    class="flex min-h-[64px] flex-col items-stretch gap-0.5 rounded-[10px] p-1 text-left sm:min-h-[92px] sm:p-1.5"
                    :class="{ 'opacity-45': cell.isPast }"
                    :style="{
                        border: cell.hasGap
                            ? '1.5px solid #c0392b'
                            : selected === cell.date
                              ? '1.5px solid var(--hab-accent)'
                              : '1px solid var(--hab-hairline)',
                    }"
                    @click="selected = cell.date"
                >
                    <span
                        class="text-[12px] font-semibold"
                        :style="{
                            color: cell.isToday
                                ? 'var(--hab-accent)'
                                : 'var(--hab-ink)',
                        }"
                        >{{ cell.day }}</span
                    >
                    <span
                        v-for="item in cell.items.slice(0, 2)"
                        :key="`${item.kind}-${item.name}`"
                        class="hidden truncate rounded-[5px] px-1 py-px text-[10.5px] sm:block"
                        :style="chipStyle(item)"
                        >{{ item.received ? '✓ ' : '' }}{{ item.name }}
                        {{ formatPesoShort(item.amount) }}</span
                    >
                    <span
                        v-if="cell.items.length > 2"
                        class="hidden text-[10.5px] sm:block"
                        style="color: var(--hab-muted)"
                        >+{{ cell.items.length - 2 }} more</span
                    >
                    <span
                        v-if="cell.items.length > 0"
                        class="mt-auto flex gap-0.5 sm:hidden"
                    >
                        <span
                            v-for="item in cell.items.slice(0, 3)"
                            :key="`dot-${item.kind}-${item.name}`"
                            class="h-1.5 w-1.5 rounded-full"
                            :style="{
                                background:
                                    item.short > 0
                                        ? '#c0392b'
                                        : item.kind === 'income'
                                          ? 'var(--hab-accent)'
                                          : 'var(--hab-muted)',
                            }"
                        ></span>
                    </span>
                </button>
            </div>
        </div>

        <div v-if="selected" class="rounded-[22px] p-6" :style="cardStyle">
            <h2
                class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold"
                style="color: var(--hab-ink)"
            >
                {{ formatDay(selected) }}
            </h2>
            <p
                v-if="selectedItems.length === 0"
                class="text-[14px]"
                style="color: var(--hab-muted)"
            >
                Nothing due or paid this day.
            </p>
            <ul v-else class="flex flex-col">
                <li
                    v-for="(item, index) in selectedItems"
                    :key="`${item.kind}-${item.name}`"
                    class="flex items-center justify-between gap-3 py-2.5"
                    :style="
                        index > 0
                            ? 'border-top: 1px solid var(--hab-hairline)'
                            : ''
                    "
                >
                    <div class="min-w-0">
                        <p
                            class="truncate text-[14px] font-semibold"
                            style="color: var(--hab-ink)"
                        >
                            {{ item.name }}
                        </p>
                        <p
                            class="text-[12px]"
                            :style="{
                                color:
                                    item.short > 0
                                        ? '#c0392b'
                                        : 'var(--hab-muted)',
                            }"
                        >
                            {{ kindLabel[item.kind]
                            }}<template v-if="item.received">
                                ·
                                {{
                                    item.kind === 'bill' ? 'paid' : 'received'
                                }}</template
                            ><template v-if="item.autopay">
                                · automatic</template
                            ><template v-if="item.short > 0">
                                · {{ formatPeso(item.short) }} short</template
                            >
                        </p>
                    </div>
                    <p
                        class="shrink-0 text-[14px] font-semibold tabular-nums"
                        :style="{
                            color:
                                item.kind === 'income'
                                    ? 'var(--hab-accent)'
                                    : 'var(--hab-ink)',
                        }"
                    >
                        {{ item.kind === 'income' ? '+' : ''
                        }}{{ formatPeso(item.amount) }}
                    </p>
                </li>
            </ul>
        </div>

        <p class="mt-6 text-[12px]" style="color: var(--hab-muted)">
            Card dues use the real minimum for the statement you owe now. For
            later months only the installments are known, so the real amount
            will be higher if you carry a balance. Gaps carry forward from month
            to month, starting from the balance of the accounts you can spend.
        </p>
    </div>
</template>
