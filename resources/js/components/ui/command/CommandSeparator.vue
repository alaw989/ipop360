<script setup lang="ts">
import type { SeparatorProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Separator } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<SeparatorProps & { class?: HTMLAttributes["class"] }>()
</script>

<template>
  <Separator
    data-slot="command-separator"
    :as="props.as!"
    :as-child="props.asChild!"
    :orientation="props.orientation!"
    :decorative="props.decorative!"
    :class="cn('bg-border -mx-1 h-px', props.class)"
  >
    <slot />
  </Separator>
</template>
