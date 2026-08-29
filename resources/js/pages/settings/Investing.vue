<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, update } from '@/routes/investing';

const props = defineProps<{
    fiNumber: string | null;
    fiExpectedReturnRate: string | null;
    emergencyFundTargetMonths: number | null;
    investmentMonthlyContributionTarget: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Investing', href: edit() }],
    },
});

const form = useForm({
    fi_number: props.fiNumber,
    fi_expected_return_rate: props.fiExpectedReturnRate,
    emergency_fund_target_months: props.emergencyFundTargetMonths,
    investment_monthly_contribution_target: props.investmentMonthlyContributionTarget,
});

function submit() {
    form.patch(update().url);
}
</script>

<template>
    <Head title="Investing settings" />

    <h1 class="sr-only">Investing settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Investing"
            description="Set the targets used by your financial independence and investment tracking"
        />

        <form @submit.prevent="submit" class="space-y-6">
            <div class="space-y-2">
                <Label for="fi_number">FI number</Label>
                <p class="text-sm text-muted-foreground">
                    The portfolio value that marks financial independence. Leave blank to hide FI progress tracking.
                </p>
                <Input
                    id="fi_number"
                    type="number"
                    min="0"
                    step="0.01"
                    v-model="form.fi_number"
                    class="w-48"
                />
                <InputError :message="form.errors.fi_number" />
            </div>

            <div class="space-y-2">
                <Label for="fi_expected_return_rate">Expected annual return (%)</Label>
                <p class="text-sm text-muted-foreground">
                    Used to project growth toward your FI number. Defaults to 6% if left blank.
                </p>
                <Input
                    id="fi_expected_return_rate"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    v-model="form.fi_expected_return_rate"
                    class="w-32"
                />
                <InputError :message="form.errors.fi_expected_return_rate" />
            </div>

            <div class="space-y-2">
                <Label for="emergency_fund_target_months">Emergency fund target (months of expenses)</Label>
                <p class="text-sm text-muted-foreground">
                    Defaults to 6 months if left blank.
                </p>
                <Input
                    id="emergency_fund_target_months"
                    type="number"
                    min="1"
                    max="60"
                    v-model="form.emergency_fund_target_months"
                    class="w-32"
                />
                <InputError :message="form.errors.emergency_fund_target_months" />
            </div>

            <div class="space-y-2">
                <Label for="investment_monthly_contribution_target">Monthly contribution target</Label>
                <p class="text-sm text-muted-foreground">
                    How much you aim to invest each month. Leave blank to hide contribution progress.
                </p>
                <Input
                    id="investment_monthly_contribution_target"
                    type="number"
                    min="0"
                    step="0.01"
                    v-model="form.investment_monthly_contribution_target"
                    class="w-48"
                />
                <InputError :message="form.errors.investment_monthly_contribution_target" />
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing">Save</Button>
            </div>
        </form>
    </div>
</template>
