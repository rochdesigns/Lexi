<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';

/**
 * A numeric field that also accepts arithmetic — "1200+350", "4500/3", "80*12".
 *
 * The box is a text input rather than a number one, because `type="number"`
 * silently swallows the operator keys. The typed expression stays on screen
 * while it is being edited and is settled to its result on blur or Enter, so
 * what you see is either what you are still writing or what was actually saved.
 */
const props = withDefaults(
    defineProps<{
        modelValue: number | null;
        /** Rendered with the running total. Suffixed for '%', prefixed otherwise. */
        unit?: string;
        /** What an empty box means: 0 for amounts, null for "not set" fields. */
        emptyValue?: number | null;
        /**
         * Show the running total under the box. Adds a block wrapper, so only
         * turn it on where the surrounding layout stacks vertically — in a flex
         * row the hint would land beside the input instead of below it.
         */
        hint?: boolean;
    }>(),
    { unit: '₱', emptyValue: 0, hint: false },
);

const emit = defineEmits<{ 'update:modelValue': [number | null] }>();

defineOptions({ inheritAttrs: false });

function toDraft(value: number | null): string {
    return value === null || Number.isNaN(value) ? '' : String(value);
}

const draft = ref(toDraft(props.modelValue));
const focused = ref(false);

const evaluated = computed(() => evaluateArithmetic(draft.value));

/** Only worth showing a running total once the box holds more than a plain number. */
const showsTotal = computed(
    () => hasArithmetic(draft.value) && evaluated.value !== null,
);

const total = computed(() => {
    if (evaluated.value === null) {
        return '';
    }

    const formatted = evaluated.value.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return props.unit === '%' ? `${formatted}%` : `${props.unit}${formatted}`;
});

/**
 * Follows the value when something other than typing moves it — a slider, a
 * "fill these for me" button, a reset when a sheet reopens. While the box has
 * focus the person typing owns it, so a half-written expression is left alone.
 */
watch(
    () => props.modelValue,
    (value) => {
        if (focused.value && evaluated.value === value) {
            return;
        }

        if (!focused.value) {
            draft.value = toDraft(value);
        }
    },
);

function onInput(event: Event): void {
    draft.value = (event.target as HTMLInputElement).value;

    if (draft.value.trim() === '') {
        emit('update:modelValue', props.emptyValue);

        return;
    }

    if (evaluated.value !== null) {
        emit('update:modelValue', evaluated.value);
    }
}

/** Replaces the expression with what it came to, so the box never lies. */
function settle(): void {
    focused.value = false;

    if (draft.value.trim() === '') {
        draft.value = toDraft(props.emptyValue);
        emit('update:modelValue', props.emptyValue);

        return;
    }

    if (evaluated.value === null) {
        draft.value = toDraft(props.modelValue);

        return;
    }

    draft.value = String(evaluated.value);
    emit('update:modelValue', evaluated.value);
}
</script>

<template>
    <!--
        Two shapes on purpose: bare, so the input keeps whatever flex or grid
        slot it was dropped into, or wrapped, when there is room to show the
        running total underneath.
    -->
    <input
        v-if="!hint"
        v-bind="$attrs"
        :value="draft"
        type="text"
        inputmode="text"
        autocomplete="off"
        @input="onInput"
        @focus="focused = true"
        @blur="settle"
        @keydown.enter.prevent="settle"
    />
    <span v-else class="block">
        <input
            v-bind="$attrs"
            :value="draft"
            type="text"
            inputmode="text"
            autocomplete="off"
            @input="onInput"
            @focus="focused = true"
            @blur="settle"
            @keydown.enter.prevent="settle"
        />
        <span
            v-if="showsTotal"
            class="mt-1 block text-[11.5px] tabular-nums"
            style="font-family: var(--hab-mono); color: var(--hab-muted)"
        >
            = {{ total }}
        </span>
    </span>
</template>
