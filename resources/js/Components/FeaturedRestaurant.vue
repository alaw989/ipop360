<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import StarRating from '@/Components/StarRating.vue'
import PriceLevel from '@/Components/PriceLevel.vue'
import { commonsSrcset } from '@/lib/responsiveImage'

export interface Spotlight {
    id: number
    name: string
    slug: string
    city: string | null
    state: string | null
    price_range: string | null
    google_rating: number | string | null
    google_review_count: number
    cuisines: Array<{ id: number; name: string; slug: string }>
    image: string | null
    image_credit?: string | null
    quote: string | null
    story: { title: string; slug: string } | null
    picked: boolean
}

// The home page spotlight: one restaurant, photo on one side and the facts
// on the other (stacked on phones, never text over the photo). An admin's
// pick is "Featured restaurant"; with no pick it is simply the top-ranked
// restaurant near the visitor, and says so.
const props = defineProps<{
    spotlight: Spotlight
}>()

const photoBroken = ref(false)

const heading = computed(() => (props.spotlight.picked ? 'Featured restaurant' : 'Top-ranked near you'))
const place = computed(() => [props.spotlight.city, props.spotlight.state].filter(Boolean).join(', '))
const cuisineNames = computed(() => props.spotlight.cuisines.slice(0, 2).map((c) => c.name).join(', '))
const srcset = computed(() => commonsSrcset(props.spotlight.image))
const rating = computed(() => Number(props.spotlight.google_rating ?? 0))
const restaurantUrl = computed(() => `/restaurants/${props.spotlight.slug}`)
</script>

<template>
    <section class="w-full bg-background py-12" aria-labelledby="spotlight-heading" data-testid="spotlight">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 id="spotlight-heading" class="mb-5 text-xl font-semibold text-foreground sm:text-2xl">{{ heading }}</h2>

            <article class="group relative grid overflow-hidden rounded-2xl border bg-card md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                <div class="relative aspect-[4/3] bg-muted md:aspect-auto md:min-h-[400px]">
                    <img
                        v-if="spotlight.image && !photoBroken"
                        :src="spotlight.image"
                        :srcset="srcset ?? undefined"
                        sizes="(min-width: 768px) 55vw, 100vw"
                        :alt="`${spotlight.name}${place ? ', ' + place : ''}`"
                        width="1280"
                        height="960"
                        class="absolute inset-0 h-full w-full object-cover"
                        loading="lazy"
                        decoding="async"
                        data-testid="spotlight-photo"
                        @error="photoBroken = true"
                    />
                </div>

                <div class="flex flex-col justify-center gap-3 p-6 sm:p-8">
                    <h3 class="font-heading text-2xl font-bold leading-tight text-foreground sm:text-3xl">
                        <a :href="restaurantUrl" class="after:absolute after:inset-0 hover:text-primary">{{ spotlight.name }}</a>
                    </h3>

                    <StarRating
                        v-if="rating > 0"
                        :rating="rating"
                        source="Google"
                        :review-count="spotlight.google_review_count"
                    />

                    <p class="flex flex-wrap items-center gap-x-1.5 text-muted-foreground">
                        <template v-if="spotlight.price_range">
                            <PriceLevel :price="spotlight.price_range" />
                            <span v-if="cuisineNames || place" aria-hidden="true">·</span>
                        </template>
                        <span v-if="cuisineNames">{{ cuisineNames }}</span>
                        <span v-if="cuisineNames && place" aria-hidden="true">·</span>
                        <span v-if="place">{{ place }}</span>
                    </p>

                    <blockquote
                        v-if="spotlight.quote"
                        class="border-l-2 border-primary pl-4 text-[17px] italic leading-relaxed text-foreground/90"
                        data-testid="spotlight-quote"
                    >{{ spotlight.quote }}</blockquote>

                    <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-3">
                        <a
                            :href="restaurantUrl"
                            class="relative z-10 inline-flex min-h-11 items-center rounded-full bg-primary px-6 font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                        >See the restaurant</a>
                        <Link
                            v-if="spotlight.story"
                            :href="`/blog/${spotlight.story.slug}`"
                            class="relative z-10 font-semibold text-foreground underline-offset-4 hover:underline"
                            data-testid="spotlight-story"
                        >Read the story</Link>
                    </div>
                </div>
            </article>

            <p v-if="spotlight.image_credit && spotlight.image && !photoBroken" class="mt-2 text-xs text-muted-foreground" data-testid="spotlight-credit">
                {{ spotlight.image_credit }}
            </p>
        </div>
    </section>
</template>
