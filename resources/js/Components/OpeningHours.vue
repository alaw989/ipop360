<script setup lang="ts">
import { Clock } from '@lucide/vue';
import type { OpeningHours } from '@/types/restaurant';
import { computed } from 'vue';

const props = defineProps<{
    hours: OpeningHours;
}>();

const dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const sortedHours = computed(() => {
    if (!props.hours || !props.hours.structured) {
        return null;
    }
    const entries = [...props.hours.hours];
    entries.sort((a, b) => dayOrder.indexOf(a.day) - dayOrder.indexOf(b.day));
    return entries;
});

const rawText = computed(() => {
    if (!props.hours) {
        return null;
    }
    if (!props.hours.structured) {
        return props.hours.raw_text;
    }
    return null;
});
</script>

<template>
    <div v-if="hours" class="space-y-3">
        <div class="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
            <Clock class="h-4 w-4" />
            <span>Hours</span>
        </div>

        <!-- Structured format: day-by-day table -->
        <table v-if="sortedHours" class="w-full text-sm">
            <tbody>
                <tr
                    v-for="entry in sortedHours"
                    :key="entry.day"
                    class="border-b border-neutral-100 last:border-0 dark:border-neutral-800"
                >
                    <td class="py-1.5 pr-4 font-medium text-neutral-700 dark:text-neutral-300">
                        {{ entry.day }}
                    </td>
                    <td class="py-1.5 text-neutral-600 dark:text-neutral-400">
                        {{ entry.open }} – {{ entry.close }}
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Raw text format -->
        <p v-else-if="rawText" class="text-sm text-neutral-600 dark:text-neutral-400 whitespace-pre-line">
            {{ rawText }}
        </p>
    </div>
</template>
