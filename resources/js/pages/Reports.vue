<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { index } from '@/actions/App/Http/Controllers/ReportController';

type PeriodType = 'month' | 'quarter' | 'year';

type Period = {
    type: PeriodType;
    label: string;
    prevDate: string;
    nextDate: string | null;
    isCurrent: boolean;
};

const props = defineProps<{
    period: Period;
}>();

const periodOptions: { value: PeriodType; label: string }[] = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
];

function switchPeriod(type: PeriodType) {
    router.get(index({ query: { period: type } }).url, {}, { preserveScroll: true, preserveState: true, replace: true });
}

function navigateDate(date: string) {
    router.get(index({ query: { period: props.period.type, date } }).url, {}, { preserveScroll: true, preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Reports" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Reports</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Where your money's been, and where it's headed</p>
            </div>

            <div style="margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                <div style="display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                    <button
                        v-for="opt in periodOptions"
                        :key="opt.value"
                        type="button"
                        @click="switchPeriod(opt.value)"
                        :style="{
                            padding: '0 14px',
                            height: '38px',
                            border: 'none',
                            fontSize: '13.5px',
                            fontWeight: 600,
                            cursor: 'pointer',
                            background: period.type === opt.value ? '#211d17' : 'transparent',
                            color: period.type === opt.value ? '#fdfbf5' : '#6b6355',
                        }"
                    >{{ opt.label }}</button>
                </div>

                <div style="display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                    <button type="button" @click="navigateDate(period.prevDate)"
                        style="width: 38px; height: 38px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                        <span class="material-symbols-rounded" style="font-size: 20px">chevron_left</span>
                    </button>
                    <span style="padding: 0 12px; font-size: 13.5px; font-weight: 600; color: #211d17; border-left: 1px solid #e0dacd; border-right: 1px solid #e0dacd; height: 38px; display: flex; align-items: center">
                        {{ period.label }}
                    </span>
                    <button type="button"
                        @click="period.nextDate && navigateDate(period.nextDate)"
                        :disabled="!period.nextDate"
                        :style="{
                            width: '38px', height: '38px', border: 'none', background: 'transparent',
                            display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#6b6355',
                            opacity: period.nextDate ? 1 : 0.35, cursor: period.nextDate ? 'pointer' : 'default',
                        }">
                        <span class="material-symbols-rounded" style="font-size: 20px">chevron_right</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
