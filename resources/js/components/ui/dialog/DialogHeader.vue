<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="dialog-header"
    :class="cn('-mx-6 -mt-6 mb-1 flex flex-col gap-1.5 border-b border-[var(--bp-ink)] px-6 pt-5 pr-12 pb-4 text-left', props.class)"
  >
    <slot />
  </div>
</template>
