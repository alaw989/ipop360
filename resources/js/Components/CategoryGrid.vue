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
        <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
            <div
                v-for="i in 8"
                :key="'skeleton-' + i"
                class="flex flex-col items-center gap-3 rounded-2xl border border-border bg-card p-6"
            >
                <Skeleton class="h-10 w-10 rounded-full" />
                <Skeleton class="h-4 w-20" />
            </div>
        </div>
        <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
            <a
                v-for="category in categories"
                :key="category.id"
                :href="categoryHref(category.slug)"
                class="flex flex-col items-center gap-3 rounded-2xl border border-border bg-card p-6 text-center transition-all hover:shadow-md hover:-translate-y-0.5"
            >
                <span class="text-4xl">{{ category.icon }}</span>
                <p class="text-sm font-semibold text-foreground">{{ category.name }}</p>
            </a>
        </div>
    </section>
</template>
