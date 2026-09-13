<script setup lang="ts">
import { computed } from 'vue'
import { signalName } from '@/lib/signalLabels'

// Why a restaurant ranks where it does, in plain words: each signal that
// counted, biggest first, with a bar for its share and the scorer's own
// sentence about it. No overall percentage: the score is internal.
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

const signals = computed(() => {
    const active = props.breakdown.signals.filter((s) => s.contribution > 0)
    const top = Math.max(...active.map((s) => s.contribution), 0)
    return active
        .sort((a, b) => b.contribution - a.contribution)
        .map((s) => ({
            key: s.label,
            name: signalName(s.label),
            detail: s.detail ?? '',
            share: top > 0 ? Math.max((s.contribution / top) * 100, 6) : 0,
        }))
})
</script>

<template>
    <div>
        <ul v-if="signals.length" class="space-y-4" data-testid="score-signals">
            <li v-for="s in signals" :key="s.key">
                <p class="text-sm font-semibold text-foreground">{{ s.name }}</p>
                <div class="mt-1.5 h-1.5 w-full max-w-sm overflow-hidden rounded-full bg-muted" aria-hidden="true">
                    <div class="h-full rounded-full bg-primary/80" :style="{ width: `${s.share}%` }" />
                </div>
                <p v-if="s.detail" class="mt-1 text-sm text-muted-foreground">{{ s.detail }}</p>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Not enough is known about this restaurant yet to explain its rank.</p>
    </div>
</template>
