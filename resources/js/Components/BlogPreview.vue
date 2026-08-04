<script setup lang="ts">
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

defineProps<{
    posts: BlogPost[]
}>()

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

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="post in posts"
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
