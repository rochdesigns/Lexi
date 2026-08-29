<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

type Period = { key: string; label: string; isCurrent: boolean };
type Row = { label: string; amounts: number[] };
type LineItem = { id: number; name: string; icon: string; color: string; amounts: number[] };
type BillSubgroup = { groupName: string; items: LineItem[]; subtotal: number[] };
type ExpenseGroup = {
    key: string;
    label: string;
    items?: LineItem[];
    subgroups?: BillSubgroup[];
    subtotal: number[];
};

const props = defineProps<{
    startingBalance: number;
    periods: Period[];
    incomeRow: Row;
    everydayExpensesRow: Row;
    expenseGroups: ExpenseGroup[];
    netLeftover: number[];
    runningBalance: number[];
}>();

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

const colWidth = 'min-width: 116px';

const debtGroup = computed(() => props.expenseGroups.find((g) => g.key === 'debt'));
const setAsidesGroup = computed(() => props.expenseGroups.find((g) => g.key === 'set_asides'));
const billsGroup = computed(() => props.expenseGroups.find((g) => g.key === 'bills'));
</script>

<template>
    <Head title="Forecast" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5">
            <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                Forecast
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">
                Starting balance {{ formatMoney(startingBalance) }} — 12 periods ahead, projected from your recurring bills, set-asides, and debt payments.
            </p>
        </div>

        <div
            class="overflow-x-auto rounded-[22px]"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            class="sticky left-0 z-10 whitespace-nowrap px-4 py-3 text-left font-semibold"
                            style="background: var(--hab-card-bg); color: var(--hab-muted)"
                        ></th>
                        <th
                            v-for="period in periods"
                            :key="period.key"
                            class="whitespace-nowrap px-3 py-3 text-right font-semibold"
                            :style="[colWidth, { background: period.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                        >
                            {{ period.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Income -->
                    <tr>
                        <td
                            class="sticky left-0 z-10 whitespace-nowrap px-4 py-2.5 font-semibold"
                            style="background: var(--hab-chip-fill); color: var(--hab-accent)"
                        >
                            {{ incomeRow.label }}
                        </td>
                        <td
                            v-for="(amount, i) in incomeRow.amounts"
                            :key="i"
                            class="whitespace-nowrap px-3 py-2.5 text-right font-semibold"
                            :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-accent)' }]"
                        >
                            {{ formatMoney(amount) }}
                        </td>
                    </tr>

                    <!-- Debt -->
                    <template v-if="debtGroup && debtGroup.items && debtGroup.items.length > 0">
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 pt-4 pb-1 text-[11px] font-bold uppercase tracking-wide" style="background: var(--hab-card-bg); color: var(--hab-muted)">
                                Debt
                            </td>
                            <td v-for="(period, i) in periods" :key="i" :style="[colWidth, { background: period.isCurrent ? 'var(--hab-chip-fill)' : 'transparent' }]"></td>
                        </tr>
                        <tr v-for="item in debtGroup.items" :key="'debt-' + item.id">
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-2" style="background: var(--hab-card-bg); color: var(--hab-ink)">
                                <span class="material-symbols-rounded align-middle" :style="{ fontSize: '15px', color: item.color }">{{ item.icon }}</span>
                                {{ item.name }}
                            </td>
                            <td
                                v-for="(amount, i) in item.amounts"
                                :key="i"
                                class="whitespace-nowrap px-3 py-2 text-right"
                                :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                            >
                                {{ formatMoney(amount) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-2 font-semibold" style="background: var(--hab-card-bg); color: var(--hab-ink)">Debt subtotal</td>
                            <td
                                v-for="(amount, i) in debtGroup.subtotal"
                                :key="i"
                                class="whitespace-nowrap px-3 py-2 text-right font-semibold"
                                :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                            >
                                {{ formatMoney(amount) }}
                            </td>
                        </tr>
                    </template>

                    <!-- Set-asides -->
                    <template v-if="setAsidesGroup && setAsidesGroup.items && setAsidesGroup.items.length > 0">
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 pt-4 pb-1 text-[11px] font-bold uppercase tracking-wide" style="background: var(--hab-card-bg); color: var(--hab-muted)">
                                Set-asides
                            </td>
                            <td v-for="(period, i) in periods" :key="i" :style="[colWidth, { background: period.isCurrent ? 'var(--hab-chip-fill)' : 'transparent' }]"></td>
                        </tr>
                        <tr v-for="item in setAsidesGroup.items" :key="'sa-' + item.id">
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-2" style="background: var(--hab-card-bg); color: var(--hab-ink)">
                                <span class="material-symbols-rounded align-middle" :style="{ fontSize: '15px', color: item.color }">{{ item.icon }}</span>
                                {{ item.name }}
                            </td>
                            <td
                                v-for="(amount, i) in item.amounts"
                                :key="i"
                                class="whitespace-nowrap px-3 py-2 text-right"
                                :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                            >
                                {{ formatMoney(amount) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-2 font-semibold" style="background: var(--hab-card-bg); color: var(--hab-ink)">Set-asides subtotal</td>
                            <td
                                v-for="(amount, i) in setAsidesGroup.subtotal"
                                :key="i"
                                class="whitespace-nowrap px-3 py-2 text-right font-semibold"
                                :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                            >
                                {{ formatMoney(amount) }}
                            </td>
                        </tr>
                    </template>

                    <!-- Bills -->
                    <template v-if="billsGroup && billsGroup.subgroups && billsGroup.subgroups.length > 0">
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 pt-4 pb-1 text-[11px] font-bold uppercase tracking-wide" style="background: var(--hab-card-bg); color: var(--hab-muted)">
                                Bills
                            </td>
                            <td v-for="(period, i) in periods" :key="i" :style="[colWidth, { background: period.isCurrent ? 'var(--hab-chip-fill)' : 'transparent' }]"></td>
                        </tr>
                        <template v-for="subgroup in billsGroup.subgroups" :key="subgroup.groupName">
                            <tr>
                                <td class="sticky left-0 z-10 whitespace-nowrap px-4 pt-2 pb-1 text-[11px] font-medium" style="background: var(--hab-card-bg); color: var(--hab-muted)">
                                    {{ subgroup.groupName }}
                                </td>
                                <td v-for="(period, i) in periods" :key="i" :style="[colWidth, { background: period.isCurrent ? 'var(--hab-chip-fill)' : 'transparent' }]"></td>
                            </tr>
                            <tr v-for="item in subgroup.items" :key="'bill-' + item.id">
                                <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-2" style="background: var(--hab-card-bg); color: var(--hab-ink)">
                                    <span class="material-symbols-rounded align-middle" :style="{ fontSize: '15px', color: item.color }">{{ item.icon }}</span>
                                    {{ item.name }}
                                </td>
                                <td
                                    v-for="(amount, i) in item.amounts"
                                    :key="i"
                                    class="whitespace-nowrap px-3 py-2 text-right"
                                    :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                                >
                                    {{ formatMoney(amount) }}
                                </td>
                            </tr>
                        </template>
                        <tr>
                            <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-2 font-semibold" style="background: var(--hab-card-bg); color: var(--hab-ink)">Bills subtotal</td>
                            <td
                                v-for="(amount, i) in billsGroup.subtotal"
                                :key="i"
                                class="whitespace-nowrap px-3 py-2 text-right font-semibold"
                                :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                            >
                                {{ formatMoney(amount) }}
                            </td>
                        </tr>
                    </template>

                    <!-- Everyday expenses -->
                    <tr>
                        <td class="sticky left-0 z-10 whitespace-nowrap px-4 pt-4 py-2" style="background: var(--hab-card-bg); color: var(--hab-ink)">
                            {{ everydayExpensesRow.label }}
                        </td>
                        <td
                            v-for="(amount, i) in everydayExpensesRow.amounts"
                            :key="i"
                            class="whitespace-nowrap px-3 pt-4 py-2 text-right"
                            :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: 'var(--hab-ink)' }]"
                        >
                            {{ formatMoney(amount) }}
                        </td>
                    </tr>

                    <!-- Net leftover -->
                    <tr class="border-t" style="border-color: var(--hab-hairline)">
                        <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-3 font-bold" style="background: var(--hab-card-bg); color: var(--hab-ink)">
                            Net leftover
                        </td>
                        <td
                            v-for="(amount, i) in netLeftover"
                            :key="i"
                            class="whitespace-nowrap px-3 py-3 text-right font-bold"
                            :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: amount < 0 ? '#c0512c' : 'var(--hab-ink)' }]"
                        >
                            {{ formatMoney(amount) }}
                        </td>
                    </tr>

                    <!-- Running balance -->
                    <tr>
                        <td class="sticky left-0 z-10 whitespace-nowrap px-4 py-3 font-['Bricolage_Grotesque'] text-[15px] font-bold" style="background: var(--hab-card-bg); color: var(--hab-ink)">
                            Running balance
                        </td>
                        <td
                            v-for="(amount, i) in runningBalance"
                            :key="i"
                            class="whitespace-nowrap px-3 py-3 text-right font-['Bricolage_Grotesque'] text-[15px] font-bold"
                            :style="[colWidth, { background: periods[i]?.isCurrent ? 'var(--hab-chip-fill)' : 'transparent', color: amount < 0 ? '#c0512c' : 'var(--hab-ink)' }]"
                        >
                            {{ formatMoney(amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
