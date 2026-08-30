<script setup lang="ts">
defineProps<{
    cities: Array<{
        name: string
        city: string
        state: string
    }>
    selectedCity?: string | null
}>()
</script>

<template>
    <section class="w-full bg-muted/50 py-12">
        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="mb-1 text-xl font-semibold text-foreground">
                Explore restaurants in popular cities
            </h2>
            <p class="mb-6 text-sm text-muted-foreground">
                See what's popular for diners in each city
            </p>
            <div class="flex flex-wrap gap-2">
                <a
                    v-for="place in cities"
                    :key="place.city + place.state"
                    :href="`/restaurants?city=${encodeURIComponent(place.city)}&state=${encodeURIComponent(place.state)}`"
                    class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm text-foreground transition-all hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-sm"
                    :class="selectedCity === place.city ? 'border-primary bg-primary/5' : ''"
                >
                    <span>{{ place.name }}</span>
                </a>
            </div>
        </div>
    </section>
</template>
