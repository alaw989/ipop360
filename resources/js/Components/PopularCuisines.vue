<script setup lang="ts">
import { ref, computed } from 'vue'
import { ChevronDown } from '@lucide/vue'

const props = defineProps<{
    cuisines: Array<{
        id: number
        name: string
        slug: string
        icon: string | null
    }>
    city: string | null
}>()

const showAll = ref(false)
const initialLimit = 12

const visibleCuisines = computed(() =>
    showAll.value ? props.cuisines : props.cuisines.slice(0, initialLimit)
)

const hasMore = computed(() => props.cuisines.length > initialLimit)
</script>

<template>
    <section class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h2 class="mb-1 text-xl font-semibold text-foreground">
            Popular cuisines
            <span v-if="city"> in {{ city }}</span>
        </h2>
        <p class="mb-6 text-sm text-muted-foreground">
            Discover top cuisines and their best restaurants
        </p>

        <div class="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
            <a
                v-for="cuisine in visibleCuisines"
                :key="cuisine.id"
                :href="`/search?cuisine=${cuisine.slug}`"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <span class="text-base">{{ cuisine.icon }}</span>
                <span>{{ cuisine.name }}</span>
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
