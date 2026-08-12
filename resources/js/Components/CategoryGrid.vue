<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton'

const props = defineProps<{
    categories: Array<{
        id: number
        name: string
        slug: string
        icon: string | null
    }>
    loading?: boolean
    lat?: number | null
    lng?: number | null
}>()

function categoryHref(slug: string): string {
    let url = `/search?category=${slug}`
    if (props.lat != null && props.lng != null) {
        url += `&lat=${props.lat}&lng=${props.lng}`
    }
    return url
}
</script>

<template>
    <section class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h2 class="mb-6 text-xl font-semibold text-foreground">Categories</h2>
        <div v-if="loading" class="flex flex-wrap gap-2">
            <div
                v-for="i in 8"
                :key="'skeleton-' + i"
                data-testid="category-skeleton"
                class="flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2"
            >
                <Skeleton class="h-4 w-4 rounded-full" />
                <Skeleton class="h-4 w-20" />
            </div>
        </div>
        <div v-else class="flex flex-wrap gap-2">
            <a
                v-for="category in categories"
                :key="category.id"
                :href="categoryHref(category.slug)"
                class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm text-foreground transition-all hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-sm"
            >
                <span v-if="category.icon" class="text-base">{{ category.icon }}</span>
                <span>{{ category.name }}</span>
            </a>
        </div>
    </section>
</template>
