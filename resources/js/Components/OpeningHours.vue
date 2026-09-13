<script setup lang="ts">
import type { OpeningHours } from '@/types/restaurant';
import { onMounted, ref } from 'vue';

// The week's hours, with today's row marked. The server sends the days in
// order with their hours written out. "Today" is the visitor's day, set after
// the page loads (the server's day may differ). There's no "Open now": the
// hours carry no time zone, so the claim could be wrong.
defineProps<{
    hours: OpeningHours;
}>();

const today = ref<string | null>(null);
onMounted(() => {
    today.value = new Date().toLocaleDateString('en-US', { weekday: 'long' });
});
</script>

<template>
    <div v-if="hours">
        <table v-if="hours.structured" class="w-full max-w-md text-sm">
            <tbody>
                <tr
                    v-for="entry in hours.week"
                    :key="entry.day"
                    class="border-b border-border last:border-0"
                    :class="{ 'font-semibold': entry.day === today }"
                    :data-today="entry.day === today ? 'true' : undefined"
                >
                    <th scope="row" class="py-2 pr-6 text-left align-top text-foreground [font-weight:inherit]">
                        {{ entry.day }}<span v-if="entry.day === today" class="ml-2 text-xs font-medium text-primary">Today</span>
                    </th>
                    <td class="py-2 text-muted-foreground" :class="{ 'text-foreground': entry.day === today }">
                        {{ entry.hours }}
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-else class="whitespace-pre-line text-sm text-muted-foreground">
            {{ hours.raw_text }}
        </p>
    </div>
</template>
