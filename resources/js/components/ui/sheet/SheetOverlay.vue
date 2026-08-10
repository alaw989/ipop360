<script setup lang="ts">
import type { DialogOverlayProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { DialogOverlay } from 'reka-ui'
import { cn } from '@/lib/utils'

const props = defineProps<DialogOverlayProps & { class?: HTMLAttributes['class'] }>()
</script>

<template>
  <DialogOverlay
    data-slot="sheet-overlay"
    :class="cn('bg-black/10 fixed inset-0 z-50 duration-100 data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0', props.class)"
    :as="props.as!"
    :as-child="props.asChild!"
    :force-mount="props.forceMount!"
  >
    <slot />
  </DialogOverlay>
</template>
