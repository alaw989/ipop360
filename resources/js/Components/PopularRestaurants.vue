<script setup lang="ts">
import { ref, computed } from 'vue'
import { ChevronDown } from '@lucide/vue'

const props = defineProps<{
    restaurants: Array<{
        name: string
        slug: string
        city: string | null
        state: string | null
    }>
    city: string | null
}>()

const showAll = ref(false)
const initialLimit = 12

const visibleRestaurants = computed(() =>
    showAll.value ? props.restaurants : props.restaurants.slice(0, initialLimit)
)

const hasMore = computed(() => props.restaurants.length > initialLimit)
</script>

<template>
    <section class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h2 class="mb-6 text-xl font-semibold text-foreground">
            Recently reviewed
            <span v-if="city"> in {{ city }}</span>
        </h2>

        <div class="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
            <a
                v-for="restaurant in visibleRestaurants"
                :key="restaurant.slug"
                :href="`/restaurants/${restaurant.slug}`"
                class="rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                {{ restaurant.name }}
            </a>
        </div>

        <button
            v-if="hasMore"
            class="mt-4 flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            @click="showAll = !showAll"
        >
            <ChevronDown
                class="h-4 w-4 transition-transform duration-200"
                :class="showAll ? 'rotate-180' : ''"
            />
            <span>{{ showAll ? 'Show less' : 'Show more' }}</span>
        </button>
    </section>
</template>
