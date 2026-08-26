<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import BrandLogo from '@/Components/BrandLogo.vue'
import { Badge } from '@/components/ui/badge'

// Matches the homepage hero's food-photo background so auth feels like part
// of the site, not a default framework shell.
const bgImage = 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=1600&q=80'
const imgLoaded = ref(true)
</script>

<template>
    <div class="relative flex min-h-screen flex-col overflow-hidden bg-neutral-950">
        <!-- Background photo + gradient overlay (same treatment as HeroBanner) -->
        <div class="absolute inset-0">
            <img
                v-show="imgLoaded"
                :src="bgImage"
                class="h-full w-full object-cover"
                alt=""
                aria-hidden="true"
                @error="imgLoaded = false"
            />
            <div class="absolute inset-0 bg-gradient-to-b from-black/70 via-black/60 to-black/85" />
        </div>

        <!-- Content -->
        <div class="relative z-10 flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <h1 class="sr-only">iPop360</h1>

            <Link
                href="/"
                class="mb-8 inline-flex flex-col items-center gap-2"
                aria-label="iPop360 home"
            >
                <BrandLogo class="text-[4.5rem] text-white drop-shadow-lg" />
                <Badge variant="outline" class="text-xs text-white border-white/40" aria-hidden="true">
                    Beta
                </Badge>
            </Link>

            <main
                class="w-full rounded-2xl border border-white/20 bg-white/10 p-6 shadow-2xl backdrop-blur-xl sm:max-w-md sm:p-8"
            >
                <slot />
            </main>
        </div>
    </div>
</template>
