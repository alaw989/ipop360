<script setup lang="ts">
import { computed } from 'vue'

interface SignalSegment {
  label: string
  contribution: number
  weight: number
  normalized: number
  detail?: string
  color: string
  width: number
}

interface NoDataSegment {
  label: string
  color: string
  width: number
}

type BarSegment = SignalSegment | NoDataSegment

const props = defineProps<{
  breakdown: {
    signals: Array<{
      label: string
      weight: number
      normalized: number
      contribution: number
      detail?: string
    }>
    total: number
  }
}>()

const segmentColors: Record<string, string> = {
  'Quality': 'bg-red-500',
  'Yelp Rating': 'bg-amber-500',
  'Yelp Reviews': 'bg-blue-500',
  'Google Rating': 'bg-red-500',
  'Google Reviews': 'bg-red-400',
  'Proximity': 'bg-green-500',
  'Profile Completeness': 'bg-emerald-500',
  'Award': 'bg-purple-500',
  'Cuisine Match': 'bg-fuchsia-500',
  'Rating': 'bg-orange-500',
  'Reviews': 'bg-cyan-500',
  'Busyness': 'bg-teal-500',
}

const defaultColors = [
  'bg-rose-500', 'bg-sky-500', 'bg-lime-500', 'bg-violet-500',
  'bg-pink-500', 'bg-indigo-500', 'bg-teal-500', 'bg-orange-500',
]

function segmentColor(index: number, label: string): string {
  return segmentColors[label] ?? defaultColors[index % defaultColors.length]
}

const barSegments = computed<BarSegment[]>(() => {
  const total = props.breakdown.total
  if (total <= 0 || props.breakdown.signals.length === 0) {
    return [{ label: 'No data', color: 'bg-muted-foreground/20', width: 100 }]
  }
  const active = props.breakdown.signals.filter(s => s.contribution > 0)
  if (active.length === 0) {
    return [{ label: 'No data', color: 'bg-muted-foreground/20', width: 100 }]
  }
  return active.map((s, i) => ({
    label: s.label,
    contribution: s.contribution,
    weight: s.weight,
    normalized: s.normalized,
    detail: s.detail,
    color: segmentColor(i, s.label),
    width: Math.max((s.contribution / total) * 100, 5),
  }))
})

function isSignal(seg: BarSegment): seg is SignalSegment {
  return 'contribution' in seg
}

const tooltipSignals = computed(() =>
  barSegments.value.filter((s): s is SignalSegment => isSignal(s))
)

const scorePercent = computed(() => Math.round(props.breakdown.total * 100))
</script>

<template>
  <div class="relative">
    <div
      class="flex h-2 w-full overflow-hidden rounded-full bg-muted"
    >
      <div
        v-for="(seg, i) in barSegments"
        :key="i"
        :style="{ width: seg.width + '%' }"
        :class="[seg.color, i === 0 ? 'rounded-l-full' : '', i === barSegments.length - 1 ? 'rounded-r-full' : '']"
        class="h-full transition-all duration-300 first:rounded-l-full last:rounded-r-full"
      />
    </div>
    <span class="mt-0.5 block text-[10px] font-medium tabular-nums text-muted-foreground">
      Score {{ scorePercent }}%
    </span>

    <div class="mt-2 space-y-1">
      <div
        v-for="seg in tooltipSignals"
        :key="seg.label"
        class="flex flex-col gap-0.5"
      >
        <div class="flex items-center gap-2 text-xs">
          <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="seg.color" />
          <span class="font-medium text-foreground">{{ seg.label }}</span>
          <span class="tabular-nums text-muted-foreground">{{ Math.round(seg.contribution * 100) }}%</span>
        </div>
        <p v-if="seg.detail" class="ml-4 text-[11px] leading-tight text-muted-foreground">
          {{ seg.detail }}
        </p>
      </div>
    </div>
  </div>
</template>
