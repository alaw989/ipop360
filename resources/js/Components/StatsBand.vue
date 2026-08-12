<script setup lang="ts">
import { computed } from 'vue'
import { ChefHat, MapPin, UtensilsCrossed } from '@lucide/vue'

interface Stats {
    restaurants: number
    cuisines: number
    cities: number
}

const props = defineProps<{
    stats: Stats
}>()

const items = computed(() => [
    { icon: UtensilsCrossed, value: props.stats.restaurants, label: 'Restaurants' },
    { icon: ChefHat, value: props.stats.cuisines, label: 'Cuisines' },
    { icon: MapPin, value: props.stats.cities, label: 'Cities' },
])

function formatNumber(value: number): string {
    return value.toLocaleString('en-US')
}
</script>

<template>
    <section class="w-full bg-background py-12">
        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-8 sm:grid-cols-3">
                <div
                    v-for="item in items"
                    :key="item.label"
                    class="flex flex-col items-center gap-2 text-center"
                >
                    <span class="text-3xl font-bold text-foreground">{{ formatNumber(item.value) }}</span>
                    <span class="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                        <component :is="item.icon" class="h-4 w-4" />
                        {{ item.label }}
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
