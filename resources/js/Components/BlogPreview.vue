<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowRight, Calendar } from '@lucide/vue'
import { Card, CardContent } from '@/components/ui/card'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    featured_image: string | null
    published_at: string | null
}

const props = defineProps<{
    posts: BlogPost[]
}>()

const heroPost = computed(() => props.posts[0] ?? null)
const gridPosts = computed(() => props.posts.slice(1))

function formatDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <section v-if="posts.length > 0" class="mx-auto max-w-7xl px-4 pb-12 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-foreground">From the blog</h2>
                <p class="mt-1 text-sm text-muted-foreground">Guides, trends, and dining insights</p>
            </div>
            <Link
                href="/blog"
                class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
            >
                View all
                <ArrowRight class="h-4 w-4" />
            </Link>
        </div>

        <!-- Hero post -->
        <Card
            v-if="heroPost"
            class="group mb-6 overflow-hidden transition-shadow hover:shadow-md"
        >
            <Link :href="`/blog/${heroPost.slug}`" class="block md:grid md:grid-cols-5">
                <div class="aspect-video overflow-hidden bg-muted md:col-span-3 md:aspect-auto md:h-full">
                    <img
                        v-if="heroPost.featured_image"
                        :src="heroPost.featured_image"
                        :alt="heroPost.title"
                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                    />
                    <div v-else class="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary/10 to-primary/5">
                        <span class="text-sm font-medium text-primary/40">No image</span>
                    </div>
                </div>
                <CardContent class="flex flex-col justify-center p-6 md:col-span-2">
                    <p class="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Calendar class="h-3.5 w-3.5" />
                        {{ formatDate(heroPost.published_at) }}
                    </p>
                    <h3 class="text-xl font-bold text-foreground group-hover:text-primary">{{ heroPost.title }}</h3>
                    <p class="mt-3 line-clamp-4 text-sm text-muted-foreground">{{ heroPost.excerpt }}</p>
                    <span class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary group-hover:underline">
                        Read more
                        <ArrowRight class="h-3.5 w-3.5" />
                    </span>
                </CardContent>
            </Link>
        </Card>

        <!-- Grid of remaining posts -->
        <div v-if="gridPosts.length > 0" class="grid gap-5 sm:grid-cols-2">
            <Card
                v-for="post in gridPosts"
                :key="post.id"
                class="group overflow-hidden transition-shadow hover:shadow-md"
            >
                <Link :href="`/blog/${post.slug}`" class="block h-full">
                    <div v-if="post.featured_image" class="aspect-video overflow-hidden bg-muted">
                        <img
                            :src="post.featured_image"
                            :alt="post.title"
                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                            loading="lazy"
                        />
                    </div>
                    <CardContent class="p-5">
                        <p class="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Calendar class="h-3.5 w-3.5" />
                            {{ formatDate(post.published_at) }}
                        </p>
                        <h3 class="font-semibold text-foreground group-hover:text-primary">{{ post.title }}</h3>
                        <p class="mt-2 line-clamp-3 text-sm text-muted-foreground">{{ post.excerpt }}</p>
                    </CardContent>
                </Link>
            </Card>
        </div>
    </section>
</template>
