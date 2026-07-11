<script setup lang="ts">
defineProps<{
    categories: Array<{
        id: number
        name: string
        slug: string
        icon: string | null
    }>
}>()
</script>

<template>
    <section class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h2 class="mb-6 text-xl font-semibold text-foreground">Categories</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
            <a
                v-for="category in categories"
                :key="category.id"
                :href="`/search?category=${category.slug}`"
                class="flex flex-col items-center gap-3 rounded-2xl border border-border bg-card p-6 text-center transition-all hover:shadow-md hover:-translate-y-0.5"
            >
                <span class="text-4xl">{{ category.icon }}</span>
                <p class="text-sm font-semibold text-foreground">{{ category.name }}</p>
            </a>
        </div>
    </section>
</template>
