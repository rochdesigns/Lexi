<script setup lang="ts">
import { computed } from 'vue';
import { interestTypes } from '@/utils/money';
import type { InterestValue } from '@/utils/money';

/**
 * Interest options as a short list with a plain-language line under each, so
 * nobody has to know what "add-on" means to choose one.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: InterestValue[];
        /** Adds a first option meaning "use whatever the debt uses", stored as ''. */
        inheritLabel?: string;
        inheritDescription?: string;
    }>(),
    { inheritLabel: undefined, inheritDescription: undefined },
);

defineEmits<{ 'update:modelValue': [string] }>();

const choices = computed(() => [
    ...(props.inheritLabel
        ? [
              {
                  value: '',
                  label: props.inheritLabel,
                  description: props.inheritDescription ?? '',
              },
          ]
        : []),
    ...interestTypes
        .filter((type) => props.options.includes(type.value))
        .map((type) => ({
            value: type.value as string,
            label: type.label,
            description: type.description,
        })),
]);
</script>

<template>
    <div class="flex flex-col gap-1.5" role="radiogroup">
        <label
            v-for="choice in choices"
            :key="choice.value"
            class="flex cursor-pointer items-start gap-2.5 rounded-[11px] border px-3 py-2"
            :style="
                modelValue === choice.value
                    ? 'border-color: var(--hab-accent); background: var(--hab-surface)'
                    : 'border-color: var(--hab-card-border)'
            "
        >
            <input
                type="radio"
                class="mt-1"
                :checked="modelValue === choice.value"
                :value="choice.value"
                @change="$emit('update:modelValue', choice.value)"
            />
            <span>
                <span
                    class="block text-[13.5px] font-semibold"
                    style="color: var(--hab-ink)"
                    >{{ choice.label }}</span
                >
                <span
                    v-if="choice.description"
                    class="block text-[12px]"
                    style="color: var(--hab-muted)"
                    >{{ choice.description }}</span
                >
            </span>
        </label>
    </div>
</template>
