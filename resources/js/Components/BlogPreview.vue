<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowRight, PenLine } from '@lucide/vue'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    category: string | null
    featured_image: string | null
    published_at: string | null
    is_featured: boolean
    author?: { id: number; name: string } | null
}

// The latest blog posts, one card each: photo on top, words below. The home
// page's spotlight is the featured restaurant now, so posts carry no
// "Featured" badge, and a "Featured" category isn't repeated as a tag.
defineProps<{
    posts: BlogPost[]
}>()

const failedIds = ref<Set<number>>(new Set())

function markImageFailed(id: number) {
    failedIds.value = new Set(failedIds.value).add(id)
}

function showsCategory(category: string | null): boolean {
    return !!category && category.toLowerCase() !== 'featured'
}

function formatDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <section v-if="posts.length > 0" class="w-full bg-background py-12" aria-labelledby="blog-preview-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-end justify-between gap-4">
                <div>
                    <h2 id="blog-preview-heading" class="text-xl font-semibold text-foreground sm:text-2xl">From the blog</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Stories about the restaurants we rank</p>
                </div>
                <Link
                    href="/blog"
                    class="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
                >
                    All stories
                    <ArrowRight class="h-4 w-4" aria-hidden="true" />
                </Link>
            </div>

            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="post in posts"
                    :key="post.id"
                    class="group relative overflow-hidden rounded-xl border bg-card transition-shadow hover:shadow-md"
                    data-testid="blog-card"
                >
                    <div class="aspect-video overflow-hidden bg-muted">
                        <img
                            v-if="post.featured_image && !failedIds.has(post.id)"
                            :src="post.featured_image"
                            :alt="post.title"
                            width="640"
                            height="360"
                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                            loading="lazy"
                            decoding="async"
                            @error="() => markImageFailed(post.id)"
                        />
                        <div v-else class="flex h-full items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/10" data-testid="blog-placeholder">
                            <PenLine class="h-10 w-10 text-foreground opacity-20" aria-hidden="true" />
                            <span class="sr-only">No image</span>
                        </div>
                    </div>
                    <div class="space-y-2 p-5">
                        <p class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            <span
                                v-if="showsCategory(post.category)"
                                class="rounded-full bg-muted px-2 py-0.5 font-medium text-foreground"
                                data-testid="blog-category"
                            >{{ post.category }}</span>
                            <span v-if="post.published_at">{{ formatDate(post.published_at) }}</span>
                            <span v-if="post.author">by {{ post.author.name }}</span>
                        </p>
                        <h3 class="font-heading text-base font-semibold leading-snug text-foreground group-hover:text-primary">
                            <Link :href="`/blog/${post.slug}`" class="after:absolute after:inset-0">{{ post.title }}</Link>
                        </h3>
                        <p class="line-clamp-3 text-sm text-muted-foreground">{{ post.excerpt }}</p>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>
