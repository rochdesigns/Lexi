<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    preview as previewRoute,
    store,
} from '@/actions/App/Http/Controllers/Money/StatementImportController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formError, formField, formLabel } from '@/utils/blueprint';
import { categoryLabel, formatDay, formatPeso } from '@/utils/money';

type PreviewRow = {
    line: number;
    date: string;
    description: string;
    amount: number;
    status: 'new' | 'matches' | 'imported';
    matched: {
        id: number;
        date: string;
        note: string | null;
        category: string;
    } | null;
};

type Preview = {
    rows: PreviewRow[];
    skipped: { line: number; text: string; reason: string }[];
    counts: { new: number; matches: number; imported: number };
};

const props = defineProps<{
    open: boolean;
    cards: { id: number; name: string }[];
}>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const debtId = ref<number | null>(null);
const csv = ref('');
const preview = ref<Preview | null>(null);

const http = useHttp<{ debt_id: number | null; csv: string }, Preview>({
    debt_id: null,
    csv: '',
});
const form = useForm({ debt_id: null as number | null, csv: '' });

watch(
    () => props.open,
    (open) => {
        if (open) {
            debtId.value = props.cards[0]?.id ?? null;
            csv.value = '';
            preview.value = null;
            http.clearErrors();
            form.clearErrors();
        }
    },
);

watch([debtId, csv], () => {
    preview.value = null;
});

async function readFile(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) {
        csv.value = await file.text();
    }
}

function runPreview(): void {
    http.debt_id = debtId.value;
    http.csv = csv.value;
    http.clearErrors();
    http.post(previewRoute().url, {
        onSuccess: (response) => {
            preview.value = response;
        },
    });
}

function runImport(): void {
    form.debt_id = debtId.value;
    form.csv = csv.value;
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

const importable = computed(
    () =>
        (preview.value?.counts.new ?? 0) + (preview.value?.counts.matches ?? 0),
);

const statusLabel = {
    new: 'New',
    matches: 'On your list',
    imported: 'Already imported',
} as const;

const statusStyle = {
    new: 'background: color-mix(in srgb, var(--bp-accent) 16%, transparent); color: var(--bp-accent)',
    matches: 'background: var(--bp-hair); color: var(--bp-ink)',
    imported: 'background: var(--bp-hair); color: var(--bp-muted)',
} as const;

const fieldClass = formField;
const labelClass = formLabel;
const errorClass = formError;
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent style="max-width: 640px">
            <DialogHeader>
                <DialogTitle>Import a card statement</DialogTitle>
            </DialogHeader>

            <div
                class="flex max-h-[70vh] flex-col gap-3.5 overflow-y-auto pt-1"
            >
                <p class="text-[13px]" style="color: var(--bp-muted)">
                    Paste the charges from your statement, or choose a CSV file.
                    Each line needs a date, a description and an amount.
                    Payments and refunds are left out. Charges you already
                    logged are matched, not added twice.
                </p>

                <div>
                    <label :class="labelClass">Card</label>
                    <select v-model="debtId" :class="fieldClass">
                        <option
                            v-for="card in cards"
                            :key="card.id"
                            :value="card.id"
                        >
                            {{ card.name }}
                        </option>
                    </select>
                    <p v-if="http.errors.debt_id" :class="errorClass">
                        {{ http.errors.debt_id }}
                    </p>
                </div>

                <div>
                    <label :class="labelClass">Statement</label>
                    <textarea
                        v-model="csv"
                        rows="6"
                        spellcheck="false"
                        placeholder="Date, Description, Amount&#10;2026-10-12, Jollibee, 250.50"
                        :class="fieldClass + ' font-mono text-[12.5px]'"
                    ></textarea>
                    <input
                        type="file"
                        accept=".csv,.txt,text/csv,text/plain"
                        class="mt-2 text-[12.5px]"
                        @change="readFile"
                    />
                    <p
                        v-if="http.errors.csv || form.errors.csv"
                        :class="errorClass"
                    >
                        {{ http.errors.csv || form.errors.csv }}
                    </p>
                </div>

                <div v-if="preview">
                    <p
                        class="mb-2 text-[13.5px] font-semibold"
                        style="color: var(--bp-ink)"
                    >
                        {{ preview.counts.new }} new,
                        {{ preview.counts.matches }} on your list,
                        {{ preview.counts.imported }} already imported<template
                            v-if="preview.skipped.length"
                            >, {{ preview.skipped.length }} skipped</template
                        >
                    </p>
                    <ul
                        class="rounded-[3px]"
                        style="border: 1px solid var(--bp-hair)"
                    >
                        <li
                            v-for="(row, index) in preview.rows"
                            :key="row.line"
                            class="flex items-center justify-between gap-3 px-3 py-2"
                            :style="
                                index > 0
                                    ? 'border-top: 1px solid var(--bp-hair)'
                                    : ''
                            "
                        >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[13.5px]"
                                    style="color: var(--bp-ink)"
                                >
                                    {{ row.description || 'No description' }}
                                </span>
                                <span
                                    class="block text-[11.5px]"
                                    style="color: var(--bp-muted)"
                                >
                                    {{ formatDay(row.date) }}
                                    <template v-if="row.matched">
                                        · matches
                                        {{
                                            row.matched.note ||
                                            categoryLabel(row.matched.category)
                                        }}
                                        on
                                        {{ formatDay(row.matched.date) }}
                                    </template>
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :style="statusStyle[row.status]"
                                >
                                    {{ statusLabel[row.status] }}
                                </span>
                                <span
                                    class="text-[13.5px] font-semibold tabular-nums"
                                    style="color: var(--bp-ink)"
                                >
                                    {{ formatPeso(row.amount) }}
                                </span>
                            </span>
                        </li>
                    </ul>
                    <ul
                        v-if="preview.skipped.length"
                        class="mt-2 text-[12px]"
                        style="color: var(--bp-muted)"
                    >
                        <li v-for="skip in preview.skipped" :key="skip.line">
                            Line {{ skip.line }} skipped: {{ skip.reason }}.
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-1">
                <Button
                    type="button"
                    variant="outline"
                    @click="emit('update:open', false)"
                    >Cancel</Button
                >
                <Button
                    v-if="!preview"
                    type="button"
                    :disabled="
                        !csv.trim() || debtId === null || http.processing
                    "
                    @click="runPreview"
                >
                    Preview
                </Button>
                <Button
                    v-else
                    type="button"
                    :disabled="importable === 0 || form.processing"
                    @click="runImport"
                >
                    Import {{ importable }}
                    {{ importable === 1 ? 'charge' : 'charges' }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
