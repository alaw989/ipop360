<script setup lang="ts">
import { ref, computed } from 'vue'
import { ChevronDown } from '@lucide/vue'
import { Skeleton } from '@/components/ui/skeleton'

const props = defineProps<{
    cuisines: Array<{
        id: number
        name: string
        slug: string
        icon: string | null
    }>
    loading?: boolean
    lat?: number | null
    lng?: number | null
}>()

function cuisineHref(slug: string): string {
    let url = `/search?cuisine=${slug}`
    if (props.lat != null && props.lng != null) {
        url += `&lat=${props.lat}&lng=${props.lng}`
    }
    return url
}

const showAll = ref(false)
const initialLimit = 12

const visibleCuisines = computed(() =>
    showAll.value ? props.cuisines : props.cuisines.slice(0, initialLimit)
)

const hasMore = computed(() => props.cuisines.length > initialLimit)
</script>

<template>
    <section class="w-full bg-background py-12">
        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="mb-1 text-xl font-semibold text-foreground">
                Popular cuisines
            </h2>
            <p class="mb-6 text-sm text-muted-foreground">
                Discover top cuisines and their best restaurants
            </p>

            <div v-if="loading" class="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
                <div v-for="i in 12" :key="'skeleton-' + i" class="flex items-center gap-2 px-2 py-1.5">
                    <Skeleton class="h-4 w-4 rounded-full" />
                    <Skeleton class="h-4 w-28" />
                </div>
            </div>
            <template v-else>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-for="cuisine in visibleCuisines"
                        :key="cuisine.id"
                        :href="cuisineHref(cuisine.slug)"
                        class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm text-foreground transition-all hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-sm"
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
            </template>
        </div>
    </section>
</template>
